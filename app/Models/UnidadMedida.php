<?php

namespace App\Models;

use App\Models\Concerns\EsMaestraErp;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidad de medida del ERP (tbl_unidad_medida).
 *
 * La tabla la crearon POR FUERA de la aplicacion: no hay migracion y este
 * modelo se ajusta a lo que existe, sin alterarla. Estructura verificada en el
 * catalogo (2026-09-27): `id` bigint IDENTITY (sin indice unico), PK agrupada
 * `pk_tbl_unidad_medida` sobre `col_cod_und_med` varchar(5), `col_nombre`
 * nvarchar(150), `col_activo` bit NOT NULL **sin valor por defecto** y
 * created_at/updated_at.
 *
 * No confundir con repuestos.unidad_medida (texto del ERP en el catalogo):
 * esta es la maestra que se ofrece por item al aprobar.
 *
 * Por eso $attributes pone col_activo en true: sin el, un create() que no lo
 * nombre fallaria por NOT NULL. `id` no es fillable (IDENTITY_INSERT) y la
 * clave es el codigo, como en las demas maestras (ver EsMaestraErp).
 */
class UnidadMedida extends Model
{
    use EsMaestraErp;

    protected $table = 'tbl_unidad_medida';

    protected $primaryKey = 'col_cod_und_med';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'col_cod_und_med',
        'col_nombre',
        'col_activo',
    ];

    protected $attributes = [
        'col_activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'col_activo' => 'boolean',
        ];
    }
}
