<?php

namespace App\Console\Commands;

use App\Exceptions\SincronizacionEnCursoException;
use App\Services\BitacoraSincronizacionStock;
use App\Services\InventarioApiSidocsa;
use App\Services\ResultadoSincronizacionStock;
use App\Services\SincronizadorStockRepuestos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cara en consola de App\Services\SincronizadorStockRepuestos.
 *
 * Aqui NO hay logica de sincronizacion: el servicio pagina la API, empareja y
 * escribe, y este comando solo lee las opciones, pinta la tabla del resultado y
 * traduce un fallo a un codigo de salida. El boton "Actualizar" de
 * /admin/parametros llama al mismo servicio, de modo que consola y panel no
 * pueden divergir.
 */
class SincronizarStockRepuestos extends Command
{
    protected $signature = 'repuestos:sincronizar-stock
                            {--simular : Reporta sin escribir en la base}';

    protected $description = 'Actualiza repuestos.stock con la existencia que reporta la API de inventario de Sidocsa';

    public function handle(
        SincronizadorStockRepuestos $sincronizador,
        BitacoraSincronizacionStock $bitacora,
    ): int {
        $simular = (bool) $this->option('simular');

        $this->info($simular
            ? 'Simulando la sincronizacion de stock con la API de inventario.'
            : 'Sincronizando stock con la API de inventario.');

        try {
            $resultado = $sincronizador->sincronizar($simular);
        } catch (SincronizacionEnCursoException $e) {
            // No es un fallo: otra corrida (el panel o el programador) llego
            // antes y esta trabajando. Se avisa y se sale sin ruido de error.
            $this->newLine();
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Un fallo de red o de la API no puede salir como stack trace: se
            // registra completo y en pantalla queda un mensaje legible.
            Log::error('Fallo la sincronizacion de stock con la API de inventario.', [
                'excepcion' => $e->getMessage(),
            ]);

            $this->newLine();
            $this->error($e->getMessage());
            $this->line('El detalle quedo en el log. Revise los parametros en /admin/parametros.');

            return self::FAILURE;
        }

        $this->reportar($resultado);

        if ($resultado->topeAlcanzado) {
            $this->warn('Se alcanzo el tope de '.InventarioApiSidocsa::MAX_PAGINAS
                .' paginas y la ultima seguia llena: puede faltar inventario por leer.');
        }

        $this->avisarDeLoQueQuedoFuera($resultado);

        if ($simular) {
            $this->newLine();
            $this->comment('Simulacion: no se escribio nada, ni en el stock ni en la bitacora. '
                .'Repita sin --simular para aplicar.');
        } else {
            $this->newLine();
            $this->line('Bitacora: '.$bitacora->ruta());
        }

        return self::SUCCESS;
    }

    private function reportar(ResultadoSincronizacionStock $resultado): void
    {
        $this->newLine();

        $this->table(['Concepto', 'Cantidad'], [
            ['Paginas leidas', $resultado->paginas],
            ['Registros recibidos', $resultado->recibidos],
            [
                $resultado->simulado ? 'Repuestos que cambiarian de stock' : 'Repuestos con el stock cambiado',
                $resultado->actualizados,
            ],
            ['Repuestos que ya estaban al dia', $resultado->sinCambio],
            ['Registros no actualizados (total)', $resultado->noActualizados()],
            ['Codigos sin correspondencia en el catalogo', $resultado->sinCorrespondencia],
            ['Registros sin el campo item', $resultado->sinItem],
            ['Registros con item no numerico', $resultado->itemNoNumerico],
            ['Registros sin cant_disp numerico', $resultado->sinCantidad],
        ]);

        $this->line($resultado->resumen());
    }

    /**
     * Ejemplos de lo que no cruzo. Son codigos de inventario, no datos
     * sensibles, y sin ellos no hay como diagnosticar si el desfase es de
     * formato o de bodega.
     */
    private function avisarDeLoQueQuedoFuera(ResultadoSincronizacionStock $resultado): void
    {
        if ($resultado->muestraSinCorrespondencia !== []) {
            $this->line('Ejemplos de item sin correspondencia en el catalogo: '
                .implode(', ', $resultado->muestraSinCorrespondencia));
        }

        if ($resultado->muestraItemNoNumerico !== []) {
            $this->warn('Ejemplos de item no numerico: '.implode(', ', $resultado->muestraItemNoNumerico));
        }
    }
}
