<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motivo del ERP (tbl_motivo).
 *
 * La clave primaria es el CODIGO (varchar(5)), no `id`: `id` es IDENTITY con
 * indice unico y lo genera la base. Por eso $incrementing = false y `id` no es
 * fillable: si viajara en un INSERT, SQL Server lo rechazaria (IDENTITY_INSERT).
 * Tras un create() el `id` no queda en el modelo; use refresh() si lo necesita.
 * $keyType = 'string' evita que un codigo como '073' se trate como numero.
 *
 * Solo esquema: hoy nada llena la tabla (ver CLAUDE.md).
 */
class Motivo extends Model
{
    protected $table = 'tbl_motivo';

    protected $primaryKey = 'col_cod_motivo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'col_cod_motivo',
        'col_nombre',
        'col_activo',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'col_activo' => 'boolean',
        ];
    }
}
