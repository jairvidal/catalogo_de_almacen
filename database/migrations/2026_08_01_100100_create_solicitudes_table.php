<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes', function (Blueprint $table) {
            $table->id();
            // Consecutivo visible para el solicitante (ej: SOL-2026-000012).
            $table->string('numero', 30)->unique();

            // Datos que el solicitante digita antes de enviar el pedido.
            $table->string('solicitante_nombre', 150);
            $table->string('solicitante_cedula', 30);
            $table->string('solicitante_email', 150);
            $table->string('solicitante_telefono', 30)->nullable();
            $table->string('solicitante_area', 100)->nullable();
            $table->string('observaciones', 1000)->nullable();

            // pendiente -> en_proceso -> listo -> entregado (o rechazada)
            $table->string('estado', 20)->default('pendiente');
            $table->string('nota_almacen', 1000)->nullable();

            $table->foreignId('atendida_por')->nullable()->constrained('users');
            $table->timestamp('fecha_en_proceso')->nullable();
            $table->timestamp('fecha_listo')->nullable();
            $table->timestamp('fecha_entrega')->nullable();

            // Trazabilidad del correo de aviso al solicitante.
            $table->timestamp('notificado_at')->nullable();
            $table->string('error_notificacion', 500)->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('solicitante_cedula');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes');
    }
};
