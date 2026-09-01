<?php

namespace App\Services;

/**
 * Lo que dejo una corrida de InicializadorExistenciaDesdeStock.
 *
 * Solo conteos: aqui no entra ni un codigo de repuesto, ni una credencial, ni
 * nada que venga de la API.
 *
 * Los cuatro contadores de filas son EXCLUYENTES Y EXHAUSTIVOS —
 * total = yaConSaldo + candidatas + sinStock— para que un descuadre en la tabla
 * del comando se vea a simple vista en vez de pasar por bueno.
 */
class ResultadoInicializacionDesdeStock
{
    /**
     * @param  bool  $simulado  si la corrida no escribio nada
     * @param  int  $total  filas de repuestos
     * @param  int  $candidatas  filas que recibirian saldo (existencia = 0 y stock > 0)
     * @param  int  $afectadas  filas realmente escritas (0 en simulacion)
     * @param  int  $yaConSaldo  filas con saldo operativo, que nunca se tocan
     * @param  int  $sinStock  filas en cero que siguen en cero porque el ERP no reporta stock
     */
    public function __construct(
        public readonly bool $simulado,
        public readonly int $total,
        public readonly int $candidatas,
        public readonly int $afectadas,
        public readonly int $yaConSaldo,
        public readonly int $sinStock,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function contadores(): array
    {
        return [
            'simulacion' => $this->simulado,
            'total' => $this->total,
            'candidatas' => $this->candidatas,
            'afectadas' => $this->afectadas,
            'ya_con_saldo' => $this->yaConSaldo,
            'sin_stock' => $this->sinStock,
        ];
    }
}
