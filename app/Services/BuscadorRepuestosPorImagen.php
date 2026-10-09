<?php

namespace App\Services;

use App\Exceptions\ClaudeApiException;
use App\Models\ImagenIndice;
use App\Models\Repuesto;
use InvalidArgumentException;

/**
 * Busqueda de repuestos del catalogo a partir de una foto del usuario.
 *
 * Dos pasos, los dos con Claude:
 *
 *  1. PRESELECCION: la foto del usuario + el indice textual de public/img
 *     (tbl_imagen_indice, una descripcion por foto). Claude devuelve los
 *     candidatos mas parecidos. El indice va en el system con cache_control:
 *     es el mismo texto en todas las consultas, asi que mientras la cache este
 *     viva se cobra a la tarifa de lectura de cache y no a la de entrada.
 *  2. VERIFICACION: la foto del usuario + las fotos REALES de esos candidatos
 *     (pocas y reducidas). Claude da el porcentaje final mirando las dos
 *     imagenes, que es lo que hace confiable el umbral. Se puede apagar con
 *     ANTHROPIC_IMAGEN_VERIFICAR=false y entonces manda el porcentaje del paso 1.
 *
 * EL PORCENTAJE ES UN JUICIO DEL MODELO, no una medida matematica de
 * similitud: se le pide expresamente que 80 o mas signifique "muy probablemente
 * la misma referencia de pieza", no "de la misma familia".
 *
 * Solo entran fotos que se pueden mostrar: las que pertenecen a un repuesto
 * activo (por repuestos.foto o por el codigo del nombre del archivo). Varias
 * fotos del mismo repuesto se colapsan quedandose con el mayor porcentaje.
 *
 * Cada consulta deja UNA linea en la bitacora de costos, con los tokens de los
 * dos pasos sumados, tambien cuando falla.
 */
class BuscadorRepuestosPorImagen
{
    /** En el paso 1 no vale la pena verificar algo por debajo de esto. */
    private const PISO_PRESELECCION = 40;

    private const ESCALA = 'El porcentaje es tu grado de certeza de que la pieza de la foto del usuario es EXACTAMENTE la '
        .'misma referencia de repuesto (mismo tipo, forma, proporciones y rasgos distintivos), no solo de la misma familia: '
        .'90 a 100 = practicamente seguro que es la misma pieza; 80 a 89 = muy probablemente la misma; 50 a 79 = parecida '
        .'pero con diferencias visibles; menos de 50 = otra pieza. Ignora el fondo, la iluminacion, el angulo y el marco de '
        .'color del borde de las fotos del catalogo.';

    public function __construct(
        private readonly ClienteClaudeApi $cliente,
        private readonly PreparadorImagenClaude $preparador,
        private readonly BitacoraCostoClaude $bitacora,
    ) {}

    /**
     * @param  string  $ruta  archivo temporal de la imagen subida; no se copia ni se guarda
     *
     * @throws ClaudeApiException con un mensaje apto para el usuario
     * @throws InvalidArgumentException si el archivo no es una imagen legible
     */
    public function buscar(string $ruta): ResultadoBusquedaImagen
    {
        if (! $this->cliente->estaConfigurado()) {
            throw new ClaudeApiException('La busqueda por imagen no esta configurada en este servidor.');
        }

        $entradas = $this->entradasConRepuesto();

        if ($entradas === []) {
            throw new ClaudeApiException('La busqueda por imagen todavia no esta disponible: falta preparar el indice de fotos del catalogo.');
        }

        $fotoUsuario = $this->preparador->bloque($ruta, (int) config('services.anthropic.imagen.lado_consulta', 1024));
        $umbral = $this->umbral();
        $inicio = microtime(true);
        $uso = UsoClaude::vacio();
        $estado = 'ok';
        $porRepuesto = [];

        try {
            $paso1 = $this->preseleccionar($fotoUsuario, $entradas);
            $uso = $uso->sumar($paso1['uso']);
            $finales = $paso1['candidatos'];

            if ($finales !== [] && (bool) config('services.anthropic.imagen.verificar', true)) {
                $paso2 = $this->verificar($fotoUsuario, $finales, $entradas);
                $uso = $uso->sumar($paso2['uso']);
                $finales = $paso2['candidatos'];
            }

            foreach ($finales as $idIndice => $porcentaje) {
                $repuestoId = $entradas[$idIndice]['repuesto_id'];

                if ($porcentaje >= $umbral) {
                    $porRepuesto[$repuestoId] = max($porRepuesto[$repuestoId] ?? 0, $porcentaje);
                }
            }
        } catch (ClaudeApiException $e) {
            $uso = $uso->sumar($e->uso ?? UsoClaude::vacio());
            $estado = 'error: '.$e->getMessage();

            throw $e;
        } finally {
            $this->bitacora->registrar(
                BitacoraCostoClaude::OPERACION_BUSQUEDA,
                $uso,
                'coincidencias: '.count($porRepuesto),
                microtime(true) - $inicio,
                $estado,
            );
        }

        arsort($porRepuesto);

        $repuestos = Repuesto::query()
            ->activos()
            ->whereKey(array_keys($porRepuesto))
            ->with('categoriaAsignada')
            ->get()
            ->sortByDesc(fn (Repuesto $repuesto) => $porRepuesto[$repuesto->id] ?? 0)
            ->values();

        return new ResultadoBusquedaImagen($repuestos, $porRepuesto, $umbral, $uso);
    }

