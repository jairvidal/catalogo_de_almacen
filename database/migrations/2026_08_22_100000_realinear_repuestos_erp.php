<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Realinea la tabla repuestos que ya existe con el esquema del ERP.
     *
     * La tabla se borro y se recreo a mano el 2026-08-21 (591 -> 28.490 filas)
     * sin pasar por una migracion, y de paso perdio el indice unico de codigo y
     * todos los indices secundarios. Las migraciones anteriores se reescribieron
     * para que una instalacion nueva ya nazca correcta, pero eso no toca a la
     * base que ya existe: eso lo hace esta.
     *
     * TODOS los pasos son idempotentes a proposito, para que corra igual sobre
     * la base actual (donde hay trabajo por hacer) y sobre una recien creada por
     * la migracion de creacion (donde no queda nada por hacer).
     */

    /** Columnas de cantidad que pasan a decimal(12,3) not null default 0. */
    private const CANTIDADES = ['stock', 'stock_minimo', 'stock_maximo', 'existencia'];

    /** Indices secundarios, nombre => columna. */
    private const INDICES = [
        'repuestos_nombre_index' => 'nombre',
        'repuestos_estado_index' => 'estado',
        'repuestos_id_categoria_index' => 'id_categoria',
    ];

    public function up(): void
    {
        foreach (self::CANTIDADES as $columna) {
            $this->ampliarCantidad($columna);
        }

        // Sin este indice, el Rule::unique de RepuestoRequest es una
        // comprobacion de carrera y el upsert por codigo no tiene clave de
        // conflicto. Verificado antes de escribirlo: 28.490 codigos distintos
        // sobre 28.490 filas, no hay duplicados que lo impidan.
        if (! $this->existeIndice('repuestos_codigo_unique')) {
            Schema::table('repuestos', function (Blueprint $table) {
                $table->unique('codigo');
            });
        }

        foreach (self::INDICES as $nombre => $columna) {
            if ($this->existeIndice($nombre) || ! Schema::hasColumn('repuestos', $columna)) {
                continue;
            }

            Schema::table('repuestos', function (Blueprint $table) use ($nombre, $columna) {
                $table->index($columna, $nombre);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDICES) as $nombre) {
            if ($this->existeIndice($nombre)) {
                Schema::table('repuestos', function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }

        if ($this->existeIndice('repuestos_codigo_unique')) {
            Schema::table('repuestos', function (Blueprint $table) {
                $table->dropUnique('repuestos_codigo_unique');
            });
        }

        // Volver a decimal(5,1) revienta si alguna cantidad ya paso de 9999.9.
        // Es justo el techo del que se sale esta migracion, asi que el rollback
        // solo es viable poco despues de aplicarla.
        foreach (self::CANTIDADES as $columna) {
            if (! Schema::hasColumn('repuestos', $columna)) {
                continue;
            }

            $this->soltarDefault($columna);
            DB::statement("alter table [repuestos] alter column [{$columna}] decimal(5, 1) null");
        }
    }

    /**
     * Lleva la columna a decimal(12,3) not null default 0.
     *
     * decimal(5,1) topaba en 9999.9 y el catalogo ya trae un stock_maximo de
     * 9600 en KG: el primer movimiento sobre 10.000 habria reventado con
     * arithmetic overflow. El not null es lo que sostiene el update condicionado
     * de SolicitudService::marcarListo(): con null, "existencia >= cantidad"
     * nunca es cierto y el despacho fallaria en silencio.
     */
    private function ampliarCantidad(string $columna): void
    {
        if (! Schema::hasColumn('repuestos', $columna)) {
            return;
        }

        $actual = DB::selectOne('
            select NUMERIC_PRECISION p, NUMERIC_SCALE s, IS_NULLABLE nul
            from INFORMATION_SCHEMA.COLUMNS
            where TABLE_NAME = ? and COLUMN_NAME = ?
        ', ['repuestos', $columna]);

        if ($actual === null) {
            return;
        }

        if ((int) $actual->p !== 12 || (int) $actual->s !== 3 || $actual->nul === 'YES') {
            // El not null exige que no queden nulos. 0 es el valor neutro y es
            // el mismo default que la columna llevaba en el esquema anterior.
            // El nombre de la columna sale de la constante de arriba, nunca de
            // fuera, asi que interpolarlo es seguro: un identificador no puede
            // ir como parametro enlazado.
            DB::update("update [repuestos] set [{$columna}] = 0 where [{$columna}] is null");
            DB::statement("alter table [repuestos] alter column [{$columna}] decimal(12, 3) not null");
        }

        $this->asegurarDefaultCero($columna);
    }

    private function asegurarDefaultCero(string $columna): void
    {
        if ($this->nombreDelDefault($columna) !== null) {
            return;
        }

        DB::statement("alter table [repuestos] add constraint [df_repuestos_{$columna}] default 0 for [{$columna}]");
    }

    private function soltarDefault(string $columna): void
    {
        $nombre = $this->nombreDelDefault($columna);

        if ($nombre !== null) {
            DB::statement("alter table [repuestos] drop constraint [{$nombre}]");
        }
    }

    /**
     * SQL Server le pone nombre automatico a los defaults (DF__repuestos__...),
     * asi que hay que preguntarle cual es en vez de suponerlo.
     */
    private function nombreDelDefault(string $columna): ?string
    {
        $fila = DB::selectOne('
            select dc.name
            from sys.default_constraints dc
            join sys.columns c on c.object_id = dc.parent_object_id and c.column_id = dc.parent_column_id
            where dc.parent_object_id = object_id(?) and c.name = ?
        ', ['repuestos', $columna]);

        return $fila->name ?? null;
    }

    private function existeIndice(string $nombre): bool
    {
        return DB::selectOne('
            select top 1 name from sys.indexes
            where object_id = object_id(?) and name = ?
        ', ['repuestos', $nombre]) !== null;
    }
};
