<?php

namespace App\Console\Commands;

use App\Services\InicializadorExistenciaDesdeStock;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Cara en consola de App\Services\InicializadorExistenciaDesdeStock.
 *
 * Aqui no hay logica: el servicio decide si la carga inicial procede, escribe
 * dentro de una transaccion y marca el parametro. Este comando pregunta,
 * reporta y traduce el fallo a un codigo de salida.
 *
 * NO ESTA EN EL PROGRAMADOR DE TAREAS Y NO DEBE ESTARLO. Escribe el saldo
 * operativo del almacen una sola vez, para desbloquear los pedidos cuando la
 * tabla se recreo con el esquema del ERP y `existencia` quedo en 0.
 */
class InicializarExistenciaDesdeStock extends Command
{
    protected $signature = 'repuestos:inicializar-existencia-desde-stock
                            {--simular : Reporta cuantas filas recibirian saldo, sin escribir}
                            {--forzar : Omite la pregunta de confirmacion (para ejecuciones no interactivas)}';

    protected $description = 'Carga inicial de repuestos.existencia copiando repuestos.stock. Se hace UNA SOLA VEZ';

    public function handle(InicializadorExistenciaDesdeStock $inicializador): int
    {
        if ($inicializador->yaSeInicializo()) {
            $this->warn('La carga inicial de existencia ya se hizo; no se toca nada.');
            $this->line('Repetirla sobreescribiria el saldo operativo del almacen y borraria lo ya despachado.');

            // No es un fallo: el comando hizo justo lo que tenia que hacer.
            return self::SUCCESS;
        }

        $previo = $inicializador->previsualizar();

        $this->tabla($previo->contadores());

        if ($previo->candidatas === 0) {
            $this->warn('No hay nada que inicializar: ningun repuesto en cero tiene stock del ERP.');

            return self::SUCCESS;
        }

        if ($this->option('simular')) {
            $this->comment('Simulacion: no se escribio nada. Repita sin --simular para aplicar.');

            return self::SUCCESS;
        }

        // La pregunta es una de las tres barreras del servicio, no un adorno:
        // esta escritura no se deshace.
        $this->warn('CUIDADO: repuestos.stock arrastra los valores de la carga masiva del ERP, y en unas '
            .'2.669 filas ese valor es un punto de reposicion (stock igual a stock_minimo) y no una '
            .'existencia fisica. Copiarlo le dara saldo a repuestos que quiza no lo tengan en la estanteria.');

        if (! $this->option('forzar') && ! $this->confirm(
            "Se va a copiar stock a existencia en {$previo->candidatas} repuesto(s). Esto habilita los pedidos y NO se deshace. Continuar?",
            false
        )) {
            $this->line('Cancelado. No se escribio nada.');

            return self::SUCCESS;
        }

        try {
            $resultado = $inicializador->inicializar();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Existencia inicializada en {$resultado->afectadas} repuesto(s).");
        $this->line('A partir de ahora ese saldo lo mueve unicamente el despacho de solicitudes; '
            .'la sincronizacion con el ERP solo escribe stock.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int|bool>  $contadores
     */
    private function tabla(array $contadores): void
    {
        $etiquetas = [
            'total' => 'Repuestos en el catalogo',
            'candidatas' => 'Recibirian saldo (existencia 0 y stock > 0)',
            'ya_con_saldo' => 'Con saldo previo (no se tocan)',
            'sin_stock' => 'Siguen en 0 porque el ERP no reporta stock',
        ];

        $filas = [];

        foreach ($etiquetas as $clave => $etiqueta) {
            $filas[] = [$etiqueta, $contadores[$clave]];
        }

        $this->table(['Concepto', 'Cantidad'], $filas);
    }
}
