<?php

namespace App\Services;

/**
 * Tokens que consumio una o varias llamadas a la API de Claude.
 *
 * Inmutable: sumar() devuelve otro objeto. Lo arma ClienteClaudeApi a partir
 * del bloque `usage` de la respuesta y lo consumen CalculadoraCostoClaude y
 * BitacoraCostoClaude.
 *
 * Los tokens de cache van aparte de `entrada` porque la API los reporta aparte
 * y se cobran a otra tarifa: `input_tokens` NO incluye lo leido ni lo escrito
 * en cache.
 */
final class UsoClaude
{
    public function __construct(
        public readonly int $entrada = 0,
        public readonly int $salida = 0,
        public readonly int $cacheEscritura = 0,
        public readonly int $cacheLectura = 0,
        public readonly int $llamadas = 0,
    ) {}

    public static function vacio(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $usage  bloque `usage` de la respuesta
     */
    public static function desdeRespuesta(array $usage): self
    {
        return new self(
            entrada: (int) ($usage['input_tokens'] ?? 0),
            salida: (int) ($usage['output_tokens'] ?? 0),
            cacheEscritura: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheLectura: (int) ($usage['cache_read_input_tokens'] ?? 0),
            llamadas: 1,
        );
    }

    public function sumar(self $otro): self
    {
        return new self(
            entrada: $this->entrada + $otro->entrada,
            salida: $this->salida + $otro->salida,
            cacheEscritura: $this->cacheEscritura + $otro->cacheEscritura,
            cacheLectura: $this->cacheLectura + $otro->cacheLectura,
            llamadas: $this->llamadas + $otro->llamadas,
        );
    }
}
