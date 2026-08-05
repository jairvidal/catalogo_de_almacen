<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.solicitudes.index');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'usuario',
            'password' => 'contrasena',
        ]);

        // `activo` entra en las credenciales para que un usuario deshabilitado
        // no pueda entrar aunque la contrasena siga siendo correcta.
        if (! Auth::attempt($credenciales + ['activo' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas o el usuario esta inactivo.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.solicitudes.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalogo.index')->with('exito', 'Sesion cerrada.');
    }
}
