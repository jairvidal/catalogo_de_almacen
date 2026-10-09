<?php

namespace App\Models;

use App\Models\Concerns\EsMaestraErp;
use Illuminate\Database\Eloquent\Model;

/**
 * Centro de operacion del ERP (tbl_centro_operacion).
 *
 * La clave primaria es el CODIGO (varchar(10)), no `id`: `id` es IDENTITY con
 * indice unico y lo genera la base. Por eso $incrementing = false y `id` no es
 * fillable: si viajara en un INSERT, SQL Server lo rechazaria (IDENTITY_INSERT).
 * Tras un create() el `id` no queda en el modelo; use refresh() si lo necesita.
 * $keyType = 'string' evita que un codigo como '015' se trate como numero.
 *
 * Solo la LEE la aprobacion del portal (opciones de la lista y relectura del
 * codigo elegido, ver EsMaestraErp); nada de la aplicacion la llena.
 */
class CentroOperacion extends Model
{
    use EsMaestraErp;

    protected $table = 'tbl_centro_operacion';

    protected $primaryKey = 'col_cod_centro_operacion';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'col_cod_centro_operacion',
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
