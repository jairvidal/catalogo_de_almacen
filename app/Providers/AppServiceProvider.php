<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
        Blade::if('puede', fn (string $funcionalidad, string $accion) => (bool) auth()->user()?->puede($funcionalidad, $accion));

        Gate::define('permiso', fn (User $usuario, string $funcionalidad, string $accion) => $usuario->puede($funcionalidad, $accion));
    }
}
