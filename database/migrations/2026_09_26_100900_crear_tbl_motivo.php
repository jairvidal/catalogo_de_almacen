<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Maestra del ERP tbl_motivo, con la misma forma que las de
 * 2026_09_26_100800 (tbl_centro_operacion, tbl_centro_de_costo, tbl_proyecto):
 *
 *  - PK = col_cod_motivo varchar(5) NOT NULL (varchar real: SQL nativo), con
 *    nombre explicito pk_tbl_motivo.
 *  - id bigint IDENTITY NOT NULL, NO es la PK: indice unico uq_tbl_motivo_id.
 *  - col_nombre nvarchar(150) NOT NULL. El usuario pidio varchar(5); se le
 *    advirtio que un nombre no cabe en 5 caracteres y eligio nvarchar(150)
 *    como las hermanas.
 *  - col_activo bit NOT NULL default 1 con indice; created_at / updated_at
 *    datetime NULL.
 *
 * SIN FK desde tbl_solicitud_detalle.cod_motivo, que hoy es varchar(50) y no
 * casa con varchar(5): lo decidira el usuario.
 *
 * Si la tabla ya existe, aborta ANTES de crear nada.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_motivo';

    public function up(): void
    {
        if (DB::selectOne('select object_id(?, ?) as id', ['dbo.'.self::TABLA, 'U'])->id !== null) {
            throw new RuntimeException('Ya existe la tabla '.self::TABLA.'. No se creo nada: revise su estructura a mano.');
        }

        DB::statement(
            'create table [tbl_motivo] (
                [id] bigint identity(1,1) not null,
                [col_cod_motivo] varchar(5) not null,
                [col_nombre] nvarchar(150) not null,
                [col_activo] bit not null constraint [df_tbl_motivo_col_activo] default 1,
                [created_at] datetime null,
                [updated_at] datetime null,
                constraint [pk_tbl_motivo] primary key ([col_cod_motivo])
            )'
        );
        DB::statement('create unique index [uq_tbl_motivo_id] on [tbl_motivo] ([id])');
        DB::statement('create index [tbl_motivo_col_activo_index] on [tbl_motivo] ([col_activo])');
    }

    public function down(): void
    {
        DB::statement('drop table if exists [tbl_motivo]');
    }
};
