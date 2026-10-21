<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integracion con el ERP al aprobar (V 1.4.39, IntegracionErpService).
 *
 * 1) tbl_consecutivo: contadores persistentes con nombre. Hoy una sola fila,
 *    'erp.importacion', que da el campo "numero" del encabezado que se envia
 *    al ERP. Arranca en col_ultimo = 0 (el primer numero entregado es 1) y no
 *    se reinicia nunca. Se incrementa con un UPDATE atomico ... OUTPUT, asi
 *    que dos aprobaciones simultaneas nunca reciben el mismo numero. NO es
 *    tbl_solicitudes.numero: aquel es el consecutivo de la solicitud y este el
 *    del documento en el ERP.
 *
 * 2) Constancia del envio en tbl_solicitudes (sin prefijo col_: tabla anterior
 *    a la convencion, igual que aprobada_at):
 *    - erp_numero: el numero reservado para ESTA solicitud. Se reserva una
 *      sola vez y se reutiliza si el envio se repite (idempotencia ante el
 *      ERP). Indice unico filtrado: un numero nunca queda en dos solicitudes.
 *    - erp_enviado_at: cuando el ERP respondio 2xx.
 *    - erp_error: ultimo fallo del envio. Columna propia y no
 *      error_notificacion, porque esa la limpia el aviso por correo y la lee
 *      el portal para decir "no se pudo enviar el correo".
 *
 * SQL nativo; aborta antes de escribir si algo ya existe.
 */
return new class extends Migration
{
    private const COLUMNAS = ['erp_numero', 'erp_enviado_at', 'erp_error'];

    public function up(): void
    {
        if (Schema::hasTable('tbl_consecutivo')) {
            throw new RuntimeException('tbl_consecutivo ya existe: revise la base antes de migrar.');
        }

        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('tbl_solicitudes', $columna)) {
                throw new RuntimeException("tbl_solicitudes.{$columna} ya existe: revise la base antes de migrar.");
            }
        }

        DB::statement(
            'create table [tbl_consecutivo] (
                [id] bigint identity(1,1) not null constraint [pk_tbl_consecutivo] primary key,
                [col_nombre] varchar(50) not null,
                [col_ultimo] bigint not null constraint [df_tbl_consecutivo_col_ultimo] default 0,
                [created_at] datetime null,
                [updated_at] datetime null,
                constraint [uq_tbl_consecutivo_col_nombre] unique ([col_nombre]),
                constraint [ck_tbl_consecutivo_col_ultimo] check ([col_ultimo] >= 0)
            )'
        );

        DB::insert(
            "insert into [tbl_consecutivo] ([col_nombre], [col_ultimo], [created_at], [updated_at])
             values ('erp.importacion', 0, getdate(), getdate())"
        );

        DB::statement(
            'alter table [tbl_solicitudes] add
                [erp_numero] bigint null,
                [erp_enviado_at] datetime null,
                [erp_error] nvarchar(500) null'
        );

        DB::statement(
            'create unique nonclustered index [ux_tbl_solicitudes_erp_numero]
                on [tbl_solicitudes] ([erp_numero]) where [erp_numero] is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists [ux_tbl_solicitudes_erp_numero] on [tbl_solicitudes]');

        foreach (self::COLUMNAS as $columna) {
            if (Schema::hasColumn('tbl_solicitudes', $columna)) {
                DB::statement("alter table [tbl_solicitudes] drop column [{$columna}]");
            }
        }

        DB::statement('drop table if exists [tbl_consecutivo]');
    }
};
