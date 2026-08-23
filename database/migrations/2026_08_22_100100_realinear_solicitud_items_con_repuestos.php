<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repunta solicitud_items.repuesto_id despues de la recarga del ERP.
     *
     * Al recrear repuestos se reasignaron los id autoincrementales, asi que los
     * items historicos quedaron apuntando a repuestos que no tienen nada que ver
     * con lo que se pidio: el item del "Acople flexible 1 HP" apuntaba a un rele
     * termico. El snapshot (codigo, nombre, foto) salvo lo que se ve en pantalla,
     * pero SolicitudService::marcarListo() descuenta por la relacion, asi que un
     * despacho habria movido la existencia del repuesto equivocado.
     *
     * El cruce es por el codigo del snapshot, que se guardo con ceros a la
     * izquierda ('0003736') mientras repuestos.codigo hoy es entero: por eso el
     * try_cast. Es la misma regla del resto del proyecto, la conversion solo
     * sirve para emparejar.
     */
    public function up(): void
    {
        if (! Schema::hasTable('solicitud_items') || ! Schema::hasTable('repuestos')) {
            return;
        }

        // try_cast y no cast: un codigo de snapshot no numerico devuelve null y
        // el join simplemente no encuentra pareja, en vez de reventar la
        // migracion entera.
        $afectados = DB::update('
            update si
            set si.repuesto_id = r.id
            from [solicitud_items] si
            inner join [repuestos] r on r.codigo = try_cast(si.codigo as int)
            where si.repuesto_id <> r.id
        ');

        if ($afectados > 0) {
            info("Realineados {$afectados} solicitud_items con el catalogo del ERP.");
        }
    }

    /**
     * No hay vuelta atras: los repuesto_id anteriores apuntaban a filas de un
     * catalogo que ya no existe, asi que no hay nada correcto que restituir.
     */
    public function down(): void
    {
        //
    }
};
