<?php

use App\Console\Commands\SincronizarStockRepuestos;
use App\Services\SincronizadorStockRepuestos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
| El scheduler de Laravel necesita que algo externo lo despierte cada minuto:
| en Windows, `php artisan schedule:work` corriendo, o una tarea programada que
| ejecute `php artisan schedule:run`. Sin eso nada de esto se dispara solo.
*/

// Se registra cada minuto y el filtro decide: asi el modo (inv.actualizar) y el
// intervalo real (tiempo.actualizar) los manda el panel y cambiarlos surte
// efecto enseguida, sin tocar codigo ni reiniciar nada.
Schedule::command(SincronizarStockRepuestos::class)
    ->everyMinute()
    // Dos corridas no se pueden pisar: la paginacion tarda y la anterior podria
    // seguir escribiendo cuando arranque la siguiente. El candado del servicio
    // cubre ademas el choque contra el boton "Actualizar" del panel.
    ->withoutOverlapping()
    ->when(function () {
        try {
            // debeCorrer() exige las dos condiciones: modo automatico y que ya
            // hayan pasado los minutos del intervalo. En modo manual la tarea
            // no se dispara nunca.
            return app(SincronizadorStockRepuestos::class)->debeCorrer();
        } catch (Throwable $e) {
            // Antes de migrar y sembrar, tbl_parametro no existe todavia; un
            // fallo aqui no puede tumbar todo el schedule:run.
            Log::warning('No se pudo evaluar si toca sincronizar el stock.', [
                'excepcion' => $e->getMessage(),
            ]);

            return false;
        }
    });
