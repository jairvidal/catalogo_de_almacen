<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * El rol del sistema "admin" conserva SIEMPRE todos los permisos, tenga lo
     * que tenga guardado en tbl_rol_funcionalidad: es lo que impide que alguien
     * deje el panel sin nadie capaz de devolver los permisos.
     */
    public function getEsAdminDelSistemaAttribute(): bool
    {
        return $this->es_del_sistema && $this->col_clave === User::ROL_ADMIN;
    }

    /**
     * Permisos guardados para este rol, por clave de funcionalidad activa.
     *
     * Devuelve null cuando el rol todavia NO tiene ninguna fila en la matriz
     * (nunca se guardo): quien llama aplica entonces el permiso heredado de
     * col_gestiona_catalogo. En cuanto existe una sola fila la matriz manda, y
     * una funcionalidad sin fila no concede nada.
     *
     * @return array<string, array{ver: bool, editar: bool, eliminar: bool}>|null
     */
    public function permisosGuardados(): ?array
    {
        $filas = DB::select(
            'select f.col_clave, f.col_activo, rf.col_ver, rf.col_editar, rf.col_eliminar
               from tbl_rol_funcionalidad rf
              inner join tbl_funcionalidad f on f.id = rf.col_funcionalidad_id
              where rf.col_rol_id = ?',
            [$this->id]
        );

        if ($filas === []) {
            return null;
        }

        $permisos = [];

        foreach ($filas as $fila) {
            // Una funcionalidad anulada no concede nada, aunque tenga la fila.
            if (! (int) $fila->col_activo) {
                continue;
            }

            $permisos[$fila->col_clave] = [
                Funcionalidad::ACCION_VER => (bool) (int) $fila->col_ver,
                Funcionalidad::ACCION_EDITAR => (bool) (int) $fila->col_editar,
                Funcionalidad::ACCION_ELIMINAR => (bool) (int) $fila->col_eliminar,
            ];
        }

        return $permisos;
    }
}
