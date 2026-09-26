<?php

namespace App\Console\Commands;

use App\Exceptions\ArchivoSolicitantesInvalidoException;
use App\Services\ImportadorSolicitantesErp;
use App\Services\LectorSolicitantesCsv;
use Illuminate\Console\Command;

/**
 * Carga (o actualiza) los solicitantes del ERP desde un CSV.
 *
 * Solo es la cara en consola: LectorSolicitantesCsv lee el archivo e
 * ImportadorSolicitantesErp valida y escribe. Cuando exista la API del ERP,
 * otro lector alimentara el mismo importador.
 */
class ImportarSolicitantes extends Command
{
    protected $signature = 'solicitantes:importar
                            {archivo : Ruta del archivo CSV}
                            {--separador=; : Separador de columnas (; o ,)}';

    protected $description = 'Importa o actualiza los solicitantes del ERP desde un CSV (codigo_erp,nombre,cedula,correo,area,telefono,activo)';

    public function handle(LectorSolicitantesCsv $lector, ImportadorSolicitantesErp $importador): int
    {
        $separador = (string) $this->option('separador');

        if (mb_strlen($separador) !== 1) {
            $this->error('El separador debe ser un solo caracter.');

            return self::FAILURE;
        }

        try {
            $resultado = $importador->importar($lector->leer((string) $this->argument('archivo'), $separador));
        } catch (ArchivoSolicitantesInvalidoException $e) {
            // Solo las del lector (archivo, encabezado): su mensaje es util y
            // no trae datos personales. Un fallo de base sale como tal.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($resultado->avisos as $aviso) {
            $this->warn("Linea {$aviso['referencia']}: {$aviso['mensaje']}");
        }

        $this->newLine();
        $this->table(['Concepto', 'Filas'], [
            ['Leidas', $resultado->leidas],
            ['Creadas', $resultado->creados],
            ['Actualizadas (cambiaron)', $resultado->actualizados],
            ['Ya estaban al dia', $resultado->sinCambio],
            ['Omitidas por datos invalidos', $resultado->omitidas],
            ['Codigo repetido (gana la ultima)', $resultado->repetidas],
        ]);

        return self::SUCCESS;
    }
}
