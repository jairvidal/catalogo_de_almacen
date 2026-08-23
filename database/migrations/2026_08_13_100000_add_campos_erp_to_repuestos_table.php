<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sin efecto desde el 2026-08-22.
     *
     * Esta migracion agregaba stock, abastecimiento, tamano, subgrupo,
     * tiene_plano y plano_url al esquema anterior. La tabla se recreo con la
     * estructura del ERP y esas columnas o quedaron dentro de la migracion de
     * creacion con su nombre nuevo (stock, abastacimiento_alm, tamanio,
     * tiene_plano, url_plano) o desaparecieron (subgrupo, que hoy es
     * desc_cat_2).
     *
     * El archivo se conserva vacio y no se borra porque las instalaciones que
     * ya existen la tienen registrada en la tabla migrations; quitarla dejaria
     * esa fila apuntando a un archivo inexistente y confundiria a rollback.
     */
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
