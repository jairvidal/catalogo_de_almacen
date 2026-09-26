<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso del solicitante del ERP al portal de aprobacion (guard `solicitante`).
 *
 * El usuario es col_correo, que ya existe; aqui van las credenciales y su
 * trazabilidad. Todas nullable: nadie tiene contrasena hasta que el
 * administrador se la asigna desde /admin/solicitantes.
 *
 * ImportadorSolicitantesErp hace MERGE sobre esta tabla y NO nombra ninguna de
 * estas columnas, ni en el UPDATE ni en el INSERT: reimportar el CSV no borra ni
 * pisa una contrasena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_solicitante_erp', function (Blueprint $table) {
            // Solo el hash (bcrypt por Hash::make). 255 por si cambia el algoritmo.
            $table->string('col_password', 255)->nullable();
            $table->string('col_remember_token', 100)->nullable();
            // Cuando el administrador la asigno o restablecio por ultima vez.
            $table->dateTime('col_password_asignada_at')->nullable();
            // Cuando la persona la cambio desde el portal.
            $table->dateTime('col_password_cambiada_at')->nullable();
            $table->dateTime('col_ultimo_ingreso_at')->nullable();

            // El ingreso busca por correo entre los activos.
            $table->index(['col_correo', 'col_activo'], 'ix_tbl_solicitante_erp_correo_activo');
        });

        // Invariante que usa el listado del panel para decir "tiene contrasena"
        // sin leer el hash: las dos columnas son nulas o no nulas a la vez.
        DB::statement(
            'alter table [tbl_solicitante_erp] add constraint [ck_tbl_solicitante_erp_password_asignada]
             check (([col_password] is null and [col_password_asignada_at] is null)
                 or ([col_password] is not null and [col_password_asignada_at] is not null))'
        );
    }

    public function down(): void
    {
        DB::statement('alter table [tbl_solicitante_erp] drop constraint [ck_tbl_solicitante_erp_password_asignada]');

        Schema::table('tbl_solicitante_erp', function (Blueprint $table) {
            $table->dropIndex('ix_tbl_solicitante_erp_correo_activo');
            $table->dropColumn([
                'col_password',
                'col_remember_token',
                'col_password_asignada_at',
                'col_password_cambiada_at',
                'col_ultimo_ingreso_at',
            ]);
        });
    }
};
