<?php

namespace App\Services;

use App\Models\Rol;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolService
{
    /**
     * Anula un rol (col_activo = false). No se borra: los usuarios historicos
     * siguen apuntando al registro por users.rol_id.
     *
     * @throws ValidationException si es un rol del sistema o tiene usuarios activos.
     */
    public function anular(Rol $rol): Rol
    {
        if ($rol->es_del_sistema) {
            throw ValidationException::withMessages([
                'rol' => "El rol \"{$rol->col_nombre}\" es del sistema y no se puede anular.",
            ]);
        }

        return DB::transaction(function () use ($rol) {
            // Se relee con bloqueo para que nadie asigne un usuario a este rol
            // entre la verificacion y la anulacion.
            $actual = Rol::query()->lockForUpdate()->findOrFail($rol->id);

            $usuariosActivos = $actual->usuarios()->where('activo', true)->count();

            if ($usuariosActivos > 0) {
                throw ValidationException::withMessages([
                    'rol' => "El rol \"{$actual->col_nombre}\" tiene {$usuariosActivos} usuario(s) activo(s); reasignelos antes de anularlo.",
                ]);
            }

            $actual->update(['col_activo' => false]);

            return $actual;
        });
    }
}
