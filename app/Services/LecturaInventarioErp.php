<?php

namespace App\Services;

/**
 * Lo que devolvio una pasada completa por la API de inventario.
 *
 * Es solo lectura y no lleva nada sensible: existencias por codigo y contadores.
 * Ni la credencial ni el token pasan por aqui.
 */
class LecturaInventarioErp
{
    /**
     * @param  array<int, float>  $existencias  codigo => cantidad reportada por el ERP
     * @param  list<string>  $muestraItemNoNumerico  ejemplos de item no numerico que mando la API
     */
    public function __construct(
        public readonly array $existencias,
        public readonly int $paginas,
        public readonly int $recibidos,
        public readonly int $sinItem,
        public readonly int $itemNoNumerico,
        public readonly int $sinCantidad,
        public readonly bool $topeAlcanzado,
        public readonly array $muestraItemNoNumerico = [],
    ) {}

    /** Codigos que el ERP reporto, en el orden en que llegaron. */
    public function codigos(): array
    {
        return array_keys($this->existencias);
    }

    public function estaVacia(): bool
    {
        return $this->existencias === [];
    }
}
