<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Comportamiento comun de las tablas maestras del ERP cuya clave primaria es
 * el CODIGO (tbl_centro_operacion, tbl_centro_de_costo, tbl_proyecto,
 * tbl_motivo y las creadas por fuera tbl_bodega y tbl_unidad_medida): la
 * regla de "que opciones se ofrecen" vive aqui y no repetida en cada modelo.
 *
 * El modelo que lo use debe declarar $primaryKey = el codigo y tener las
 * columnas col_nombre y col_activo.
 */
trait EsMaestraErp
{
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('col_activo', true);
    }

    /**
     * Opciones para las listas del formulario: solo las activas, ordenadas
     * por codigo, como "codigo - nombre".
     *
     * @return array<string, string> codigo => texto visible
     */
    public static function opcionesActivas(): array
    {
        $modelo = new static;
        $clave = $modelo->getKeyName();

        return static::query()
            ->activas()
            ->orderBy($clave)
            ->get([$clave, 'col_nombre'])
            ->mapWithKeys(fn ($fila) => [(string) $fila->getKey() => $fila->texto_opcion])
            ->all();
    }

    /**
     * Relee una maestra ACTIVA por su codigo. Null si no existe o esta inactiva.
     */
    public static function activaPorCodigo(string $codigo): ?static
    {
        return static::query()->activas()->whereKey($codigo)->first();
    }

    /**
     * Relee VARIAS maestras activas en una sola consulta (la aprobacion por
     * item repite codigos entre renglones). La llave del resultado va en
     * minusculas porque la collation es CI: "abc" encuentra la fila "ABC" y
     * quien llama tiene que poder emparejarlas.
     *
     * @param  list<string>  $codigos
     * @return array<string, static> codigo en minusculas => maestra
     */
    public static function activasPorCodigos(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        return static::query()
            ->activas()
            ->whereKey(array_values(array_unique($codigos)))
            ->get()
            ->mapWithKeys(fn ($fila) => [mb_strtolower((string) $fila->getKey()) => $fila])
            ->all();
    }

    public function getTextoOpcionAttribute(): string
    {
        return $this->getKey().' - '.$this->col_nombre;
    }
}
