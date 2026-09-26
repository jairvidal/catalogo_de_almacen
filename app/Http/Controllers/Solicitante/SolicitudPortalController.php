<?php

namespace App\Http\Controllers\Solicitante;

use App\Exceptions\DecisionSolicitudException;
use App\Http\Controllers\Controller;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Services\AprobacionSolicitudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Portal del solicitante autenticado: sus solicitudes y la decision sobre las
 * que estan por aprobar.
 *
 * La pertenencia la decide SIEMPRE Solicitud::scopeDelSolicitante() (FK y, para
 * las historicas, la cedula); una solicitud ajena responde 404 y no 403, para no
 * confirmar que existe.
 */
class SolicitudPortalController extends Controller
{
    public function index(Request $request): View
    {
        $solicitante = $this->solicitante();

        // Lista blanca: solo "por_aprobar" o nada.
        $filtro = $request->string('filtro')->toString() === Solicitud::ESTADO_POR_APROBAR
            ? Solicitud::ESTADO_POR_APROBAR
            : '';

        $porAprobar = Solicitud::query()
            ->delSolicitante($solicitante)
            ->where('estado', Solicitud::ESTADO_POR_APROBAR)
            ->count();

        $solicitudes = Solicitud::query()
            ->delSolicitante($solicitante)
            ->withCount('items')
            ->estado($filtro !== '' ? $filtro : null)
            ->porAprobarPrimero()
            ->paginate(15)
            ->appends(array_filter(['filtro' => $filtro]));

        return view('solicitante.solicitudes.index', [
            'solicitante' => $solicitante,
            'solicitudes' => $solicitudes,
            'porAprobar' => $porAprobar,
            'filtro' => $filtro,
        ]);
    }

    public function show(int $id): View
    {
        $solicitud = Solicitud::with('items')
            ->whereKey($id)
            ->delSolicitante($this->solicitante())
            ->firstOrFail();

        return view('solicitante.solicitudes.show', ['solicitud' => $solicitud]);
    }

    public function aprobar(int $id, AprobacionSolicitudService $servicio): RedirectResponse
    {
        try {
            $solicitud = $servicio->aprobar($id, $this->solicitante());
        } catch (DecisionSolicitudException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('solicitante.solicitudes.show', $solicitud->id)
            ->with(...$this->aviso($solicitud, "Solicitud {$solicitud->numero} aprobada. Ya paso al almacen."));
    }

    public function denegar(Request $request, int $id, AprobacionSolicitudService $servicio): RedirectResponse
    {
        $datos = $request->validate([
            'motivo_denegacion' => ['nullable', 'string', 'max:1000'],
        ], [], ['motivo_denegacion' => 'motivo']);

        try {
            $solicitud = $servicio->denegar($id, $this->solicitante(), $datos['motivo_denegacion'] ?? null);
        } catch (DecisionSolicitudException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('solicitante.solicitudes.show', $solicitud->id)
            ->with(...$this->aviso($solicitud, "Solicitud {$solicitud->numero} denegada."));
    }

    /**
     * La decision ya quedo guardada; si el correo no salio se dice, sin
     * detalles del servidor de correo.
     *
     * @return array{0: string, 1: string}
     */
    private function aviso(Solicitud $solicitud, string $mensaje): array
    {
        return $solicitud->error_notificacion === null
            ? ['exito', $mensaje.' Se envio el aviso por correo.']
            : ['error', $mensaje.' La decision quedo registrada, pero no se pudo enviar el aviso por correo.'];
    }

    private function solicitante(): SolicitanteErp
    {
        return Auth::guard('solicitante')->user();
    }
}
