<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indice visual de las fotos de public/img para la busqueda por imagen del
 * catalogo (comando repuestos:indexar-imagenes).
 *
 * Una fila por ARCHIVO, no por repuesto: la carpeta tiene fotos alternas
 * (_v2), codigos con dos extensiones y archivos sin codigo, y no es un espejo
 * del catalogo. El repuesto se resuelve al consultar (repuestos.foto o el
 * codigo del nombre del archivo), para que el indice no quede viejo cuando
 * cambia el catalogo.
 *
 *  - col_archivo nvarchar(255): nombre dentro de public/img, unico.
 *  - col_hash char(64): sha256 del archivo; si cambia, se vuelve a indexar.
 *  - col_codigo int NULL: digitos iniciales del nombre (0003729_v2.png -> 3729),
 *    mismo tipo que repuestos.codigo. Sin FK: hay archivos sin repuesto.
 *  - col_descripcion nvarchar(1000): descripcion visual que genero Claude.
 *  - col_modelo varchar(60): modelo que la genero.
 *
 * Es un dato DERIVADO (se reconstruye con --forzar), no historico: el comando
 * borra las filas cuyo archivo ya no existe.
 *
 * Si la tabla ya existe, aborta ANTES de crear nada.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_imagen_indice';

    public function up(): void
    {
        if (DB::selectOne('select object_id(?, ?) as id', ['dbo.'.self::TABLA, 'U'])->id !== null) {
            throw new RuntimeException('Ya existe la tabla '.self::TABLA.'. No se creo nada: revise su estructura a mano.');
        }

        DB::statement(
            'create table [tbl_imagen_indice] (
                [id] bigint identity(1,1) not null constraint [pk_tbl_imagen_indice] primary key,
                [col_archivo] nvarchar(255) not null,
                [col_hash] char(64) not null,
                [col_codigo] int null,
                [col_descripcion] nvarchar(1000) not null,
                [col_modelo] varchar(60) not null,
                [col_indexado_at] datetime not null,
                [created_at] datetime null,
                [updated_at] datetime null
            )'
        );
        DB::statement('create unique index [uq_tbl_imagen_indice_col_archivo] on [tbl_imagen_indice] ([col_archivo])');
        DB::statement('create index [ix_tbl_imagen_indice_col_codigo] on [tbl_imagen_indice] ([col_codigo])');
    }

    public function down(): void
    {
        DB::statement('drop table if exists [tbl_imagen_indice]');
    }
};
