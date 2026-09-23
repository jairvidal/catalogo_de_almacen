<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSolicitudRequest;
use App\Models\Solicitud;
use App\Services\Carrito;
use App\Services\SolicitudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SolicitudController extends Controller
{
    /**
     * Formulario con los datos del solicitante (nombre, cedula y correo).
     */
    public function create(Carrito $carrito): View|RedirectResponse
    {
        if ($carrito->estaVacio()) {
            return redirect()
                ->route('catalogo.index')
                ->with('error', 'Primero seleccione los repuestos que necesita.');
        }

        return view('solicitudes.create', [
            'lineas' => $carrito->lineas(),
            'unidades' => $carrito->cantidadUnidades(),
        ]);
    }

    public function store(StoreSolicitudRequest $request, SolicitudService $servicio): RedirectResponse
    {
        $solicitud = $servicio->crearConReintento($request->validated());

        // El numero queda en sesion para que la pantalla de confirmacion no sea
        // una URL adivinable con el id de cualquier otra solicitud.
        return redirect()
            ->route('solicitudes.confirmacion', $solicitud->numero)
            ->with('solicitud_recien_creada', $solicitud->numero);
    }

    public function confirmacion(string $numero): View|RedirectResponse
    {
        if (session('solicitud_recien_creada') !== $numero) {
            return redirect()->route('solicitudes.consultar', ['numero' => $numero]);
        }

        $solicitud = Solicitud::with('items')->where('numero', $numero)->firstOrFail();

        return view('solicitudes.confirmacion', ['solicitud' => $solicitud]);
    }

    /**
     * Consulta publica del estado. Pide numero + cedula para que nadie vea
     * los pedidos de otra persona solo probando consecutivos.
     */
    public function consultar(Request $request): View
    {
        $numero = trim((string) $request->query('numero'));
        $cedula = trim((string) $request->query('cedula'));
        $solicitud = null;
        $noEncontrada = false;

        if ($numero !== '' && $cedula !== '') {
            // Se busca por el numero normalizado para que siga funcionando el
            // formato historico (SOL-2026-000004) de los correos ya enviados.
            // Un texto que no sea un numero de solicitud no consulta la base.
            $buscado = Solicitud::normalizarNumero($numero);

            $solicitud = $buscado === null ? null : Solicitud::with('items')
                ->where('numero', $buscado)
                ->where('solicitante_cedula', $cedula)
                ->first();

            $noEncontrada = $solicitud === null;
        }

        return view('solicitudes.consultar', [
            'solicitud' => $solicitud,
            'numero' => $numero,
            'cedula' => $cedula,
            'noEncontrada' => $noEncontrada,
        ]);
    }
}
