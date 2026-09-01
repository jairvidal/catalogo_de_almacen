<?php

namespace App\Console\Commands;

use App\Models\Repuesto;
use Illuminate\Console\Command;

/**
 * Carga masiva de repuestos desde un CSV.
 *
 * Pensado para reemplazar los nombres genericos del seeder por los nombres
 * reales del almacen sin tener que editarlos uno por uno en el panel.
 */
class ImportarRepuestos extends Command
{
    protected $signature = 'repuestos:importar
                            {archivo : Ruta del archivo CSV}
                            {--separador=; : Separador de columnas (; o ,)}
                            {--crear : Crea los codigos que aun no existan en el catalogo}';

    protected $description = 'Importa o actualiza repuestos desde un archivo CSV (codigo,nombre,descripcion,categoria,ubicacion,unidad_medida,existencia,stock_minimo)';

    /**
     * Columnas admitidas. Solo `codigo` es obligatoria; el resto se actualiza
     * unicamente si viene en el encabezado del archivo.
     *
     * `existencia` es el saldo operativo del almacen. NUNCA se acepta `stock`:
     * esa columna la escribe solo la sincronizacion con el ERP.
     *
     * @var list<string>
     */
    private const COLUMNAS = [
        'codigo', 'nombre', 'descripcion', 'categoria',
        'ubicacion', 'unidad_medida', 'existencia', 'stock_minimo',
    ];

    /**
     * Encabezados viejos que se siguen aceptando como alias del nombre actual.
     *
     * Los archivos que el almacen ya tiene armados traen la cabecera
     * `cantidad_disponible`, que es como se llamaba la columna antes de
     * realinear la tabla con el ERP. Rechazarlos obligaria a reeditar a mano
     * cada CSV existente, asi que se traducen al leer el encabezado.
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'cantidad_disponible' => 'existencia',
    ];

    /**
     * Columnas decimal(12,3) en la base: el almacen mide en KG y hay items con
     * fraccion, asi que convertirlas a entero las truncaria en silencio.
     *
     * @var list<string>
     */
    private const NUMERICAS = ['existencia', 'stock_minimo'];

    public function handle(): int
    {
        $archivo = $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No se encontro el archivo: {$archivo}");

            return self::FAILURE;
        }

        $manejador = fopen($archivo, 'r');

        if ($manejador === false) {
            $this->error('No se pudo abrir el archivo.');

            return self::FAILURE;
        }

        $separador = $this->option('separador');
        $encabezado = fgetcsv($manejador, 0, $separador);

        if ($encabezado === false) {
            fclose($manejador);
            $this->error('El archivo esta vacio.');

            return self::FAILURE;
        }

        // Se limpia el BOM que Excel deja al guardar como CSV UTF-8.
        $encabezado = array_map(
            fn ($columna) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $columna))),
            $encabezado
        );

        $encabezado = $this->traducirAlias($encabezado);

        if (! in_array('codigo', $encabezado, true)) {
            fclose($manejador);
            $this->error('El archivo debe tener una columna "codigo".');
            $this->line('Columnas admitidas: '.implode(', ', self::COLUMNAS));

            return self::FAILURE;
        }

        $actualizados = 0;
        $creados = 0;
        $omitidos = 0;
        $linea = 1;

        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            $linea++;

            if ($fila === [null] || $fila === []) {
                continue;
            }

            $datos = [];

            foreach ($encabezado as $indice => $columna) {
                if (in_array($columna, self::COLUMNAS, true) && isset($fila[$indice])) {
                    $valor = trim((string) $fila[$indice]);

                    if ($valor !== '') {
                        $datos[$columna] = $valor;
                    }
                }
            }

            $codigo = $datos['codigo'] ?? null;
            unset($datos['codigo']);

            if (! $codigo || $datos === []) {
                $omitidos++;

                continue;
            }

            foreach (self::NUMERICAS as $numerica) {
                if (! isset($datos[$numerica])) {
                    continue;
                }

                $cantidad = $this->aDecimal((string) $datos[$numerica]);

                // Un (float) a secas convierte "abc" en 0.0 y le borraria el
                // saldo a un item real sin decir nada. Se descarta el campo y
                // se avisa; el resto de la fila si se importa.
                if ($cantidad === null) {
                    $this->warn("Linea {$linea}: se ignora {$numerica} porque \"{$datos[$numerica]}\" no es un numero.");
                    unset($datos[$numerica]);

                    continue;
                }

                $datos[$numerica] = $cantidad;
            }

            $repuesto = Repuesto::where('codigo', $codigo)->first();

            if ($repuesto) {
                $repuesto->update($datos);
                $actualizados++;

                continue;
            }

            if (! $this->option('crear')) {
                $this->warn("Linea {$linea}: el codigo {$codigo} no existe en el catalogo (use --crear para agregarlo).");
                $omitidos++;

                continue;
            }

            // Si existe una foto con ese codigo en public/img, se asocia sola.
            Repuesto::create($datos + [
                'codigo' => $codigo,
                'nombre' => $datos['nombre'] ?? $codigo,
                'unidad_medida' => $datos['unidad_medida'] ?? 'UND',
                'foto' => $this->buscarFoto($codigo),
                'activo' => true,
            ]);
            $creados++;
        }

        fclose($manejador);

        $this->newLine();
        $this->info("Actualizados: {$actualizados}");
        $this->info("Creados:      {$creados}");
        $this->info("Omitidos:     {$omitidos}");

        return self::SUCCESS;
    }

    /**
     * Cambia los encabezados viejos por el nombre actual de la columna.
     *
     * Si el archivo ya trae el nombre nuevo, el alias no se aplica: manda el
     * actual y el viejo se ignora como cualquier columna desconocida, para no
     * dejar que dos columnas escriban el mismo campo.
     *
     * @param  list<string>  $encabezado
     * @return list<string>
     */
    private function traducirAlias(array $encabezado): array
    {
        foreach (self::ALIAS as $viejo => $nuevo) {
            if (in_array($nuevo, $encabezado, true)) {
                continue;
            }

            $indice = array_search($viejo, $encabezado, true);

            if ($indice !== false) {
                $encabezado[$indice] = $nuevo;
                $this->comment("La columna \"{$viejo}\" se importa como \"{$nuevo}\".");
            }
        }

        return $encabezado;
    }

    /**
     * Convierte el texto del CSV en la cantidad decimal, o null si no es un
     * numero.
     *
     * Se admite la coma como separador decimal porque el separador de columnas
     * por defecto es ";", justo el que usa el Excel en español, y ese mismo
     * Excel escribe "1,5" y no "1.5".
     */
    private function aDecimal(string $valor): ?float
    {
        if (! str_contains($valor, '.') && substr_count($valor, ',') === 1) {
            $valor = str_replace(',', '.', $valor);
        }

        return is_numeric($valor) ? (float) $valor : null;
    }

    private function buscarFoto(string $codigo): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            foreach (["{$codigo}.{$extension}", "{$codigo}_v2.{$extension}"] as $nombre) {
                if (is_file(public_path('img/'.$nombre))) {
                    return $nombre;
                }
            }
        }

        return null;
    }
}
