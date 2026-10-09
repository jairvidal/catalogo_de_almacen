<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Las dos caras de la matriz Funciones por perfil. Ninguna decide nada
        // por su cuenta: las dos preguntan a User::puede().
        //   Vistas:      @puede('repuestos', 'editar') ... @else ... @endpuede
        //   Codigo PHP:  Gate::allows('permiso', ['repuestos', 'editar'])
        // Guard web explicito: en una ruta del portal el guard por defecto es el
        // del solicitante, que no es usuario del panel ni tiene permisos.
        Blade::if('puede', fn (string $funcionalidad, string $accion) => (bool) auth('web')->user()?->puede($funcionalidad, $accion));

        Gate::define('permiso', fn (User $usuario, string $funcionalidad, string $accion) => $usuario->puede($funcionalidad, $accion));

        // Busqueda por imagen del catalogo publico: cada consulta cuesta dinero
        // en la API de Claude. Dos frenos: por IP y por minuto, y un tope GLOBAL
        // por dia que acota el gasto aunque las peticiones vengan de muchas IP.
        RateLimiter::for('busqueda-imagen', function (Request $request) {
            $respuesta = fn () => response()->json([
                'ok' => false,
                'mensaje' => 'Se alcanzo el limite de busquedas por imagen. Intente de nuevo mas tarde.',
            ], 429);

            return [
                Limit::perMinute(max(1, (int) config('services.anthropic.imagen.por_minuto', 5)))
                    ->by('ip:'.$request->ip())->response($respuesta),
                Limit::perDay(max(1, (int) config('services.anthropic.imagen.por_dia', 300)))
                    ->by('global')->response($respuesta),
            ];
        });
    }
}
