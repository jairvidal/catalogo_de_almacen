<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funcionalidades del panel sobre las que se conceden permisos por rol
 * (modulo "Funciones por perfil", ver App\Services\PermisoService).
 *
 * La tabla nace vacia a proposito: la llena FuncionalidadSeeder. Mientras un
 * rol no tenga su matriz guardada, User::puede() le aplica el permiso heredado
 * de col_gestiona_catalogo, asi que correr solo `migrate` no deja a nadie por
 * fuera del panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_funcionalidad', function (Blueprint $table) {
            $table->id();
            // Clave estable con la que el codigo pide el permiso
            // (middleware permiso:repuestos,editar). No se edita desde el panel.
            $table->string('col_clave', 60)->unique();
            $table->string('col_nombre', 80);
            // Encabezado con el que se agrupan las tarjetas (CATALOGOS, ...).
            $table->string('col_seccion', 60);
            // Nombre del icono de Bootstrap Icons sin el prefijo "bi-".
            $table->string('col_icono', 60);
            $table->integer('col_orden')->default(0);
            $table->boolean('col_activo')->default(true);
            $table->timestamps();

            $table->index(['col_activo', 'col_orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_funcionalidad');
    }
};
