<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $table->foreignId('repuesto_id')->constrained('repuestos');

            // Copia del item en el momento del pedido: si luego se corrige el
            // nombre o se cambia la foto, el historico de la solicitud no cambia.
            $table->string('codigo', 40);
            $table->string('nombre', 200);
            $table->string('foto', 255)->nullable();

            $table->integer('cantidad_solicitada');
            $table->integer('cantidad_entregada')->nullable();
            $table->string('nota', 500)->nullable();
            $table->timestamps();

            $table->index('solicitud_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_items');
    }
};
