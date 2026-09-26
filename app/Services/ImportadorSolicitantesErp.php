<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escribe los solicitantes del ERP en tbl_solicitante_erp.
 *
 * Es la mitad de ESCRITURA de la carga y no sabe de donde vienen las filas:
 * hoy las entrega LectorSolicitantesCsv (comando solicitantes:importar) y
 * manana las entregara un lector de la API del ERP. Cualquier fuente nueva
 * llama a importar() con filas en el formato normalizado, sin duplicar aqui
 * ni la validacion ni el MERGE.
 *
 * Formato normalizado de cada fila (todas las llaves opcionales salvo las dos
 * primeras; null o '' = "no informado"):
 *   codigo_erp, nombre, cedula, correo, area, telefono, activo
 *
 * Reglas:
 *  - Sin codigo_erp o sin nombre, o con uno de los dos demasiado largo, la
 *    fila se OMITE con un aviso: no se mete basura en la tabla.
 *  - Un dato opcional invalido (correo mal formado, cedula con letras, texto
 *    demasiado largo, activo que no se entiende) se DESCARTA con un aviso y el
 *    resto de la fila si entra, igual que repuestos:importar con las cantidades.
 *  - Un dato opcional no informado CONSERVA el que ya habia: un archivo sin la
 *    columna correo no le borra el correo a nadie (y sin correo no sale el
 *    aviso de "pedido listo"). Al crear, activo no informado es activo.
 *  - Un codigo repetido en la misma carga: gana la ULTIMA fila y se avisa. El
 *    MERGE fallaria si el mismo codigo llegara dos veces en un lote.
 */
class ImportadorSolicitantesErp
{
    /**
     * Filas por sentencia MERGE: 7 parametros por fila mas 3 fechas. 200 filas
     * son 1.403 parametros, holgado contra el limite de 2.100 de SQL Server.
     */
    private const LOTE = 200;

    /** Largos de las columnas, los mismos de la migracion. */
    private const LARGOS = [
        'codigo_erp' => 30,
        'nombre' => 150,
        'cedula' => 30,
        'correo' => 150,
        'area' => 100,
        'telefono' => 30,
    ];

    private const VERDADEROS = ['1', 'si', 's', 'true', 'activo', 'a', 'yes', 'y'];

    private const FALSOS = ['0', 'no', 'n', 'false', 'inactivo', 'i'];

    /**
     * @param  iterable<int|string, array<string, mixed>>  $filas  la llave es la referencia que sale en los avisos (la linea del CSV)
     */
    public function importar(iterable $filas): ResultadoImportacionSolicitantes
    {
        $avisos = [];
        $validas = [];
        $referencias = [];
        $leidas = 0;
        $omitidas = 0;
        $repetidas = 0;

        foreach ($filas as $referencia => $fila) {
            $leidas++;
            $normalizada = $this->normalizar($fila, $referencia, $avisos);

            if ($normalizada === null) {
                $omitidas++;

                continue;
            }

            $codigo = $normalizada['codigo_erp'];

            if (isset($validas[$codigo])) {
                $repetidas++;
                $avisos[] = [
                    'referencia' => $referencias[$codigo],
                    'mensaje' => "el codigo {$codigo} se repite en la referencia {$referencia}; se usa la ultima.",
                ];
                // Se saca y se vuelve a poner para que el orden del archivo se
                // respete y la ultima aparicion sea la que se escribe.
                unset($validas[$codigo]);
            }

            $validas[$codigo] = $normalizada;
            $referencias[$codigo] = $referencia;
        }

        [$creados, $actualizados] = $validas === [] ? [0, 0] : $this->escribir(array_values($validas));

        $resultado = new ResultadoImportacionSolicitantes(
            leidas: $leidas,
            creados: $creados,
            actualizados: $actualizados,
            sinCambio: count($validas) - $creados - $actualizados,
            omitidas: $omitidas,
            repetidas: $repetidas,
            avisos: $avisos,
        );

        // Solo contadores: nada de cedulas ni correos en el log.
        Log::info('Importacion de solicitantes del ERP', [
            'leidas' => $resultado->leidas,
            'creados' => $resultado->creados,
            'actualizados' => $resultado->actualizados,
            'sin_cambio' => $resultado->sinCambio,
            'omitidas' => $resultado->omitidas,
            'repetidas' => $resultado->repetidas,
            'avisos' => count($avisos),
        ]);

        return $resultado;
    }

