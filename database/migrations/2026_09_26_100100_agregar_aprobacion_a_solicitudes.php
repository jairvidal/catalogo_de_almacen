<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobacion de la solicitud por el solicitante del ERP (estado por_aprobar).
 *
 * Sin prefijo col_ porque `solicitudes` es anterior a la convencion. Las
 * solicitudes HISTORICAS quedan con las dos fechas en NULL y conservan su
 * estado: se consideran aprobadas, no se migran a por_aprobar.
 *
 * Se agregan dos CHECK como ultima linea de defensa:
 *  - estado solo admite los seis valores de Solicitud::ESTADOS;
 *  - una solicitud no puede estar aprobada y denegada a la vez.
 */
return new class extends Migration
{
    private const ESTADOS = ['por_aprobar', 'pendiente', 'en_proceso', 'listo', 'entregada', 'rechazada'];

    public function up(): void
    {
        Schema::table('solicitudes', function (Blueprint $table) {
            $table->dateTime('aprobada_at')->nullable()->after('estado');
            $table->dateTime('denegada_at')->nullable()->after('aprobada_at');
            $table->string('motivo_denegacion', 1000)->nullable()->after('denegada_at');
        });

        $valores = implode(', ', array_map(fn (string $estado) => "'{$estado}'", self::ESTADOS));

        DB::statement("alter table [solicitudes] add constraint [ck_solicitudes_estado] check ([estado] in ({$valores}))");
        DB::statement(
            'alter table [solicitudes] add constraint [ck_solicitudes_una_decision]
             check ([aprobada_at] is null or [denegada_at] is null)'
        );
    }

    public function down(): void
    {
        // Una solicitud por_aprobar no tiene a donde volver sin el estado nuevo:
        // se deja como rechazada, que es lo que no llega al almacen.
        DB::table('solicitudes')->where('estado', 'por_aprobar')->update(['estado' => 'rechazada']);

        DB::statement('alter table [solicitudes] drop constraint [ck_solicitudes_una_decision]');
        DB::statement('alter table [solicitudes] drop constraint [ck_solicitudes_estado]');

        Schema::table('solicitudes', function (Blueprint $table) {
            $table->dropColumn(['aprobada_at', 'denegada_at', 'motivo_denegacion']);
        });
    }
};
