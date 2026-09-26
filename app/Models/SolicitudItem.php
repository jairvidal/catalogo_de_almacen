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

    protected function casts(): array
    {
        return [
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
