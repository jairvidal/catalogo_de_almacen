<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repuestos', function (Blueprint $table) {
            $table->id();
            // Codigo del item en el almacen. Es lo que el usuario busca y lo que
            // relaciona el registro con el archivo de public/img.
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 200);
            $table->string('descripcion', 500)->nullable();
            $table->string('categoria', 100)->nullable();
            $table->string('ubicacion', 100)->nullable();
            $table->string('unidad_medida', 20)->default('UND');
            // Nombre del archivo dentro de public/img (ej: 0003729.jpg).
            $table->string('foto', 255)->nullable();
            $table->integer('cantidad_disponible')->default(0);
            $table->integer('stock_minimo')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repuestos');
    }
};
