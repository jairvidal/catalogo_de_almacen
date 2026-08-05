<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe la gestion del catalogo al rol administrador.
 * El almacenista entra al panel, pero solo despacha solicitudes.
 */
class EnsureEsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->puedeGestionarCatalogo(), 403, 'Solo el administrador puede gestionar el catalogo.');

        return $next($request);
    }
}
