<?php

namespace App\Services;

use App\Exceptions\ClaudeApiException;
use App\Models\ImagenIndice;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Construye y mantiene tbl_imagen_indice: una descripcion visual en texto por
 * cada foto de public/img, generada por Claude.
 *
 * POR QUE UN INDICE PREVIO: comparar la foto del usuario contra las ~760
 * imagenes de la carpeta en cada consulta seria lento y caro (cientos de miles
 * de tokens por busqueda). Con el indice, la busqueda manda la foto del usuario
 * mas el texto de las descripciones, y solo los mejores candidatos se verifican
 * mirando su foto real (ver BuscadorRepuestosPorImagen).
 *
 * INCREMENTAL: solo se envian los archivos nuevos o cuyo sha256 cambio. Las
 * filas de archivos que ya no existen se borran (es un dato derivado).
 *
 * Varias fotos por peticion (services.anthropic.imagen.lote_indice) para
 * repartir el costo de las instrucciones; cada lote deja su linea en la
 * bitacora de costos.
 */
class IndexadorImagenesCatalogo
{
    /** Los mismos tipos que acepta la busqueda del usuario. */
    public const EXTENSIONES = ['jpg', 'jpeg', 'png'];

    /**
     * Salida estimada por foto para las simulaciones (20 a 40 palabras + JSON).
     *
     * La descripcion se pide corta a proposito: TODO el indice viaja en cada
     * busqueda, asi que cada palabra de mas se paga en cada consulta.
     */
    public const TOKENS_SALIDA_POR_IMAGEN = 100;

    private const LARGO_DESCRIPCION = 1000;

    private const INSTRUCCIONES = 'Eres el catalogador de un almacen de repuestos industriales. Recibiras fotos del catalogo, '
        .'cada una precedida por el texto "Imagen N:". Para CADA foto escribe una descripcion visual en espanol, de 20 a 40 '
        .'palabras, que sirva para reconocer la misma pieza en otra foto tomada con un celular: tipo de pieza (rodamiento, '
        .'filtro, valvula, tornillo, empaque, correa, sensor, etc.), forma y geometria, material y acabado aparente, colores de '
        .'la pieza, proporciones, roscas, orificios, y marcas, numeros o textos legibles. Ignora el marco de color del borde de '
        .'la foto, el fondo y la iluminacion: no son parte de la pieza. No inventes nada que no se vea. Responde una entrada '
        .'por foto, con su numero N.';

    public function __construct(
        private readonly ClienteClaudeApi $cliente,
        private readonly PreparadorImagenClaude $preparador,
        private readonly BitacoraCostoClaude $bitacora,
    ) {}

    /**
     * Que hay que hacer, sin llamar a la API. `pendientes` es nombre de archivo
     * => sha256.
     *
     * @return array{pendientes: array<string, string>, al_dia: int, huerfanos: list<string>}
     */
    public function planificar(bool $forzar, ?int $limite = null): array
    {
        $archivos = $this->archivosDeCatalogo();
        $indexados = ImagenIndice::query()->pluck('col_hash', 'col_archivo')->all();

        $pendientes = [];
        $alDia = 0;

        foreach ($archivos as $nombre => $ruta) {
            $hash = (string) hash_file('sha256', $ruta);

            if (! $forzar && ($indexados[$nombre] ?? null) === $hash) {
                $alDia++;

                continue;
            }

            $pendientes[$nombre] = $hash;
        }

        if ($limite !== null && $limite > 0) {
            $pendientes = array_slice($pendientes, 0, $limite, true);
        }

        // En Windows el sistema de archivos no distingue mayusculas; la
        // comparacion de huerfanos tampoco debe hacerlo.
        $existentes = array_change_key_case($archivos, CASE_LOWER);
        $huerfanos = array_values(array_filter(
            array_keys($indexados),
            fn (string $nombre) => ! isset($existentes[mb_strtolower($nombre)]),
        ));

        return ['pendientes' => $pendientes, 'al_dia' => $alDia, 'huerfanos' => $huerfanos];
    }

    /**
     * Tokens de imagen que costaria indexar estos archivos (sin las
     * instrucciones ni la salida).
     *
     * @param  array<string, string>  $pendientes
     */
    public function tokensDeImagen(array $pendientes): int
    {
        $lado = (int) config('services.anthropic.imagen.lado_indice', 768);
        $total = 0;

        foreach (array_keys($pendientes) as $nombre) {
            $total += $this->preparador->tokensEstimados($this->ruta($nombre), $lado);
        }

        return $total;
    }

    /**
     * Envia los pendientes por lotes y guarda cada descripcion.
     *
     * @param  array<string, string>  $pendientes  nombre => sha256
     * @param  callable(int): void|null  $alAvanzar  recibe cuantas fotos se procesaron en el lote
     * @return array{indexadas: int, fallidas: list<string>, uso: UsoClaude}
     *
     * @throws ClaudeApiException al primer lote que falle; lo ya guardado queda guardado
     */
    public function indexar(array $pendientes, ?callable $alAvanzar = null): array
    {
        $tamanoLote = max(1, min(20, (int) config('services.anthropic.imagen.lote_indice', 8)));
        $indexadas = 0;
        $fallidas = [];
        $uso = UsoClaude::vacio();

        foreach (array_chunk($pendientes, $tamanoLote, true) as $lote) {
            $resultado = $this->indexarLote($lote);
            $indexadas += $resultado['indexadas'];
            $fallidas = array_merge($fallidas, $resultado['fallidas']);
            $uso = $uso->sumar($resultado['uso']);

            if ($alAvanzar !== null) {
                $alAvanzar(count($lote));
            }
        }

        return ['indexadas' => $indexadas, 'fallidas' => $fallidas, 'uso' => $uso];
    }

