<?php

namespace App\Http\Controllers\Solicitante;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambioContrasenaSolicitanteRequest;
use App\Http\Requests\IngresoSolicitanteRequest;
use App\Services\AccesoSolicitanteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Ingreso, salida y cambio de contrasena del portal del solicitante (guard
 * `solicitante`, separado del panel del almacen).
 */
class AccesoController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('solicitante')->check()) {
            return redirect()->route('solicitante.solicitudes.index');
        }

        return view('solicitante.login');
    }

    public function login(IngresoSolicitanteRequest $request, AccesoSolicitanteService $acceso): RedirectResponse
    {
        $solicitante = $acceso->verificarIngreso(
            $request->validated('correo'),
            $request->validated('contrasena'),
            (string) $request->ip(),
        );

        Auth::guard('solicitante')->login($solicitante, $request->boolean('recordar'));
        // Id de sesion nuevo: evita la fijacion de sesion.
        $request->session()->regenerate();

        $acceso->registrarIngreso($solicitante);

        return redirect()->intended(route('solicitante.solicitudes.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('solicitante')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('solicitante.login')->with('exito', 'Sesion cerrada.');
    }

    public function editContrasena(): View
    {
        return view('solicitante.cambiar-contrasena');
    }

    public function updateContrasena(CambioContrasenaSolicitanteRequest $request, AccesoSolicitanteService $acceso): RedirectResponse
    {
        $solicitante = $acceso->cambiarContrasena(
            $request->validated('correo'),
            $request->validated('contrasena_actual'),
            $request->validated('contrasena'),
            (string) $request->ip(),
        );

        // Si quien cambia la clave tenia la sesion abierta en este navegador,
        // se cierra: tiene que volver a entrar con la nueva.
        if (Auth::guard('solicitante')->id() === $solicitante->id) {
            Auth::guard('solicitante')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()
            ->route('solicitante.login')
            ->with('exito', 'Contrasena actualizada. Ingrese con la nueva.');
    }
}
