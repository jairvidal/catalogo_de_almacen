<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Repuesto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoriaService
{
    /** Violacion de indice unico en SQL Server. */
    private const ERRORES_UNICO = ['2601', '2627'];

    /**
     * Crea la categoria derivando col_slug del nombre.
     *
     * El slug no llega del formulario y despues no se edita: es la clave con la
     * que DetectorColorMarco y CategoriaSeeder reconocen el registro, asi que
     * renombrar la categoria en el panel no puede cambiarlo.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Categoria
    {
        // El indice unico sobre col_slug es la garantia ante concurrencia; el
        // sufijo solo evita el choque previsible de dos nombres parecidos.
        for ($intento = 1; $intento <= 3; $intento++) {
            try {
                $categoria = new Categoria($datos);
                $categoria->col_slug = $this->slugDisponible($datos['col_nombre'] ?? '');
                $categoria->save();

                return $categoria;
            } catch (QueryException $e) {
                if ($intento === 3 || ! $this->esChoqueDeUnico($e)) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages([
            'col_nombre' => 'No se pudo generar una clave unica para la categoria; intente con otro nombre.',
        ]);
    }

    /**
     * Anula una categoria (col_activo = false). No se borra: los repuestos
     * apuntan al registro por repuestos.id_categoria.
     *
     * @throws ValidationException si tiene repuestos activos asociados.
     */
    public function anular(Categoria $categoria): Categoria
    {
        return DB::transaction(function () use ($categoria) {
            // Se relee con bloqueo para que nadie asigne un repuesto a esta
            // categoria entre la verificacion y la anulacion.
            $actual = Categoria::query()->lockForUpdate()->findOrFail($categoria->id);

            $repuestosActivos = $actual->repuestos()->where('estado', Repuesto::ESTADO_ACTIVO)->count();

            if ($repuestosActivos > 0) {
                throw ValidationException::withMessages([
                    'categoria' => "La categoria \"{$actual->col_nombre}\" tiene {$repuestosActivos} repuesto(s) activo(s); reasignelos antes de anularla.",
                ]);
            }

            $actual->update(['col_activo' => false]);

            return $actual;
        });
    }

    /**
     * Slug a partir del nombre, con sufijo numerico si ya esta tomado.
     */
    private function slugDisponible(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'categoria';
        $base = Str::limit($base, 56, '');
        $candidato = $base;
        $sufijo = 2;

        while (Categoria::where('col_slug', $candidato)->exists()) {
            $candidato = $base.'-'.$sufijo;
            $sufijo++;
        }

        return $candidato;
    }

    private function esChoqueDeUnico(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[1] ?? ''), self::ERRORES_UNICO, true)
            || in_array((string) $e->getCode(), self::ERRORES_UNICO, true);
    }
}
