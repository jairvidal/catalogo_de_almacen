<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Baja a public/img las fotos que estan en el storage de Supabase.
 *
 * Primer paso de la carga de repuestos nuevos. Deja los archivos con el mismo
 * nombre que ya usa el catalogo ({codigo}.jpg) para que despues
 * `repuestos:importar --crear` los asocie solo y `repuestos:clasificar` pueda
 * leer el color del marco, que necesita el archivo en disco.
 */
class SincronizarImagenesSupabase extends Command
{
    protected $signature = 'repuestos:sincronizar-supabase
                            {--prefijo= : Carpeta dentro del bucket (por defecto la raiz)}
                            {--csv= : Ruta donde escribir el manifiesto de las fotos nuevas}
                            {--forzar : Vuelve a bajar las imagenes que ya existen en public/img}
                            {--simular : Reporta lo que haria sin escribir nada}';

    protected $description = 'Descarga a public/img las imagenes nuevas del storage de Supabase';

    /** Las mismas que reconoce ImportarRepuestos::buscarFoto(). */
    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp'];

    /** Tamano de pagina del endpoint de listado de Supabase. */
    private const LOTE = 100;

    public function handle(): int
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        $bucket = trim((string) config('services.supabase.bucket'));
        $clave = (string) config('services.supabase.key');

        if ($url === '' || $bucket === '') {
            $this->error('Faltan SUPABASE_URL o SUPABASE_BUCKET en el .env.');

            return self::FAILURE;
        }

        $prefijo = trim((string) $this->option('prefijo'), '/');
        $simular = (bool) $this->option('simular');

        $this->info("Bucket: {$bucket}".($prefijo !== '' ? "/{$prefijo}" : ''));

