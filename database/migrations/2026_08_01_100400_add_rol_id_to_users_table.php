<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 1 del cambio compatible: users.rol_id apunta a tbl_rol pero la columna
 * historica users.rol sigue existiendo y sigue siendo la que autentica. Ambas
 * conviven hasta que exista el CRUD de usuarios que escriba solo en rol_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->constrained('tbl_rol');
        });

        // Relleno por coincidencia entre el texto viejo y la clave del rol.
        DB::statement('
            UPDATE users
            SET rol_id = (SELECT r.id FROM tbl_rol r WHERE r.col_clave = users.rol)
            WHERE rol_id IS NULL
              AND EXISTS (SELECT 1 FROM tbl_rol r WHERE r.col_clave = users.rol)
        ');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['rol_id']);
            $table->dropColumn('rol_id');
        });
    }
};