    /**
     * @param  list<string>  $huerfanos
     */
    public function borrarHuerfanos(array $huerfanos): int
    {
        $borradas = 0;

        // 1000 por sentencia: limite de 2100 parametros de SQL Server.
        foreach (array_chunk($huerfanos, 1000) as $lote) {
            $borradas += ImagenIndice::query()->whereIn('col_archivo', $lote)->delete();
        }

        return $borradas;
    }

    /**
     * @param  array<string, string>  $lote  nombre => sha256
     * @return array{indexadas: int, fallidas: list<string>, uso: UsoClaude}
     */
    private function indexarLote(array $lote): array
    {
        $lado = (int) config('services.anthropic.imagen.lado_indice', 768);
        $contenido = [];
        $porNumero = [];
        $fallidas = [];

        foreach (array_keys($lote) as $nombre) {
            try {
                $bloque = $this->preparador->bloque($this->ruta($nombre), $lado);
            } catch (InvalidArgumentException) {
                // Un archivo corrupto no debe frenar a los demas: se reporta.
                $fallidas[] = $nombre;

                continue;
            }

            $numero = count($porNumero) + 1;
            $porNumero[$numero] = $nombre;
            $contenido[] = ['type' => 'text', 'text' => "Imagen {$numero}:"];
            $contenido[] = $bloque;
        }

        if ($porNumero === []) {
            return ['indexadas' => 0, 'fallidas' => $fallidas, 'uso' => UsoClaude::vacio()];
        }

        $contenido[] = ['type' => 'text', 'text' => 'Describe cada una de las '.count($porNumero).' imagenes.'];
        $inicio = microtime(true);

        try {
            $respuesta = $this->cliente->json(
                [['type' => 'text', 'text' => self::INSTRUCCIONES]],
                $contenido,
                $this->esquema(),
            );
        } catch (ClaudeApiException $e) {
            $this->bitacora->registrar(
                BitacoraCostoClaude::OPERACION_INDICE,
                $e->uso ?? UsoClaude::vacio(),
                'imagenes: 0 de '.count($porNumero),
                microtime(true) - $inicio,
                'error: '.$e->getMessage(),
            );

            throw $e;
        }

        $indexadas = 0;
        $ahora = Carbon::now();

        foreach ((array) ($respuesta['datos']['imagenes'] ?? []) as $entrada) {
            $nombre = $porNumero[(int) ($entrada['numero'] ?? 0)] ?? null;
            $descripcion = trim((string) ($entrada['descripcion'] ?? ''));

            if ($nombre === null || $descripcion === '') {
                continue;
            }

            ImagenIndice::query()->updateOrCreate(
                ['col_archivo' => $nombre],
                [
                    'col_hash' => $lote[$nombre],
                    'col_codigo' => ImagenIndice::codigoDeArchivo($nombre),
                    'col_descripcion' => mb_substr($descripcion, 0, self::LARGO_DESCRIPCION),
                    'col_modelo' => mb_substr((string) config('services.anthropic.modelo'), 0, 60),
                    'col_indexado_at' => $ahora,
                ],
            );

            unset($porNumero[(int) $entrada['numero']]);
            $indexadas++;
        }

        // Lo que el modelo no describio queda pendiente para la proxima corrida.
        $fallidas = array_merge($fallidas, array_values($porNumero));

        $this->bitacora->registrar(
            BitacoraCostoClaude::OPERACION_INDICE,
            $respuesta['uso'],
            "imagenes: {$indexadas}",
            microtime(true) - $inicio,
        );

        return ['indexadas' => $indexadas, 'fallidas' => $fallidas, 'uso' => $respuesta['uso']];
    }

    /**
     * @return array<string, mixed>
     */
    private function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'imagenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'numero' => ['type' => 'integer'],
                            'descripcion' => ['type' => 'string'],
                        ],
                        'required' => ['numero', 'descripcion'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['imagenes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Fotos de public/img con extension admitida, ordenadas por nombre para que
     * los lotes no dependan de como liste el sistema de archivos.
     *
     * @return array<string, string> nombre => ruta absoluta
     */
    private function archivosDeCatalogo(): array
    {
        $archivos = [];

        foreach (glob(public_path('img').DIRECTORY_SEPARATOR.'*') ?: [] as $ruta) {
            if (is_file($ruta) && in_array(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)), self::EXTENSIONES, true)) {
                $archivos[basename($ruta)] = $ruta;
            }
        }

        ksort($archivos, SORT_NATURAL | SORT_FLAG_CASE);

        return $archivos;
    }

    private function ruta(string $nombre): string
    {
        return public_path('img').DIRECTORY_SEPARATOR.$nombre;
    }
}
