<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra la tabla `solicitud_items` a `tbl_solicitud_detalle`.
 *
 * Solo cambia el nombre de la tabla: las columnas (solicitud_id, repuesto_id,
 * codigo, nombre, foto, cantidad...) conservan su nombre sin prefijo col_, y
 * el modelo SolicitudItem y la relacion Solicitud::items() no se renombran.
 *
 * Mismo criterio que 2026_09_26_100200 (tbl_solicitudes): sp_rename conserva
 * datos, identidad, indices y FK, y ademas se renombran los indices y
 * restricciones cuyo nombre empieza por `solicitud_items_` a
 * `tbl_solicitud_detalle_`, porque Laravel deriva esos nombres del nombre
 * ACTUAL de la tabla y una migracion futura no los encontraria.
 *
 * Los nombres se leen del catalogo y no de una lista fija: en la base de
 * desarrollo solo existe `solicitud_items_solicitud_id_index`; las FK
 * `solicitud_items_solicitud_id_foreign` y `solicitud_items_repuesto_id_foreign`
 * que crea la migracion original no estan. Donde existan se renombran; aqui no
 * se crean. Los nombres generados por SQL Server (PK__solicitu__...) se dejan.
 *
 * El codigo del renombre se repite a proposito en vez de compartirse con la
 * migracion de tbl_solicitudes: una migracion ya ejecutada tiene que seguir
 * haciendo exactamente lo mismo aunque el codigo compartido cambie despues.
 *
 * down() deshace los renombres en orden inverso. El migrador corre todo
 * dentro de una transaccion y en SQL Server el DDL es transaccional.
 */
return new class extends Migration
{
    private const TABLA_VIEJA = 'solicitud_items';

    private const TABLA_NUEVA = 'tbl_solicitud_detalle';

    /** Prefijo viejo => prefijo nuevo de los nombres de indices y restricciones. */
    private const PREFIJOS = [
        'ck_solicitud_items_' => 'ck_tbl_solicitud_detalle_',
        'solicitud_items_' => 'tbl_solicitud_detalle_',
    ];

    public function up(): void
    {
        $this->exigirTablas(existe: self::TABLA_VIEJA, noExiste: self::TABLA_NUEVA);

        Schema::rename(self::TABLA_VIEJA, self::TABLA_NUEVA);

        $this->renombrarDependientes(self::PREFIJOS);
    }

    public function down(): void
    {
        $this->exigirTablas(existe: self::TABLA_NUEVA, noExiste: self::TABLA_VIEJA);

        $this->renombrarDependientes(array_flip(self::PREFIJOS));

        Schema::rename(self::TABLA_NUEVA, self::TABLA_VIEJA);
    }

    /**
     * Falla temprano y con mensaje claro antes de tocar nada.
     */
    private function exigirTablas(string $existe, string $noExiste): void
    {
        if (! Schema::hasTable($existe)) {
            throw new RuntimeException("No existe la tabla [{$existe}]: no hay nada que renombrar.");
        }

        if (Schema::hasTable($noExiste)) {
            throw new RuntimeException(
                "Ya existe la tabla [{$noExiste}]. Revise a mano cual de las dos tiene los datos antes de migrar."
            );
        }
    }

    /**
     * Renombra los indices y restricciones de la tabla (ya con su nombre
     * nuevo o viejo, segun el sentido) cuyo nombre empiece por un prefijo de
     * $prefijos. El orden del arreglo importa: el prefijo mas largo va primero.
     *
     * @param  array<string, string>  $prefijos
     */
    private function renombrarDependientes(array $prefijos): void
    {
        $tabla = Schema::hasTable(self::TABLA_NUEVA) ? self::TABLA_NUEVA : self::TABLA_VIEJA;

        // Indices sueltos. La PK y los indices que respaldan una restriccion
        // UNIQUE se renombran por la restriccion.
        $indices = DB::select(
            'select i.name as nombre
               from sys.indexes i
              where i.object_id = object_id(?)
                and i.name is not null
                and i.is_primary_key = 0
                and i.is_unique_constraint = 0',
            ['dbo.'.$tabla]
        );

        foreach ($indices as $indice) {
            $nuevo = $this->nombreNuevo($indice->nombre, $prefijos);

            if ($nuevo !== null) {
                DB::statement('exec sp_rename ?, ?, ?', ["dbo.{$tabla}.{$indice->nombre}", $nuevo, 'INDEX']);
            }
        }

        // CHECK, FK y UNIQUE declarados como restriccion sobre la tabla.
        $restricciones = DB::select(
            "select o.name as nombre
               from sys.objects o
              where o.parent_object_id = object_id(?)
                and o.type in ('C', 'F', 'UQ')",
            ['dbo.'.$tabla]
        );

        foreach ($restricciones as $restriccion) {
            $nuevo = $this->nombreNuevo($restriccion->nombre, $prefijos);

            if ($nuevo !== null) {
                DB::statement('exec sp_rename ?, ?, ?', ["dbo.{$restriccion->nombre}", $nuevo, 'OBJECT']);
            }
        }
    }

    /**
     * @param  array<string, string>  $prefijos
     */
    private function nombreNuevo(string $nombre, array $prefijos): ?string
    {
        foreach ($prefijos as $viejo => $nuevo) {
            if (str_starts_with($nombre, $viejo)) {
                return $nuevo.substr($nombre, strlen($viejo));
            }
        }

        return null;
    }
};
