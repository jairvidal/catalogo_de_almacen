<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso de la matriz Funciones por perfil.
 *
 * Uso en rutas: ->middleware('permiso:repuestos,editar'). La decision la toma
 * User::puede(); aqui solo se corta la peticion y se deja rastro del intento.
 */
class EnsurePermiso
{
    public function handle(Request $request, Closure $next, string $funcionalidad, string $accion): Response
    {
        $usuario = $request->user();

        if (! $usuario?->puede($funcionalidad, $accion)) {
            // Evento de seguridad: quien, sobre que y por que ruta. Nada de
            // datos personales ni del cuerpo de la peticion.
            Log::warning('Acceso denegado por permiso del panel.', [
                'usuario_id' => $usuario?->id,
                'funcionalidad' => $funcionalidad,
                'accion' => $accion,
                'ruta' => $request->route()?->getName(),
            ]);

            abort(403, 'Su perfil no tiene permiso para esta accion.');
        }

        return $next($request);
    }
}
