<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repuestos', function (Blueprint $table) {
            // Nullable a proposito: un repuesto sin foto, o con una foto cuyo
            // marco no corresponde a ninguna categoria, no debe bloquear nada.
            // Convive con la columna de texto repuestos.categoria, que es otra
            // cosa (la linea generica del seeder) y se deja como estaba.
            $table->unsignedBigInteger('categoria_id')->nullable()->after('categoria');

            $table->foreign('categoria_id')
                ->references('id')
                ->on('tbl_categoria')
                ->nullOnDelete();

            $table->index('categoria_id');
        });
    }

    public function down(): void
    {
        Schema::table('repuestos', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
            $table->dropIndex(['categoria_id']);
            $table->dropColumn('categoria_id');
        });
    }
};