    public function umbral(): int
    {
        return max(1, min(100, (int) config('services.anthropic.imagen.umbral', 80)));
    }

    /**
     * Fotos del indice que pertenecen a un repuesto activo, por id del indice.
     *
     * @return array<int, array{archivo: string, descripcion: string, repuesto_id: int, codigo: int}>
     */
    private function entradasConRepuesto(): array
    {
        $indice = ImagenIndice::query()->orderBy('id')->get(['id', 'col_archivo', 'col_codigo', 'col_descripcion']);

        if ($indice->isEmpty()) {
            return [];
        }

        // repuestos.foto guarda el nombre del archivo; la comparacion va en
        // minusculas porque el sistema de archivos de Windows no distingue.
        $porFoto = [];
        foreach (Repuesto::query()->activos()->whereNotNull('foto')->get(['id', 'codigo', 'foto']) as $repuesto) {
            $porFoto[mb_strtolower($repuesto->foto)] = [$repuesto->id, $repuesto->codigo];
        }

        // Las fotos alternas (_v2) se resuelven por el codigo del nombre.
        $porCodigo = [];
        $codigos = $indice->pluck('col_codigo')->filter()->unique()->values()->all();
        foreach (array_chunk($codigos, 1000) as $lote) {
            foreach (Repuesto::query()->activos()->whereIn('codigo', $lote)->get(['id', 'codigo']) as $repuesto) {
                $porCodigo[$repuesto->codigo] = [$repuesto->id, $repuesto->codigo];
            }
        }

        $entradas = [];

        foreach ($indice as $fila) {
            $repuesto = $porFoto[mb_strtolower($fila->col_archivo)] ?? ($fila->col_codigo !== null ? ($porCodigo[$fila->col_codigo] ?? null) : null);

            if ($repuesto === null) {
                continue;
            }

            $entradas[$fila->id] = [
                'archivo' => $fila->col_archivo,
                'descripcion' => $fila->col_descripcion,
                'repuesto_id' => (int) $repuesto[0],
                'codigo' => (int) $repuesto[1],
            ];
        }

        return $entradas;
    }

    /**
     * Paso 1: la foto contra el indice textual.
     *
     * @param  array<string, mixed>  $fotoUsuario
     * @param  array<int, array{archivo: string, descripcion: string, repuesto_id: int, codigo: int}>  $entradas
     * @return array{candidatos: array<int, int>, uso: UsoClaude}
     */
    private function preseleccionar(array $fotoUsuario, array $entradas): array
    {
        $maximo = $this->maximoCandidatos();
        $catalogo = "CATALOGO (id | codigo del repuesto | descripcion visual):\n";

        foreach ($entradas as $id => $entrada) {
            $catalogo .= $id.' | '.$entrada['codigo'].' | '.str_replace(["\r", "\n"], ' ', $entrada['descripcion'])."\n";
        }

        $respuesta = $this->cliente->json(
            [
                ['type' => 'text', 'text' => 'Eres el experto en repuestos industriales de un almacen. El usuario envia la foto '
                    .'de una pieza y debes encontrarla en el catalogo, del que solo tienes una descripcion visual por foto. '
                    .self::ESCALA],
                // Ultimo bloque del prefijo estable: aqui va el punto de cache.
                ['type' => 'text', 'text' => $catalogo, 'cache_control' => ['type' => 'ephemeral']],
            ],
            [
                $fotoUsuario,
                ['type' => 'text', 'text' => "Devuelve hasta {$maximo} ids del catalogo que mejor correspondan a la pieza de "
                    .'esta foto, con su porcentaje, de mayor a menor. Si ninguna entrada se parece, devuelve la lista vacia.'],
            ],
            $this->esquema('candidatos', 'id'),
        );

        $candidatos = $this->leerPorcentajes($respuesta['datos']['candidatos'] ?? [], 'id', fn (int $id) => isset($entradas[$id]) ? $id : null);
        $candidatos = array_filter($candidatos, fn (int $porcentaje) => $porcentaje >= min(self::PISO_PRESELECCION, $this->umbral()));

        return ['candidatos' => array_slice($candidatos, 0, $maximo, true), 'uso' => $respuesta['uso']];
    }

