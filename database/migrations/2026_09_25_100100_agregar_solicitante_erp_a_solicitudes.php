<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La solicitud pasa a apuntar al solicitante del ERP.
 *
 * - `solicitante_erp_id`: FK nullable y SIN prefijo col_, porque `solicitudes`
 *   es anterior a la convencion (igual que `users.rol_id`). Nullable porque las
 *   solicitudes historicas no tienen a quien apuntar.
 * - `solicitante_cedula` y `solicitante_email` quedan NULLABLE: siguen siendo el
 *   snapshot de lo que traia el ERP al crear la solicitud, y el ERP puede no
 *   traer cedula o correo. No se borra ninguna columna: el panel, sus filtros y
 *   los correos las siguen leyendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes', function (Blueprint $table) {
            $table->foreignId('solicitante_erp_id')
                ->nullable()
                ->after('numero')
                ->constrained('tbl_solicitante_erp');

            $table->index('solicitante_erp_id');
        });

        // SQL Server permite cambiar la nulabilidad de una columna indexada
        // (solicitante_cedula tiene indice) mientras no cambie el tipo ni se
        // achique el largo; por eso se repite el mismo nvarchar.
        DB::statement('alter table [solicitudes] alter column [solicitante_cedula] nvarchar(30) null');
        DB::statement('alter table [solicitudes] alter column [solicitante_email] nvarchar(150) null');
    }

    public function down(): void
    {
        // Volver a NOT NULL exige que no quede ningun nulo: se rellenan con
        // cadena vacia, que es lo menos malo para un rollback.
        DB::table('solicitudes')->whereNull('solicitante_cedula')->update(['solicitante_cedula' => '']);
        DB::table('solicitudes')->whereNull('solicitante_email')->update(['solicitante_email' => '']);

        // A la inversa de up(), pasar a NOT NULL una columna indexada SI esta
        // prohibido en SQL Server: se suelta el indice y se rehace.
        Schema::table('solicitudes', fn (Blueprint $table) => $table->dropIndex(['solicitante_cedula']));
        DB::statement('alter table [solicitudes] alter column [solicitante_cedula] nvarchar(30) not null');
        Schema::table('solicitudes', fn (Blueprint $table) => $table->index('solicitante_cedula'));
        DB::statement('alter table [solicitudes] alter column [solicitante_email] nvarchar(150) not null');

        Schema::table('solicitudes', function (Blueprint $table) {
            $table->dropForeign(['solicitante_erp_id']);
            $table->dropIndex(['solicitante_erp_id']);
            $table->dropColumn('solicitante_erp_id');
        });
    }
};
