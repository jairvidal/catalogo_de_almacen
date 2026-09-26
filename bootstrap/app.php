<?php

use App\Http\Middleware\EnsureEsAdmin;
use App\Http\Middleware\EnsurePermiso;
use App\Http\Middleware\EnsureSolicitanteActivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // es.admin queda registrado pero ninguna ruta lo usa desde el modulo
        // Funciones por perfil: las rutas del panel piden permiso:clave,accion,
        // que respeta la matriz y cae a col_gestiona_catalogo mientras un rol no
        // la tenga guardada.
        $middleware->alias([
            'es.admin' => EnsureEsAdmin::class,
            'permiso' => EnsurePermiso::class,
            'solicitante.activo' => EnsureSolicitanteActivo::class,
        ]);

        // Los invitados van al login que corresponde a la zona que pidieron:
        // el portal del solicitante tiene el suyo, separado del del almacen.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->routeIs('solicitante.*')
            ? route('solicitante.login')
            : route('admin.login'));

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