    /**
     * Paso 2: la foto contra las fotos reales de los candidatos.
     *
     * @param  array<string, mixed>  $fotoUsuario
     * @param  array<int, int>  $candidatos  id del indice => porcentaje del paso 1
     * @param  array<int, array{archivo: string, descripcion: string, repuesto_id: int, codigo: int}>  $entradas
     * @return array{candidatos: array<int, int>, uso: UsoClaude}
     */
    private function verificar(array $fotoUsuario, array $candidatos, array $entradas): array
    {
        $lado = (int) config('services.anthropic.imagen.lado_catalogo', 512);
        $contenido = [['type' => 'text', 'text' => 'Foto del usuario:'], $fotoUsuario];
        $porNumero = [];

        foreach (array_keys($candidatos) as $idIndice) {
            try {
                $bloque = $this->preparador->bloque(public_path('img').DIRECTORY_SEPARATOR.$entradas[$idIndice]['archivo'], $lado);
            } catch (InvalidArgumentException) {
                continue; // la foto del catalogo desaparecio o esta danada
            }

            $numero = count($porNumero) + 1;
            $porNumero[$numero] = $idIndice;
            $contenido[] = ['type' => 'text', 'text' => "Candidata {$numero}:"];
            $contenido[] = $bloque;
        }

        if ($porNumero === []) {
            return ['candidatos' => [], 'uso' => UsoClaude::vacio()];
        }

        $contenido[] = ['type' => 'text', 'text' => 'Compara la pieza de la foto del usuario con cada candidata y da un '
            .'porcentaje para cada una.'];

        $respuesta = $this->cliente->json(
            [['type' => 'text', 'text' => 'Eres el experto en repuestos industriales de un almacen. Verificas si la pieza '
                .'que fotografio el usuario es la misma que aparece en fotos del catalogo. '.self::ESCALA]],
            $contenido,
            $this->esquema('coincidencias', 'candidata'),
        );

        return [
            'candidatos' => $this->leerPorcentajes(
                $respuesta['datos']['coincidencias'] ?? [],
                'candidata',
                fn (int $numero) => $porNumero[$numero] ?? null,
            ),
            'uso' => $respuesta['uso'],
        ];
    }

    /**
     * Normaliza la lista que devolvio el modelo: descarta referencias que no
     * existen, topa el porcentaje a 0-100 y ordena de mayor a menor.
     *
     * @param  callable(int): (int|null)  $resolver  traduce la referencia a un id del indice
     * @return array<int, int> id del indice => porcentaje
     */
    private function leerPorcentajes(mixed $lista, string $campo, callable $resolver): array
    {
        $resultado = [];

        foreach (is_array($lista) ? $lista : [] as $item) {
            if (! is_array($item) || ! is_numeric($item[$campo] ?? null) || ! is_numeric($item['porcentaje'] ?? null)) {
                continue;
            }

            $id = $resolver((int) $item[$campo]);

            if ($id === null) {
                continue;
            }

            $porcentaje = max(0, min(100, (int) round((float) $item['porcentaje'])));
            $resultado[$id] = max($resultado[$id] ?? 0, $porcentaje);
        }

        arsort($resultado);

        return $resultado;
    }

    private function maximoCandidatos(): int
    {
        return max(1, min(20, (int) config('services.anthropic.imagen.candidatos', 8)));
    }

    /**
     * @return array<string, mixed>
     */
    private function esquema(string $lista, string $campo): array
    {
        return [
            'type' => 'object',
            'properties' => [
                $lista => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            $campo => ['type' => 'integer'],
                            'porcentaje' => ['type' => 'integer'],
                        ],
                        'required' => [$campo, 'porcentaje'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [$lista],
            'additionalProperties' => false,
        ];
    }
}