    /**
     * Valida y limpia una fila. Devuelve null si hay que omitirla.
     *
     * @param  array<string, mixed>  $fila
     * @param  list<array{referencia: int|string, mensaje: string}>  $avisos
     * @return array{codigo_erp: string, nombre: string, cedula: ?string, correo: ?string, area: ?string, telefono: ?string, activo: ?int}|null
     */
    private function normalizar(array $fila, int|string $referencia, array &$avisos): ?array
    {
        $avisar = function (string $mensaje) use ($referencia, &$avisos): void {
            $avisos[] = ['referencia' => $referencia, 'mensaje' => $mensaje];
        };

        $codigo = $this->texto($fila['codigo_erp'] ?? null);
        $nombre = $this->texto($fila['nombre'] ?? null);

        if ($codigo === null) {
            $avisar('se omite la fila porque no trae codigo_erp.');

            return null;
        }

        if ($nombre === null) {
            $avisar("se omite el codigo {$codigo} porque no trae nombre.");

            return null;
        }

        foreach (['codigo_erp' => $codigo, 'nombre' => $nombre] as $campo => $valor) {
            if (mb_strlen($valor) > self::LARGOS[$campo]) {
                $avisar("se omite la fila porque {$campo} supera los ".self::LARGOS[$campo].' caracteres.');

                return null;
            }
        }

        $opcionales = [];

        foreach (['cedula', 'correo', 'area', 'telefono'] as $campo) {
            $valor = $this->texto($fila[$campo] ?? null);

            if ($valor !== null && mb_strlen($valor) > self::LARGOS[$campo]) {
                $avisar("codigo {$codigo}: se ignora {$campo} porque supera los ".self::LARGOS[$campo].' caracteres.');
                $valor = null;
            }

            $opcionales[$campo] = $valor;
        }

        if ($opcionales['cedula'] !== null && preg_match('/^[0-9.\-]+$/', $opcionales['cedula']) !== 1) {
            $avisar("codigo {$codigo}: se ignora la cedula porque solo admite numeros, puntos o guiones.");
            $opcionales['cedula'] = null;
        }

        if ($opcionales['correo'] !== null) {
            $opcionales['correo'] = mb_strtolower($opcionales['correo']);

            if (filter_var($opcionales['correo'], FILTER_VALIDATE_EMAIL) === false) {
                $avisar("codigo {$codigo}: se ignora el correo porque no es una direccion valida.");
                $opcionales['correo'] = null;
            }
        }

        return [
            'codigo_erp' => $codigo,
            'nombre' => $nombre,
            ...$opcionales,
            'activo' => $this->activo($fila['activo'] ?? null, $codigo, $avisar),
        ];
    }

    /**
     * Recorta y colapsa los espacios internos; la cadena vacia es "no informado".
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');

        return $valor === '' ? null : $valor;
    }

    private function activo(mixed $valor, string $codigo, callable $avisar): ?int
    {
        if (is_bool($valor)) {
            return (int) $valor;
        }

        $texto = mb_strtolower((string) $this->texto($valor));

        if ($texto === '') {
            return null;
        }

        if (in_array($texto, self::VERDADEROS, true)) {
            return 1;
        }

        if (in_array($texto, self::FALSOS, true)) {
            return 0;
        }

        $avisar("codigo {$codigo}: se ignora activo porque \"{$texto}\" no es un valor reconocido (use 1/0, si/no).");

        return null;
    }

    /**
     * Escribe todas las filas en una sola transaccion: un fallo a mitad de la
     * carga no deja el archivo aplicado a medias.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{0: int, 1: int} creados, actualizados
     */
    private function escribir(array $filas): array
    {
        return DB::transaction(function () use ($filas) {
            $creados = 0;
            $actualizados = 0;

            foreach (array_chunk($filas, self::LOTE) as $lote) {
                foreach ($this->escribirLote($lote) as $accion) {
                    match ($accion) {
                        'INSERT' => $creados++,
                        'UPDATE' => $actualizados++,
                        default => null,
                    };
                }
            }

            return [$creados, $actualizados];
        });
    }

