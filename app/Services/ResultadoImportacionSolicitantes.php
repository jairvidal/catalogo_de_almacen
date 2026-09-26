<?php

namespace App\Services;

/**
 * Resultado de una importacion de solicitantes del ERP, de solo lectura.
 *
 * Los contadores son EXCLUYENTES y EXHAUSTIVOS sobre las filas leidas:
 * leidas = creados + actualizados + sinCambio + omitidas + repetidas
 * para que un descuadre se vea a simple vista.
 *
 * `actualizados` cuenta lo que CAMBIO, no lo que se escribio: el MERGE solo
 * actualiza la fila cuando algun dato es distinto, y la que ya estaba al dia
 * cuenta en `sinCambio`. Asi volver a importar el mismo archivo reporta cero
 * actualizados, que es lo que se espera de una carga idempotente.
 */
final class ResultadoImportacionSolicitantes
{
    /**
     * @param  list<array{referencia: int|string, mensaje: string}>  $avisos
     */
    public function __construct(
        public readonly int $leidas,
        public readonly int $creados,
        public readonly int $actualizados,
        public readonly int $sinCambio,
        public readonly int $omitidas,
        public readonly int $repetidas,
        public readonly array $avisos,
    ) {}
}
