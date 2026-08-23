<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pone estado = 1 en los repuestos que la carga del ERP dejo en NULL.
     *
     * La columna tiene default 1, pero la carga masiva escribio NULL explicito
     * en las 28.490 filas y el default no alcanza a un valor que si viene en el
     * INSERT. Como scopeActivos() filtra por estado = 1, el catalogo publico
     * quedaba mostrando cero repuestos.
     *
     * Solo toca los nulos: un repuesto que el administrador haya anulado a mano
     * (estado = 0) no debe volver a aparecer en el catalogo.
     */
    public function up(): void
    {
        if (! Schema::hasTable('repuestos') || ! Schema::hasColumn('repuestos', 'estado')) {
            return;
        }

        $afectados = DB::update('update [repuestos] set [estado] = 1 where [estado] is null');

        if ($afectados > 0) {
            info("Repuestos con estado NULL puestos en activo: {$afectados}.");
        }
    }

    /**
     * Sin vuelta atras: no hay forma de distinguir cuales estaban en NULL antes,
     * y devolverlos todos a NULL apagaria el catalogo entero.
     */
    public function down(): void
    {
        //
    }
};