        try {
            $archivos = $this->listar($url, $bucket, $clave, $prefijo);
        } catch (ConnectionException $e) {
            $this->error('No se pudo conectar con Supabase: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($archivos === []) {
            $this->warn('El bucket no devolvio imagenes con extension conocida.');
            $this->line('Extensiones admitidas: '.implode(', ', self::EXTENSIONES));

            return self::SUCCESS;
        }

        $this->info(count($archivos).' imagenes encontradas.');
        $this->newLine();

        $descargados = 0;
        $omitidos = 0;
        $fallidos = 0;
        $nuevos = [];
        $destinos = [];

        $barra = $this->output->createProgressBar(count($archivos));
        $barra->start();

        foreach ($archivos as $ruta) {
            $barra->advance();
            $nombre = basename($ruta);

            // Dos carpetas distintas pueden traer el mismo nombre de archivo y
            // public/img es plano: se avisa en vez de pisar en silencio.
            if (isset($destinos[$nombre])) {
                $this->newLine();
                $this->warn("Nombre repetido, se omite: {$ruta} (ya vino de {$destinos[$nombre]})");
                $omitidos++;

                continue;
            }

            $destinos[$nombre] = $ruta;
            $destino = public_path('img/'.$nombre);

            if (is_file($destino) && ! $this->option('forzar')) {
                $omitidos++;

                continue;
            }

            if ($simular) {
                $descargados++;
                $nuevos[] = $nombre;

                continue;
            }

            $contenido = $this->descargar($url, $bucket, $clave, $ruta);

            if ($contenido === null) {
                $fallidos++;

                continue;
            }

            file_put_contents($destino, $contenido);
            $descargados++;
            $nuevos[] = $nombre;
        }

        $barra->finish();
        $this->newLine(2);

        if ($this->option('csv') && $nuevos !== []) {
            $this->escribirManifiesto($nuevos, $simular);
        }

        $this->info(($simular ? 'Se bajarian:  ' : 'Descargadas:  ').$descargados);
        $this->info('Ya existian:  '.$omitidos);

        if ($fallidos > 0) {
            $this->error('Fallidas:     '.$fallidos);
        }

        if ($simular) {
            $this->newLine();
            $this->comment('Simulacion: no se escribio ningun archivo.');
        }

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Lista recursivamente las rutas de imagen bajo un prefijo.
     *
     * El endpoint pagina de a LOTE y devuelve las carpetas como filas con
     * `id` nulo, que es lo que distingue una carpeta de un archivo.
     *
     * @return list<string>
     */
    private function listar(string $url, string $bucket, string $clave, string $prefijo): array
    {
        $rutas = [];
        $desplazamiento = 0;

        do {
            $respuesta = Http::withHeaders($this->encabezados($clave))
                ->timeout(30)
                ->retry(2, 500)
                ->post("{$url}/storage/v1/object/list/{$bucket}", [
                    'prefix' => $prefijo === '' ? '' : $prefijo.'/',
                    'limit' => self::LOTE,
                    'offset' => $desplazamiento,
                    'sortBy' => ['column' => 'name', 'order' => 'asc'],
                ]);

            if ($respuesta->failed()) {
                $this->newLine();
                $this->error("Supabase respondio {$respuesta->status()} al listar: ".$respuesta->body());

                return $rutas;
            }

            $filas = $respuesta->json() ?? [];

            foreach ($filas as $fila) {
                $nombre = $fila['name'] ?? null;

                if (! $nombre) {
                    continue;
                }

                $ruta = $prefijo === '' ? $nombre : $prefijo.'/'.$nombre;

                if (($fila['id'] ?? null) === null) {
                    $rutas = array_merge($rutas, $this->listar($url, $bucket, $clave, $ruta));

                    continue;
                }

                $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

                if (in_array($extension, self::EXTENSIONES, true)) {
                    $rutas[] = $ruta;
                }
            }

            $desplazamiento += self::LOTE;
        } while (count($filas) === self::LOTE);

        return $rutas;
    }

    private function descargar(string $url, string $bucket, string $clave, string $ruta): ?string
    {
        // En un bucket publico el objeto se sirve sin credenciales; en uno
        // privado hace falta el token. La clave se manda igual: sobra en el
        // primer caso y es obligatoria en el segundo.
        $publico = (bool) config('services.supabase.publico');
        $segmento = $publico ? 'object/public' : 'object';
        $codificada = implode('/', array_map('rawurlencode', explode('/', $ruta)));

        try {
            $respuesta = Http::withHeaders($this->encabezados($clave))
                ->timeout(60)
                ->retry(2, 500)
                ->get("{$url}/storage/v1/{$segmento}/{$bucket}/{$codificada}");
        } catch (ConnectionException $e) {
            $this->newLine();
            $this->warn("Fallo la descarga de {$ruta}: ".$e->getMessage());

            return null;
        }

        if ($respuesta->failed()) {
            $this->newLine();
            $this->warn("Fallo la descarga de {$ruta}: HTTP {$respuesta->status()}");

            return null;
        }

        return $respuesta->body();
    }

    /**
     * @return array<string, string>
     */
    private function encabezados(string $clave): array
    {
        if ($clave === '') {
            return [];
        }

        return [
            'apikey' => $clave,
            'Authorization' => 'Bearer '.$clave,
        ];
    }

    /**
     * Deja un CSV listo para `repuestos:importar --crear`.
     *
     * El nombre queda igual al codigo a proposito: es un marcador para que el
     * administrador lo reemplace antes de importar. La foto no la escribe,
     * porque ImportarRepuestos la encuentra sola en public/img.
     *
     * @param  list<string>  $nuevos
     */
    private function escribirManifiesto(array $nuevos, bool $simular): void
    {
        $ruta = (string) $this->option('csv');

        if ($simular) {
            $this->comment("Simulacion: se escribiria el manifiesto en {$ruta}");

            return;
        }

        $manejador = fopen($ruta, 'w');

        if ($manejador === false) {
            $this->error("No se pudo escribir el manifiesto en {$ruta}");

            return;
        }

        fputcsv($manejador, ['codigo', 'nombre', 'cantidad_disponible'], ';');

        foreach ($nuevos as $nombre) {
            // El sufijo _v2 marca una foto alterna del mismo repuesto, no un
            // codigo distinto (ver ImportarRepuestos::buscarFoto).
            $codigo = preg_replace('/_v2$/', '', pathinfo($nombre, PATHINFO_FILENAME));
            fputcsv($manejador, [$codigo, $codigo, 0], ';');
        }

        fclose($manejador);
        $this->info("Manifiesto escrito en {$ruta}");
    }
}
