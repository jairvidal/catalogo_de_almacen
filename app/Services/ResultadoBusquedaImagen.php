<?php

namespace App\Services;

use App\Models\Repuesto;
use Illuminate\Support\Collection;

/**
 * Lo que devuelve BuscadorRepuestosPorImagen: solo los repuestos que
 * alcanzaron el umbral, ordenados de mayor a menor coincidencia.
 */
final class ResultadoBusquedaImagen
{
    /**
     * @param  Collection<int, Repuesto>  $repuestos
     * @param  array<int, int>  $porcentajes  id del repuesto => porcentaje
     */
    public function __construct(
        public readonly Collection $repuestos,
        public readonly array $porcentajes,
        public readonly int $umbral,
        public readonly UsoClaude $uso,
    ) {}

    public function total(): int
    {
        return $this->repuestos->count();
    }

    public function porcentajeDe(Repuesto $repuesto): int
    {
        return $this->porcentajes[$repuesto->id] ?? 0;
    }

    public function mensaje(): string
    {
        $total = $this->total();

        if ($total === 0) {
            return "No se encontraron repuestos con al menos {$this->umbral}% de coincidencia con la imagen.";
        }

        return $total === 1
            ? "Se encontro 1 repuesto con al menos {$this->umbral}% de coincidencia."
            : "Se encontraron {$total} repuestos con al menos {$this->umbral}% de coincidencia.";
    }
}
