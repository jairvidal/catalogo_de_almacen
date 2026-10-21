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

    /**
     * Guarda los dos parametros de la seccion "Importacion ERP"
     * (Parametro::IMPORTACION_ERP). Solo escribe col_valor: nombre, estado y
     * descripcion no se tocan.
     *
     * Los relee con bloqueo dentro de la transaccion y exige que existan los
     * dos: si falta uno (no se corrio la migracion ni el seeder) no se guarda
     * ninguno, para no dejar la seccion a medias.
     *
     * @throws ValidationException si falta alguno de los dos parametros.
     */
    public function guardarImportacionErp(string $url, string $estructuraJson): void
    {
        DB::transaction(function () use ($url, $estructuraJson) {
            $parametros = Parametro::query()
                ->deImportacionErp()
                ->lockForUpdate()
                ->get()
                ->keyBy('col_nombre');

            $faltan = array_diff(Parametro::IMPORTACION_ERP, $parametros->keys()->all());

            if ($faltan !== []) {
                throw ValidationException::withMessages([
                    'importacion_erp' => 'Faltan los parametros '.implode(', ', $faltan)
                        .'; ejecute las migraciones (php artisan migrate) y vuelva a intentar.',
                ]);
            }

            $valores = [
                Parametro::ERP_IMPORTACION_URL => $url,
                Parametro::ERP_IMPORTACION_JSON => $estructuraJson,
            ];

            foreach ($valores as $nombre => $valor) {
                $parametros[$nombre]->forceFill(['col_valor' => $valor])->save();
            }
        });
    }
}
