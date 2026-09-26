<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra la tabla `solicitudes` a `tbl_solicitudes`.
 *
 * Solo cambia el nombre de la tabla: las columnas conservan su nombre sin
 * prefijo col_ y `solicitud_items` no se toca.
 *
 * sp_rename conserva datos, identidad, indices, CHECK y FK (las que salen y
 * las que llegan, porque SQL Server las enlaza por object_id), pero los
 * NOMBRES de esas restricciones seguirian diciendo `solicitudes_...`. Se
 * renombran tambien, por dos razones:
 *  - coherencia: `ck_tbl_solicitudes_estado` dice de que tabla es;
 *  - Laravel deriva el nombre de un indice o FK del nombre ACTUAL de la tabla
 *    (`$table->dropIndex(['estado'])` busca `tbl_solicitudes_estado_index`),
 *    asi que una migracion futura fallaria con los nombres viejos.
 *
 * Los nombres se leen del catalogo y no de una lista fija, porque las bases
 * no son identicas: en la de desarrollo, por ejemplo, no existen las FK
 * `solicitudes_solicitante_erp_id_foreign` ni la de `solicitud_items`, aunque
 * sus migraciones las crean. Solo se renombra lo que empieza por el prefijo
 * viejo; los nombres que genero SQL Server (PK__solicitu__..., DF__solicitud__...)
 * no los nombra nadie y se dejan como estan.
 *
 * La FK de `solicitud_items.solicitud_id` se llama `solicitud_items_...`: sigue
 * apuntando a la tabla renombrada y su nombre no cambia.
 *
 * down() deshace los renombres en orden inverso, de modo que el down() de las
 * migraciones anteriores (que nombran `[solicitudes]` y `ck_solicitudes_*`)
 * sigue funcionando. El migrador corre todo dentro de una transaccion y en
 * SQL Server el DDL es transaccional: si un sp_rename falla no queda a medias.
 */
return new class extends Migration
{
    private const TABLA_VIEJA = 'solicitudes';

    private const TABLA_NUEVA = 'tbl_solicitudes';

    /** Prefijo viejo => prefijo nuevo de los nombres de indices y restricciones. */
    private const PREFIJOS = [
        'ck_solicitudes_' => 'ck_tbl_solicitudes_',
        'solicitudes_' => 'tbl_solicitudes_',
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

        // Indices sueltos (incluido el unico de `numero`, que Laravel crea como
        // indice y no como restriccion UNIQUE). La PK y los indices que
        // respaldan una restriccion UNIQUE se renombran por la restriccion.
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
