<?php

namespace App\Services;

/**
 * Convierte tokens en dolares con los precios del .env
 * (services.anthropic.precios, USD por millon de tokens).
 *
 * Es una ESTIMACION para la bitacora: lo que cobra Anthropic sale de su
 * consola. Si se cambia el modelo y no los precios, la bitacora mentira; por
 * eso los dos viven juntos en el .env.
 */
class CalculadoraCostoClaude
{
    private const POR_MILLON = 1_000_000;

    public function costo(UsoClaude $uso): float
    {
        $precios = (array) config('services.anthropic.precios', []);

        $total = $uso->entrada * (float) ($precios['entrada'] ?? 0)
            + $uso->salida * (float) ($precios['salida'] ?? 0)
            + $uso->cacheEscritura * (float) ($precios['cache_escritura'] ?? 0)
            + $uso->cacheLectura * (float) ($precios['cache_lectura'] ?? 0);

        return round($total / self::POR_MILLON, 6);
    }
}
