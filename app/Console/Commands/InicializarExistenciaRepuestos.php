<?php

namespace App\Console\Commands;

use App\Services\InicializadorExistenciaRepuestos;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Cara en consola de App\Services\InicializadorExistenciaRepuestos.
 *
 * Aqui no hay logica: el servicio lee la API, decide si la carga inicial
 * procede, escribe dentro de una transaccion y marca el parametro. Este comando
 * pregunta, reporta y traduce el fallo a un codigo de salida.
 *
 * NO ESTA EN EL PROGRAMADOR DE TAREAS Y NO DEBE ESTARLO. Escribe el saldo
 * operativo del almacen una sola vez, para desbloquear los pedidos cuando la
 * tabla se recreo con el esquema del ERP y `existencia` quedo en 0.
 */
class InicializarExistenciaRepuestos extends Command
{
    protected $signature = 'repuestos:inicializar-existencia
                            {--simular : Reporta cuantas filas recibirian saldo, sin escribir}
                            {--forzar : Omite la pregunta de confirmacion (para ejecuciones no interactivas)}';

    protected $description = 'Carga inicial de repuestos.existencia con lo que reporta la API del ERP. Se hace UNA SOLA VEZ';

    public function handle(InicializadorExistenciaRepuestos $inicializador): int
    {
        $simular = (bool) $this->option('simular');

        if ($inicializador->yaSeInicializo()) {
            $this->warn('La carga inicial de existencia ya se hizo; no se toca nada.');
            $this->line('Repetirla sobreescribiria el saldo operativo del almacen y borraria lo ya despachado.');

            // No es un fallo: el comando hizo justo lo que tenia que hacer.
            return self::SUCCESS;
        }

        $this->info('Consultando la API de inventario...');

        try {
            $previo = $inicializador->previsualizar();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->tabla($previo->contadores());

        if ($previo->candidatas === 0) {
            $this->warn('No hay nada que inicializar: el ERP no reporto existencia para ningun repuesto en cero.');

            return self::SUCCESS;
        }

        if ($simular) {
            $this->comment('Simulacion: no se escribio nada. Repita sin --simular para aplicar.');

            return self::SUCCESS;
        }

        // La pregunta es una de las tres barreras del servicio, no un adorno:
        // esta escritura no se deshace.
        if (! $this->option('forzar') && ! $this->confirm(
            "Se va a escribir el saldo del ERP en {$previo->candidatas} repuesto(s). Esto habilita los pedidos y NO se deshace. Continuar?",
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
            'recibidos' => 'Registros recibidos del ERP',
            'candidatas' => 'Repuestos que recibirian saldo',
            'ya_con_saldo' => 'Con saldo previo (no se tocan)',
            'sin_correspondencia' => 'Codigos sin correspondencia en el catalogo',
        ];

        $filas = [];

        foreach ($etiquetas as $clave => $etiqueta) {
            $filas[] = [$etiqueta, $contadores[$clave]];
        }

        $this->table(['Concepto', 'Cantidad'], $filas);
    }
}
