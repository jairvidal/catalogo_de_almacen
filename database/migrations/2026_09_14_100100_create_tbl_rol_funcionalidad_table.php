<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de permisos: una fila por rol y funcionalidad con las tres acciones.
 *
 * Las llaves foraneas llevan el prefijo col_ porque la tabla es nueva y sigue
 * la convencion completa; el "sin prefijo" de CLAUDE.md aplica solo a las FK
 * que se agregan a las tablas anteriores a la convencion (users.rol_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_rol_funcionalidad', function (Blueprint $table) {
            $table->id();
            // Sin cascada: los roles y las funcionalidades se anulan, no se borran.
            $table->foreignId('col_rol_id')->constrained('tbl_rol');
            $table->foreignId('col_funcionalidad_id')->constrained('tbl_funcionalidad');
            $table->boolean('col_ver')->default(false);
            $table->boolean('col_editar')->default(false);
            $table->boolean('col_eliminar')->default(false);
            $table->timestamps();

            // Garantia ante dos guardados simultaneos de la misma matriz y, por
            // ir col_rol_id primero, el indice con el que se leen los permisos
            // de un rol en cada peticion.
            $table->unique(['col_rol_id', 'col_funcionalidad_id'], 'uq_tbl_rol_funcionalidad_rol_funcionalidad');
            $table->index('col_funcionalidad_id');
        });

        // Editar o eliminar sin ver no tiene sentido (no se edita lo que no se
        // puede abrir). PermisoService lo normaliza; el CHECK es la ultima
        // linea de defensa ante un script manual u otro cliente de la base.
        DB::statement('alter table [tbl_rol_funcionalidad] add constraint [ck_tbl_rol_funcionalidad_ver] '
            .'check ([col_ver] = 1 or ([col_editar] = 0 and [col_eliminar] = 0))');
    }

    public function down(): void
    {
        DB::statement('alter table [tbl_rol_funcionalidad] drop constraint if exists [ck_tbl_rol_funcionalidad_ver]');

        Schema::dropIfExists('tbl_rol_funcionalidad');
    }
};
