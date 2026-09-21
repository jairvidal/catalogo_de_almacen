<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega repuestos.fecha_actual_ERP: cuando el ERP confirmo por ultima vez
     * la existencia de ese repuesto durante la sincronizacion de inventario.
     *
     * POR QUE HACE FALTA UNA COLUMNA NUEVA: hasta ahora el unico rastro de la
     * sincronizacion era fecha_actualizacion, que es el UPDATED_AT del modelo
     * (ver App\Models\Repuesto) y por tanto se movia tanto cuando una persona
     * editaba el repuesto en el panel como cuando el ERP le rozaba el stock.
     * Con las dos cosas en la misma columna era imposible responder ni "quien
     * toco esto" ni "hace cuanto que el ERP dejo de reportar este item". Aqui se
     * separan: fecha_actual_ERP la escribe SOLO la sincronizacion y
     * fecha_actualizacion vuelve a ser SOLO la edicion desde el panel.
     *
     * EL NOMBRE VA CON "ERP" EN MAYUSCULAS A PETICION EXPLICITA DEL USUARIO,
     * aunque el resto de la tabla vaya en minusculas. En SQL Server el
     * identificador no distingue mayusculas de minusculas —da igual como se
     * escriba en el SQL—, pero Eloquent SI es sensible al nombre exacto cuando
     * se lee el atributo o se declara el cast, porque el driver devuelve la
     * clave del arreglo tal como esta declarada la columna. Cambiarle la caja
     * aqui obligaria a cambiarla tambien en el modelo, el servicio y las vistas.
     *
     * NULLABLE A PROPOSITO: las 28.490 filas que ya existen nunca han recibido
     * confirmacion del ERP, y NULL dice exactamente eso ("el ERP todavia no ha
     * confirmado este item"). Rellenarlas con la fecha de la migracion, o con
     * fecha_actualizacion, seria inventar una confirmacion que nunca ocurrio y
     * el dato naceria mintiendo.
     */
    public function up(): void
    {
        if (Schema::hasColumn('repuestos', 'fecha_actual_ERP')) {
            return;
        }

        Schema::table('repuestos', function (Blueprint $table) {
            // Sin ->after(): SQL Server no admite reordenar columnas al vuelo.
            $table->dateTime('fecha_actual_ERP')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('repuestos', 'fecha_actual_ERP')) {
            return;
        }

        Schema::table('repuestos', function (Blueprint $table) {
            $table->dropColumn('fecha_actual_ERP');
        });
    }
};
