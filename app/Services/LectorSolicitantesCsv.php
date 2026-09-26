<?php

namespace App\Services;

use App\Exceptions\ArchivoSolicitantesInvalidoException;

/**
 * Lee el CSV de solicitantes del ERP y entrega filas en el formato
 * normalizado de ImportadorSolicitantesErp.
 *
 * Es la mitad de LECTURA: no valida datos ni escribe en la base. El dia que
 * exista la API del ERP, su lector reemplaza a esta clase y el importador
 * queda igual.
 *
 * Encabezados (primera fila, sin importar mayusculas ni el orden):
 *   codigo_erp (obligatorio), nombre (obligatorio),
 *   cedula, correo, area, telefono, activo (opcionales).
 * Una columna desconocida se ignora.
 */
class LectorSolicitantesCsv
{
    public const COLUMNAS = ['codigo_erp', 'nombre', 'cedula', 'correo', 'area', 'telefono', 'activo'];

    public const OBLIGATORIAS = ['codigo_erp', 'nombre'];

    /**
     * Encabezados alternos que se aceptan como el nombre normalizado.
     * `cod_empleado` es como el ERP llama al codigo en sus documentos.
     *
     * @var array<string, string>
     */
    private const ALIAS = [
        'codigo' => 'codigo_erp',
        'cod_empleado' => 'codigo_erp',
        'email' => 'correo',
        'estado' => 'activo',
    ];

    /**
     * @return \Generator<int, array<string, ?string>> llave = numero de linea del archivo
     *
     * @throws ArchivoSolicitantesInvalidoException si el archivo no existe, esta vacio o le faltan columnas obligatorias
     */
    public function leer(string $ruta, string $separador = ';'): \Generator
    {
        if (! is_file($ruta)) {
            throw new ArchivoSolicitantesInvalidoException("No se encontro el archivo: {$ruta}");
        }

        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            throw new ArchivoSolicitantesInvalidoException('No se pudo abrir el archivo.');
        }

        try {
            $encabezado = fgetcsv($manejador, 0, $separador);

            if ($encabezado === false || $encabezado === [null]) {
                throw new ArchivoSolicitantesInvalidoException('El archivo esta vacio.');
            }

            $columnas = $this->columnas($encabezado);
            $faltantes = array_diff(self::OBLIGATORIAS, $columnas);

            if ($faltantes !== []) {
                throw new ArchivoSolicitantesInvalidoException(
                    'Faltan columnas obligatorias: '.implode(', ', $faltantes).
                    '. Columnas admitidas: '.implode(', ', self::COLUMNAS).
                    '. Revise tambien el separador (--separador).'
                );
            }

            $linea = 1;

            while (($celdas = fgetcsv($manejador, 0, $separador)) !== false) {
                $linea++;

                if ($celdas === [null] || $celdas === []) {
                    continue;
                }

                $fila = [];

                foreach ($columnas as $indice => $columna) {
                    if (in_array($columna, self::COLUMNAS, true)) {
                        $fila[$columna] = isset($celdas[$indice]) ? $this->aUtf8((string) $celdas[$indice]) : null;
                    }
                }

                yield $linea => $fila;
            }
        } finally {
            fclose($manejador);
        }
    }

    /**
     * Nombres de columna normalizados, con el BOM de Excel fuera y los alias
     * traducidos (salvo que el archivo ya traiga el nombre normalizado, para
     * que dos columnas no escriban el mismo campo).
     *
     * @param  array<int, ?string>  $encabezado
     * @return array<int, string>
     */
    private function columnas(array $encabezado): array
    {
        $columnas = array_map(
            fn ($columna) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $columna))),
            $encabezado
        );

        foreach ($columnas as $indice => $columna) {
            $normal = self::ALIAS[$columna] ?? null;

            if ($normal !== null && ! in_array($normal, $columnas, true)) {
                $columnas[$indice] = $normal;
            }
        }

        return $columnas;
    }

    /**
     * Excel en español guarda el CSV "normal" en Windows-1252: sin esta
     * conversion, un nombre con enie o tilde llegaria como bytes invalidos.
     */
    private function aUtf8(string $valor): string
    {
        return mb_check_encoding($valor, 'UTF-8') ? $valor : mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
    }
}
