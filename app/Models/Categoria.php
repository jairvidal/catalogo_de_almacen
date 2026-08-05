<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria del catalogo. En el almacen la categoria es el color del marco que
 * lleva impresa la foto del repuesto, asi que col_slug espeja ese color y es la
 * clave que usa DetectorColorMarco para asignar repuestos automaticamente.
 *
 * col_nombre si se puede renombrar desde el panel; col_slug no, porque romperia
 * el amarre con la deteccion y el seeder crearia una categoria duplicada.
 */
class Categoria extends Model
{
    protected $table = 'tbl_categoria';

    /**
     * col_slug queda fuera a proposito: lo deriva CategoriaService al crear y
     * despues es inmutable, para que no llegue desde el formulario.
     */
    protected $fillable = [
        'col_nombre',
        'col_color_hex',
        'col_descripcion',
        'col_activo',
    ];

    protected function casts(): array
    {
        return [
            'col_activo' => 'boolean',
        ];
    }

    public function repuestos(): HasMany
    {
        return $this->hasMany(Repuesto::class, 'categoria_id');
    }

    /**
     * Busca por nombre, slug o descripcion.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

            $q->where('col_nombre', 'like', $like)
                ->orWhere('col_slug', 'like', $like)
                ->orWhere('col_descripcion', 'like', $like);
        });
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('col_activo', true);
    }
}
