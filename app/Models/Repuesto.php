<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repuesto extends Model
{
    use HasFactory;

    protected $table = 'repuestos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'categoria',
        'categoria_id',
        'ubicacion',
        'unidad_medida',
        'foto',
        'cantidad_disponible',
        'stock_minimo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_disponible' => 'integer',
            'stock_minimo' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudItem::class);
    }

    /**
     * Categoria por color de marco (tbl_categoria).
     *
     * Se llama categoriaAsignada() y no categoria() por el mismo motivo que
     * User::rolAsignado(): ya existe la columna de texto repuestos.categoria y
     * opacaria la relacion, asi que $repuesto->categoria seguiria devolviendo
     * el texto historico y nunca el modelo.
     */
    public function categoriaAsignada(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    /**
     * Busca por codigo, nombre, descripcion o categoria.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

            $q->where('codigo', 'like', $like)
                ->orWhere('nombre', 'like', $like)
                ->orWhere('descripcion', 'like', $like)
                ->orWhere('categoria', 'like', $like);
        });
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->where('cantidad_disponible', '>', 0);
    }

    /**
     * Ruta publica de la foto, con imagen generica cuando el item no tiene una.
     */
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto && is_file(public_path('img/'.$this->foto))) {
            return asset('img/'.$this->foto);
        }

        return asset('assets/img/sin-foto.svg');
    }

    public function getSinStockAttribute(): bool
    {
        return $this->cantidad_disponible <= 0;
    }

    public function getStockBajoAttribute(): bool
    {
        return ! $this->sin_stock && $this->cantidad_disponible <= $this->stock_minimo;
    }
}
