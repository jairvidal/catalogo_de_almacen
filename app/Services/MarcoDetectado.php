<?php

namespace App\Services;

/**
 * Resultado de analizar el marco de una foto de repuesto.
 */
final readonly class MarcoDetectado
{
    public function __construct(
        /** Clave del color, espeja tbl_categoria.col_slug (ej: 'rojo', 'celeste'). */
        public string $slug,
        /** Color dominante del marco ya cuantizado (ej: '#D81818'). */
        public string $hex,
        /** Porcentaje de los pixeles del marco que aporto el color dominante. */
        public float $porcentaje,
        /** Matiz en grados, null cuando el color es acromatico (negro, gris, blanco). */
        public ?float $matiz,
        /** Luminosidad 0-255. Es lo que separa el durazno claro del naranja saturado. */
        public float $luminosidad,
    ) {}
}
