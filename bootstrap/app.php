<?php

use App\Http\Middleware\EnsureEsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'es.admin' => EnsureEsAdmin::class,
        ]);

        // Los invitados que intenten entrar al panel van al login del almacen.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));

        // El valor de un parametro se guarda literal. Laravel recorta por
        // defecto toda la entrada, y eso borraba en silencio el espacio final
        // de api.criterio_2 cada vez que se guardaba el formulario, cambiando
        // la consulta que se le manda a la API de inventario sin que nadie lo
        // viera. La excepcion es solo para ese campo.
        $middleware->trimStrings(except: ['col_valor']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
