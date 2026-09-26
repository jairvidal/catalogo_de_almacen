<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudItem extends Model
{
    use HasFactory;

    protected $table = 'tbl_solicitud_detalle';

    protected $fillable = [
        'solicitud_id',
        'repuesto_id',
        'codigo',
        'nombre',
        'foto',
        'cantidad_solicitada',
        'cantidad_entregada',
        'nota',
    ];

    /**
     * cod_bodega, cod_motivo, cantidad, cod_unidad_medida, cod_unidad_negocio,
     * cod_centro_operacion, cod_centro_de_costo, cod_proyecto, notas_item y
     * descripcion_item (migracion 2026_09_26_100600) NO son fillable: hoy nada
     * las escribe y su significado esta pendiente de definir. `cantidad` no es
     * cantidad_solicitada ni cantidad_entregada, y `notas_item` no es `nota`.
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_solicitada' => 'integer',
            'cantidad_entregada' => 'integer',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class);
    }

    public function repuesto(): BelongsTo
    {
        return $this->belongsTo(Repuesto::class);
    }

    /**
     * Foto congelada al momento del pedido; cae en la generica si ya no existe.
     */
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto && is_file(public_path('img/'.$this->foto))) {
            return asset('img/'.$this->foto);
        }

        return asset('assets/img/sin-foto.svg');
    }
}
