<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogo de repuestos con la estructura que trae el ERP.
     *
     * OJO: esta migracion se reescribio el 2026-08-22. La tabla se habia
     * recreado a mano con el esquema del ERP y el repositorio seguia
     * describiendo el esquema viejo (codigo nvarchar, cantidad_disponible,
     * activo, descripcion, categoria de texto), asi que una instalacion nueva
     * producia una tabla distinta de la real. Ahora esta describe la unica
     * estructura valida.
     *
     * Como ya estaba aplicada, reescribirla NO alcanza a las instalaciones
     * existentes: de eso se encarga 2026_08_22_100000_realinear_repuestos_erp,
     * que hace idempotente el mismo resultado sobre la tabla que ya existe.
     */
    public function up(): void
    {
        Schema::create('repuestos', function (Blueprint $table) {
            $table->id();

            // Codigo del item en el ERP. Es entero: ya no guarda ceros a la
            // izquierda, asi que el cruce con la API de inventario es directo.
            $table->integer('codigo')->unique();

            // Referencia comercial alterna. NO es el codigo con otro formato:
            // hay 533 filas donde los dos valores difieren de verdad y 178 que
            // ni siquiera son numericas (ELECT11, A0001310).
            $table->string('cod_referencia', 50)->nullable();

            $table->string('nombre', 300)->nullable();
            $table->string('unidad_medida', 10)->nullable();
            $table->string('ubicacion', 30)->nullable();

            // Taxonomia del ERP: cat_1 es el GRUPO y cat_2 el SUBGRUPO. Son los
            // mismos criterios que filtran la consulta de inventario.
            $table->string('cod_cat_1', 6)->nullable();
            $table->string('desc_cat_1', 50)->nullable();
            $table->string('cod_cat_2', 6)->nullable();
            $table->string('desc_cat_2', 50)->nullable();

            // Cantidades en decimal(12,3): el almacen mide en KG y hay items
            // con fraccion. decimal(5,1) topaba en 9999.9 y ya existe un
            // stock_maximo de 9600, asi que el techo estaba a un paso.
            //
            // stock      = existencia que reporta el ERP; la escribe la API.
            // existencia = saldo operativo del sistema (el antiguo
            //              cantidad_disponible): lo topa el Carrito y lo
            //              descuenta SolicitudService::marcarListo().
            // La sincronizacion escribe UNICAMENTE stock y jamas existencia.
            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('stock_minimo', 12, 3)->default(0);
            $table->decimal('stock_maximo', 12, 3)->default(0);
            $table->decimal('existencia', 12, 3)->default(0);

            // Banderas 0/1. El nombre de la primera trae el typo del ERP y se
            // respeta tal cual para no divergir de la tabla real.
            $table->boolean('abastacimiento_alm')->nullable();
            $table->boolean('tiene_plano')->nullable();
            $table->string('url_plano', 50)->nullable();
            $table->string('tamanio', 20)->nullable();

            // La tabla no usa los timestamps de Laravel: trae sus propias
            // marcas con default getdate(). Ver Repuesto::CREATED_AT.
            $table->dateTime('fecha_creacion')->nullable()->useCurrent();
            $table->dateTime('fecha_actualizacion')->nullable()->useCurrent();

            // Categoria por color de marco (tbl_categoria). La FK se agrega en
            // 2026_08_04_200100 porque tbl_categoria se crea despues.
            $table->unsignedBigInteger('id_categoria')->nullable();

            $table->boolean('tiene_foto')->nullable()->default(true);
            // Nombre del archivo dentro de public/img (ej: 0003729.jpg).
            $table->string('foto', 50)->nullable();

            // 1 = visible en el catalogo, 0 = oculto. Reemplaza al antiguo
            // booleano activo; destroy pone 0 en vez de borrar la fila.
            $table->integer('estado')->nullable()->default(1);

            $table->index('nombre');
            $table->index('estado');
            $table->index('id_categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repuestos');
    }
};
