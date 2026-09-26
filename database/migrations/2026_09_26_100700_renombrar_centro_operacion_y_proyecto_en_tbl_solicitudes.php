<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Formaliza los nombres que decidio el usuario para dos columnas de
 * tbl_solicitudes creadas por 2026_09_26_100500:
 *
 *   centro_operacion -> cod_centro_operacion
 *   proyecto         -> cod_proyecto
 *
 * En la base de desarrollo ya se habian renombrado a mano despues de migrar,
 * asi que la migracion es IDEMPOTENTE por par:
 *
 *  - existe la vieja y no la nueva  -> sp_rename;
 *  - existe la nueva y no la vieja  -> nada (ya esta como debe);
 *  - existen las dos, o ninguna     -> aborta ANTES de tocar nada: no se
 *    adivina cual conserva los datos ni se inventa una columna.
 *
 * down() es el inverso con la misma regla, para que el down() de 100500
 * encuentre los nombres que el creo y las suelte.
 *
 * No se edita 100500 porque ya esta ejecutada: una instalacion nueva crea los
 * nombres viejos y esta migracion los renombra.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_solicitudes';

    /** nombre viejo => nombre nuevo. */
    private const RENOMBRES = [
        'centro_operacion' => 'cod_centro_operacion',
        'proyecto' => 'cod_proyecto',
    ];

    public function up(): void
    {
        $this->renombrar(self::RENOMBRES);
    }

    public function down(): void
    {
        $this->renombrar(array_flip(self::RENOMBRES));
    }

    /**
     * @param  array<string, string>  $pares  origen => destino
     */
    private function renombrar(array $pares): void
    {
        $existentes = $this->columnasExistentes();
        $pendientes = [];

        // Primero se valida todo; solo despues se escribe.
        foreach ($pares as $origen => $destino) {
            $hayOrigen = in_array($origen, $existentes, true);
            $hayDestino = in_array($destino, $existentes, true);

            if ($hayOrigen && $hayDestino) {
                throw new RuntimeException(
                    self::TABLA." tiene a la vez {$origen} y {$destino}. No se cambio nada: "
                    .'decida a mano cual conserva los datos y suelte la otra.'
                );
            }

            if (! $hayOrigen && ! $hayDestino) {
                throw new RuntimeException(
                    self::TABLA." no tiene ni {$origen} ni {$destino}. No se cambio nada: "
                    .'revise que la migracion 2026_09_26_100500 este aplicada.'
                );
            }

            if ($hayOrigen) {
                $pendientes[$origen] = $destino;
            }
        }

        foreach ($pendientes as $origen => $destino) {
            DB::statement('exec sp_rename ?, ?, ?', ['dbo.'.self::TABLA.'.'.$origen, $destino, 'COLUMN']);
        }
    }

    /**
     * Nombres (tal como los guarda el catalogo) de las columnas involucradas
     * que existen hoy.
     *
     * @return list<string>
     */
    private function columnasExistentes(): array
    {
        $nombres = [...array_keys(self::RENOMBRES), ...array_values(self::RENOMBRES)];
        $marcas = implode(', ', array_fill(0, count($nombres), '?'));

        $filas = DB::select(
            "select c.name as nombre
               from sys.columns c
              where c.object_id = object_id(?) and c.name in ({$marcas})",
            ['dbo.'.self::TABLA, ...$nombres]
        );

        return array_map(fn (object $fila) => $fila->nombre, $filas);
    }
};
