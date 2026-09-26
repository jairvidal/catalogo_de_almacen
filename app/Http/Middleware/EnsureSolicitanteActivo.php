<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesion del portal si el solicitante dejo de poder usarlo.
 *
 * El guard rehidrata la sesion por id y no vuelve a mirar si la persona sigue
 * activa en el ERP ni si todavia tiene contrasena: sin esto, alguien a quien el
 * ERP inactivo seguiria aprobando solicitudes mientras dure su sesion. El
 * cambio de contrasena lo cubre aparte `auth.session` (compara el hash).
 *
 * Va despues de auth:solicitante.
 */
class EnsureSolicitanteActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('solicitante');
        $solicitante = $guard->user();

        if ($solicitante !== null && ! $solicitante->puedeIngresar()) {
            Log::warning('Sesion de solicitante cerrada: ya no tiene acceso al portal.', [
                'solicitante_id' => $solicitante->getAuthIdentifier(),
                'ruta' => $request->route()?->getName(),
            ]);

            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('solicitante.login')
                ->with('error', 'Su acceso al portal ya no esta habilitado. Comuniquese con el almacen.');
        }

        return $next($request);
    }
}
