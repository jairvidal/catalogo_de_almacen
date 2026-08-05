<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol interno del panel. La columna historica users.rol guarda la misma clave
 * como texto y sigue siendo la que autentica; users.rol_id es la relacion.
 */
class Rol extends Model
{
    protected $table = 'tbl_rol';

    protected $fillable = [
        'col_clave',
        'col_nombre',
        'col_descripcion',
        'col_gestiona_catalogo',
        'col_activo',
    ];

    protected function casts(): array
    {
        return [
            'col_gestiona_catalogo' => 'boolean',
            'col_sistema' => 'boolean',
            'col_activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }

    /**
     * Busca por clave, nombre o descripcion.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

            $q->where('col_clave', 'like', $like)
                ->orWhere('col_nombre', 'like', $like)
                ->orWhere('col_descripcion', 'like', $like);
        });
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('col_activo', true);
    }

    /**
     * Los roles del sistema sostienen el acceso al panel: no se anulan y no se
     * les cambia la clave ni el permiso, para que nadie se deje a si mismo por fuera.
     */
    public function getEsDelSistemaAttribute(): bool
    {
        return (bool) $this->col_sistema;
    }
}
