<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_categoria', function (Blueprint $table) {
            $table->id();
            // Nombre visible. El administrador lo cambia desde el panel sin que
            // eso afecte la clasificacion automatica, que se apoya en col_slug.
            $table->string('col_nombre', 60);
            // Clave estable e inmutable. Es la que amarra el color detectado en
            // el marco de la foto con el registro (ver DetectorColorMarco).
            $table->string('col_slug', 60)->unique();
            // Color del marco de la foto que define la categoria (ej: #D81818).
            $table->string('col_color_hex', 7);
            $table->string('col_descripcion', 255)->nullable();
            $table->boolean('col_activo')->default(true);
            $table->timestamps();

            $table->index('col_activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_categoria');
    }
};