    /**
     * MERGE nativo con HOLDLOCK por col_codigo_erp (mismo patron que
     * PermisoService). Solo actualiza cuando algun dato cambia: el EXISTS con
     * EXCEPT compara tratando NULL = NULL, y los textos se comparan en binario
     * para que una correccion de mayusculas del ERP tambien cuente como cambio.
     *
     * @param  list<array<string, mixed>>  $lote
     * @return list<string> la accion de cada fila tocada ('INSERT' o 'UPDATE')
     */
    private function escribirLote(array $lote): array
    {
        $valores = implode(', ', array_fill(0, count($lote), '(?, ?, ?, ?, ?, ?, ?)'));
        $parametros = [];

        foreach ($lote as $fila) {
            array_push(
                $parametros,
                $fila['codigo_erp'], $fila['nombre'], $fila['cedula'], $fila['correo'],
                $fila['area'], $fila['telefono'], $fila['activo'],
            );
        }

        $ahora = now();
        $binario = 'collate Latin1_General_100_BIN2';

        $filas = DB::select(
            "merge [tbl_solicitante_erp] with (holdlock) as destino
             using (
                select cast(v.codigo as nvarchar(30)) as codigo,
                       cast(v.nombre as nvarchar(150)) as nombre,
                       cast(v.cedula as nvarchar(30)) as cedula,
                       cast(v.correo as nvarchar(150)) as correo,
                       cast(v.area as nvarchar(100)) as area,
                       cast(v.telefono as nvarchar(30)) as telefono,
                       cast(v.activo as bit) as activo
                  from (values {$valores}) as v (codigo, nombre, cedula, correo, area, telefono, activo)
             ) as origen
                on destino.[col_codigo_erp] = origen.codigo
             when matched and exists (
                    select origen.nombre {$binario},
                           coalesce(origen.cedula, destino.[col_cedula]) {$binario},
                           coalesce(origen.correo, destino.[col_correo]) {$binario},
                           coalesce(origen.area, destino.[col_area]) {$binario},
                           coalesce(origen.telefono, destino.[col_telefono]) {$binario},
                           coalesce(origen.activo, destino.[col_activo])
                    except
                    select destino.[col_nombre], destino.[col_cedula], destino.[col_correo],
                           destino.[col_area], destino.[col_telefono], destino.[col_activo]
                  ) then update set
                  destino.[col_nombre] = origen.nombre,
                  destino.[col_cedula] = coalesce(origen.cedula, destino.[col_cedula]),
                  destino.[col_correo] = coalesce(origen.correo, destino.[col_correo]),
                  destino.[col_area] = coalesce(origen.area, destino.[col_area]),
                  destino.[col_telefono] = coalesce(origen.telefono, destino.[col_telefono]),
                  destino.[col_activo] = coalesce(origen.activo, destino.[col_activo]),
                  destino.[updated_at] = ?
             when not matched then insert
                  ([col_codigo_erp], [col_nombre], [col_cedula], [col_correo], [col_area],
                   [col_telefono], [col_activo], [created_at], [updated_at])
                  values (origen.codigo, origen.nombre, origen.cedula, origen.correo, origen.area,
                          origen.telefono, coalesce(origen.activo, 1), ?, ?)
             output \$action as accion;",
            [...$parametros, $ahora, $ahora, $ahora]
        );

        return array_map(fn ($fila) => (string) $fila->accion, $filas);
    }
}
