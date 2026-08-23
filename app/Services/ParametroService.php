<?php

namespace App\Services;

use App\Models\Parametro;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParametroService
{
    /**
     * Anula un parametro (col_estado = 'inactivo'). No se borra: el codigo lo
     * pide por nombre y un parametro ausente y uno inactivo tienen que poder
     * distinguirse cuando alguien revise por que dejo de sincronizar.
     *
     * @throws ValidationException si es un parametro del sistema.
     */
    public function anular(Parametro $parametro): Parametro
    {
        return DB::transaction(function () use ($parametro) {
            // Se relee con bloqueo para que la verificacion y la escritura no
            // se separen: entre las dos, otro admin puede estar guardando el
            // mismo registro desde el formulario.
            $actual = Parametro::query()->lockForUpdate()->findOrFail($parametro->id);

            if ($actual->es_del_sistema) {
                throw ValidationException::withMessages([
                    'parametro' => "El parametro \"{$actual->col_nombre}\" es del sistema y no se puede anular; "
                        .'sin el, la sincronizacion con el ERP se queda sin configuracion.',
                ]);
            }

            $actual->update(['col_estado' => Parametro::ESTADO_INACTIVO]);

            return $actual;
        });
    }
}
