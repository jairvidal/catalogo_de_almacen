<?php

namespace App\Models;

use App\Models\Concerns\EsMaestraErp;
use Illuminate\Database\Eloquent\Model;

/**
 * Bodega del ERP (tbl_bodega).
 *
 * La tabla la crearon POR FUERA de la aplicacion: no hay migracion y este
 * modelo se ajusta a lo que existe, sin alterarla. Estructura verificada en el
 * catalogo (2026-09-27): `id` bigint IDENTITY (sin indice unico), PK agrupada
 * `pk_tbl_bodega` sobre `col_cod_bod` varchar(5), `col_nombre` nvarchar(150),
 * `col_activo` bit NOT NULL **sin valor por defecto** y created_at/updated_at.
 *
 * Por eso $attributes pone col_activo en true: sin el, un create() que no lo
 * nombre fallaria por NOT NULL. `id` no es fillable (IDENTITY_INSERT) y la
 * clave es el codigo, como en las demas maestras (ver EsMaestraErp).
 *
 * Solo la LEE la aprobacion del portal (lista por item y relectura del codigo
 * elegido); nada de la aplicacion la llena.
 */
class Bodega extends Model
{
    use EsMaestraErp;

    protected $table = 'tbl_bodega';

    protected $primaryKey = 'col_cod_bod';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'col_cod_bod',
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
