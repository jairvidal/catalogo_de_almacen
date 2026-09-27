<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tres tablas maestras del ERP con la misma forma: tbl_centro_operacion,
 * tbl_centro_de_costo y tbl_proyecto. Decisiones del usuario:
 *
 *  - Convencion de tablas nuevas: prefijo col_, salvo `id`.
 *  - La CLAVE PRIMARIA es el codigo, varchar(10) NOT NULL (varchar real: SQL
 *    nativo, $table->string() daria nvarchar), con nombre explicito
 *    pk_<tabla>.
 *  - `id` bigint IDENTITY NOT NULL pero NO es la PK: lleva indice unico
 *    uq_<tabla>_id para servir de referencia estable si algun dia hace falta.
 *  - col_nombre nvarchar(150) NOT NULL: es texto humano que llega del ERP
 *    (tildes, enie, signos) y las tablas hermanas usan nvarchar para el
 *    nombre; 150 es el largo de tbl_solicitante_erp.col_nombre y de
 *    solicitante_nombre. El codigo, en cambio, es un identificador y va en
 *    varchar para que una futura FK case con las columnas varchar de
 *    tbl_solicitudes / tbl_solicitud_detalle.
 *  - col_activo bit NOT NULL default 1 (anular != borrar), con indice como
 *    tbl_categoria y tbl_rol; created_at / updated_at datetime NULL como
 *    las hermanas.
 *
 * SIN FK desde tbl_solicitudes ni tbl_solicitud_detalle: sus longitudes
 * (varchar 50/100 en la cabecera, 5/10/100 en el detalle) no coinciden con
 * varchar(10) y el usuario lo decidira despues.
 *
 * Si alguna de las tres tablas ya existe, aborta ANTES de crear nada.
 */
return new class extends Migration
{
    /** tabla => columna codigo (PK). */
    private const TABLAS = [
        'tbl_centro_operacion' => 'col_cod_centro_operacion',
        'tbl_centro_de_costo' => 'col_cod_centro_de_costo',
        'tbl_proyecto' => 'col_cod_proyecto',
    ];

    public function up(): void
    {
        $existentes = array_values(array_filter(
            array_keys(self::TABLAS),
            fn (string $tabla) => DB::selectOne('select object_id(?, ?) as id', ['dbo.'.$tabla, 'U'])->id !== null
        ));

        if ($existentes !== []) {
            throw new RuntimeException(
                'Ya existen las tablas: '.implode(', ', $existentes).'. No se creo nada: revise su estructura a mano.'
            );
        }

        foreach (self::TABLAS as $tabla => $codigo) {
            DB::statement(
                "create table [{$tabla}] (
                    [id] bigint identity(1,1) not null,
                    [{$codigo}] varchar(10) not null,
                    [col_nombre] nvarchar(150) not null,
                    [col_activo] bit not null constraint [df_{$tabla}_col_activo] default 1,
                    [created_at] datetime null,
                    [updated_at] datetime null,
                    constraint [pk_{$tabla}] primary key ([{$codigo}])
                )"
            );
            DB::statement("create unique index [uq_{$tabla}_id] on [{$tabla}] ([id])");
            DB::statement("create index [{$tabla}_col_activo_index] on [{$tabla}] ([col_activo])");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::TABLAS)) as $tabla) {
            DB::statement("drop table if exists [{$tabla}]");
        }
    }
};
