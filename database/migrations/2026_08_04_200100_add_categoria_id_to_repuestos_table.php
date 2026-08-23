<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaza repuestos con tbl_categoria (la categoria por color de marco).
     *
     * Reescrita el 2026-08-22: la columna paso a llamarse id_categoria y ahora
     * la declara la migracion de creacion, porque asi viene la tabla del ERP.
     * Aqui solo queda la FK, que no puede vivir en el create: tbl_categoria se
     * crea en 2026_08_04_200000, despues de repuestos.
     */
    public function up(): void
    {
        // Nullable a proposito: un repuesto sin foto, o con una foto cuyo marco
        // no corresponde a ninguna categoria, no debe bloquear nada.
        if (! Schema::hasColumn('repuestos', 'id_categoria')) {
            return;
        }

        if ($this->existeForanea()) {
            return;
        }

        Schema::table('repuestos', function (Blueprint $table) {
            $table->foreign('id_categoria')
                ->references('id')
                ->on('tbl_categoria')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->existeForanea()) {
            return;
        }

        Schema::table('repuestos', function (Blueprint $table) {
            $table->dropForeign(['id_categoria']);
        });
    }

    /**
     * La base que ya existe trae la FK creada a mano como FK_repuestos_categoria,
     * asi que no basta con buscar el nombre que genera Laravel: se pregunta por
     * la columna.
     */
    private function existeForanea(): bool
    {
        return DB::selectOne("
            select top 1 fk.name
            from sys.foreign_keys fk
            join sys.foreign_key_columns fkc on fkc.constraint_object_id = fk.object_id
            join sys.columns c on c.object_id = fkc.parent_object_id and c.column_id = fkc.parent_column_id
            where fk.parent_object_id = object_id('repuestos') and c.name = 'id_categoria'
        ") !== null;
    }
};
