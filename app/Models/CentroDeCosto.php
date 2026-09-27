<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Centro de costo del ERP (tbl_centro_de_costo).
 *
 * La clave primaria es el CODIGO (varchar(10)), no `id`: `id` es IDENTITY con
 * indice unico y lo genera la base. Por eso $incrementing = false y `id` no es
 * fillable: si viajara en un INSERT, SQL Server lo rechazaria (IDENTITY_INSERT).
 * Tras un create() el `id` no queda en el modelo; use refresh() si lo necesita.
 * $keyType = 'string' evita que un codigo como 'P91885' o '015' se trate como numero.
 *
 * Solo esquema: hoy nada llena la tabla (ver CLAUDE.md).
 */
class CentroDeCosto extends Model
{
    protected $table = 'tbl_centro_de_costo';

    protected $primaryKey = 'col_cod_centro_de_costo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'col_cod_centro_de_costo',
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
