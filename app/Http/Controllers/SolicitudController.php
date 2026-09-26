<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSolicitudRequest;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Services\Carrito;
use App\Services\SolicitudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SolicitudController extends Controller
{
    /**
     * Formulario de envio: el solicitante se elige de la lista del ERP.
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
            // Al volver con errores de validacion, el cuadro combinado muestra
            // el nombre del solicitante que ya se habia elegido.
            'solicitanteElegido' => $this->solicitantePorId(old('solicitante_erp_id'), soloActivos: true),
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
     * Consulta publica del estado. Pide numero + solicitante (elegido en el
     * mismo cuadro combinado del formulario) y la solicitud tiene que ser de
     * ese solicitante: la regla vive en Solicitud::scopeDelSolicitante(), que
     * tambien casa por cedula las solicitudes historicas sin FK.
     *
     * El solicitante se acepta aunque este inactivo: una persona que salio del
     * ERP puede seguir consultando un pedido que ya hizo.
     */
    public function consultar(Request $request): View
    {
        $numero = trim((string) $request->query('numero'));
        $solicitante = $this->solicitantePorId($request->query('solicitante'), soloActivos: false);
        $solicitud = null;
        $noEncontrada = false;

        if ($numero !== '' && $request->filled('solicitante')) {
            // Se busca por el numero normalizado para que siga funcionando el
            // formato historico (SOL-2026-000004) de los correos ya enviados.
            // Un texto que no sea un numero de solicitud no consulta la base.
            $buscado = Solicitud::normalizarNumero($numero);

            $solicitud = ($buscado === null || $solicitante === null) ? null : Solicitud::with('items')
                ->where('numero', $buscado)
                ->delSolicitante($solicitante)
                ->first();

            $noEncontrada = $solicitud === null;
        }

        return view('solicitudes.consultar', [
            'solicitud' => $solicitud,
            'numero' => $numero,
            'solicitanteElegido' => $solicitante,
            'noEncontrada' => $noEncontrada,
        ]);
    }

    /**
     * Solicitante por el id que llega del formulario, o null si el valor no es
     * un id o no existe. Nunca lanza: un id manipulado es "no encontrado".
     */
    private function solicitantePorId(mixed $id, bool $soloActivos): ?SolicitanteErp
    {
        $id = trim(is_scalar($id) ? (string) $id : '');

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return SolicitanteErp::whereKey((int) $id)
            ->when($soloActivos, fn ($consulta) => $consulta->where('col_activo', true))
            ->first();
    }
}
