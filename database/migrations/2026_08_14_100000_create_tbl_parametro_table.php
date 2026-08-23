<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_parametro', function (Blueprint $table) {
            $table->id();
            // Clave del parametro. Es lo que pide el codigo por su nombre
            // (Parametro::valor('api.id_bod')), asi que es unica y estable.
            $table->string('col_nombre', 100)->unique();
            // NOT NULL con default '': un parametro sin valor todavia es un
            // parametro configurable, pero nunca un NULL que haya que testear
            // en cada lectura.
            $table->string('col_valor', 255)->default('');
            // Estado como texto y no como booleano por pedido explicito del
            // usuario. El CHECK de abajo es lo que impide que entre un tercer
            // valor por un script manual o por otro cliente de la base.
            $table->string('col_estado', 10)->default('activo');
            $table->string('col_descripcion', 255)->nullable();
            // Igual que en tbl_rol: los parametros sembrados sostienen la
            // sincronizacion con el ERP, no se renombran ni se anulan.
            $table->boolean('col_sistema')->default(false);
            $table->timestamps();

            $table->index('col_estado');
        });

        DB::statement("alter table [tbl_parametro] add constraint [ck_tbl_parametro_estado] check ([col_estado] in ('activo', 'inactivo'))");
    }

    public function down(): void
    {
        // El CHECK cae con la tabla, pero se suelta explicito para que el
        // rollback no dependa del orden interno de SQL Server.
        DB::statement('alter table [tbl_parametro] drop constraint if exists [ck_tbl_parametro_estado]');

        Schema::dropIfExists('tbl_parametro');
    }
};
