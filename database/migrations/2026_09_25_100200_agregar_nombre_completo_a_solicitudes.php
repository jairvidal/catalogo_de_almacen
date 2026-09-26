<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nombre completo" que digita la persona en el formulario publico, aparte del
 * solicitante elegido de la lista del ERP.
 *
 * NO se mezcla con `solicitante_nombre`: esa columna sigue siendo la copia del
 * nombre del ERP y la usan filtros, orden, correos y /consultar. Nullable
 * porque las solicitudes historicas no lo tienen. Sin prefijo col_ por ser
 * tabla anterior a la convencion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes', function (Blueprint $table) {
            $table->string('nombre_completo', 150)->nullable()->after('solicitante_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes', function (Blueprint $table) {
            $table->dropColumn('nombre_completo');
        });
    }
};
