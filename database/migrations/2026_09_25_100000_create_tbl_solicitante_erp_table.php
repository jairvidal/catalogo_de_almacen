<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitantes del ERP: las personas que pueden pedir repuestos al almacen.
 *
 * Reemplaza a los datos que el visitante digitaba a mano (nombre, cedula,
 * correo, telefono, area) por una lista cerrada que viene del ERP. Hoy se
 * carga con `solicitantes:importar` desde un CSV; manana la llenara una
 * sincronizacion con la API del ERP por el mismo servicio de escritura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_solicitante_erp', function (Blueprint $table) {
            $table->id();
            // Identificador del empleado en el ERP. Es la clave por la que se
            // sincroniza (MERGE), asi que es unico y nunca se edita a mano.
            $table->string('col_codigo_erp', 30)->unique();
            // Mismos largos que las columnas de snapshot de `solicitudes`,
            // para que copiar el dato nunca lo trunque.
            $table->string('col_nombre', 150);
            // Nullable: el ERP puede no traerla. Sin cedula, las solicitudes
            // historicas (anteriores a la FK) no se le pueden casar.
            $table->string('col_cedula', 30)->nullable();
            // Nullable: sin correo la solicitud se crea igual y el aviso de
            // "pedido listo" queda registrado como fallido.
            $table->string('col_correo', 150)->nullable();
            $table->string('col_area', 100)->nullable();
            $table->string('col_telefono', 30)->nullable();
            $table->boolean('col_activo')->default(true);
            $table->timestamps();

            // El buscador del formulario filtra por activos y ordena por
            // nombre: el indice compuesto cubre las dos cosas.
            $table->index(['col_activo', 'col_nombre'], 'ix_tbl_solicitante_erp_activo_nombre');
            // Casa las solicitudes historicas en /consultar.
            $table->index('col_cedula', 'ix_tbl_solicitante_erp_cedula');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_solicitante_erp');
    }
};
