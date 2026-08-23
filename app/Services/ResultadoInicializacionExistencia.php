<?php

namespace App\Services;

/**
 * Lo que dejo una corrida de InicializadorExistenciaRepuestos.
 *
 * Aqui no entra nada sensible: son conteos y codigos de inventario.
 */
class ResultadoInicializacionExistencia
{
    /**
     * @param  int  $recibidos  registros que mando el ERP
     * @param  int  $candidatas  repuestos que recibirian saldo
     * @param  int  $afectadas  filas realmente escritas (0 en simulacion)
     * @param  int  $yaConSaldo  repuestos que ya tenian existencia y no se tocan
     * @param  int  $sinCorrespondencia  codigos del ERP que el catalogo no tiene
     * @param  array<string, array{cantidad: float, codigos: list<int>}>  $porCantidad
     */
    public function __construct(
        public readonly bool $simulado,
        public readonly int $recibidos,
        public readonly int $candidatas,
        public readonly int $afectadas,
        public readonly int $yaConSaldo,
        public readonly int $sinCorrespondencia,
        public readonly array $porCantidad = [],
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function contadores(): array
    {
        return [
            'simulacion' => $this->simulado,
            'recibidos' => $this->recibidos,
            'candidatas' => $this->candidatas,
            'afectadas' => $this->afectadas,
            'ya_con_saldo' => $this->yaConSaldo,
            'sin_correspondencia' => $this->sinCorrespondencia,
        ];
    }
}
