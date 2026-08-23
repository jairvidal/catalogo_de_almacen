<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Repuesto;
use App\Models\Solicitud;
use App\Services\SolicitudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SolicitudAdminController extends Controller
{
    /**
     * Bandeja del almacenista: todas las solicitudes, filtrables por estado.
     */
    public function index(Request $request): View
    {
        $estado = $request->string('estado')->toString();
        $termino = $request->string('q')->toString();

        $solicitudes = Solicitud::query()
            ->with('atendidaPor')
            ->withCount('items')
            ->estado($estado !== '' ? $estado : null)
            ->buscar($termino)
            ->orderByRaw("CASE estado
                WHEN 'pendiente' THEN 1
                WHEN 'en_proceso' THEN 2
                WHEN 'listo' THEN 3
                ELSE 4 END")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $conteos = Solicitud::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('admin.solicitudes.index', [
            'solicitudes' => $solicitudes,
            'conteos' => $conteos,
            'estadoActivo' => $estado,
            'termino' => $termino,
            'totalGeneral' => $conteos->sum(),
        ]);
    }

    /**
     * Detalle con id, nombre, cantidad y foto de cada item, mas el stock
     * actual del almacen para que el almacenista sepa si alcanza.
     */
    public function show(Solicitud $solicitud): View
    {
        $solicitud->load(['items.repuesto', 'atendidaPor']);

        return view('admin.solicitudes.show', ['solicitud' => $solicitud]);
    }

    /**
     * Toma la solicitud: pasa de pendiente a en proceso.
     */
    public function tomar(Solicitud $solicitud): RedirectResponse
    {
        if ($solicitud->estado !== Solicitud::ESTADO_PENDIENTE) {
            return back()->with('error', 'La solicitud ya fue tomada o cerrada.');
        }

        $solicitud->update([
            'estado' => Solicitud::ESTADO_EN_PROCESO,
            'atendida_por' => Auth::id(),
            'fecha_en_proceso' => now(),
        ]);

        return back()->with('exito', 'Solicitud marcada como "En proceso".');
    }

    /**
     * El pedido quedo elaborado: descuenta inventario y avisa por correo.
     */
    public function marcarListo(Request $request, Solicitud $solicitud, SolicitudService $servicio): RedirectResponse
    {
        if ($solicitud->esta_cerrada || $solicitud->estado === Solicitud::ESTADO_LISTO) {
            return back()->with('error', 'La solicitud ya fue elaborada o esta cerrada.');
        }

        $datos = $request->validate([
            'cantidades' => ['nullable', 'array'],
            'cantidades.*' => ['nullable', 'integer', 'min:0'],
            'nota_almacen' => ['nullable', 'string', 'max:1000'],
        ]);

        $cantidades = collect($datos['cantidades'] ?? [])
            ->mapWithKeys(fn ($valor, $itemId) => [(int) $itemId => (int) $valor])
            ->all();

        $servicio->marcarListo($solicitud, Auth::user(), $cantidades, $datos['nota_almacen'] ?? null);

        $solicitud->refresh();

        if ($solicitud->notificado_at) {
            return back()->with('exito', "Pedido elaborado. Se envio el aviso a {$solicitud->solicitante_email}.");
        }

        return back()->with(
            'error',
            'El pedido quedo elaborado, pero no se pudo enviar el correo. Revise la configuracion SMTP y use "Reenviar aviso".'
        );
    }

    /**
     * La persona llego y reclamo el pedido.
     */
    public function entregar(Solicitud $solicitud): RedirectResponse
    {
        if ($solicitud->estado !== Solicitud::ESTADO_LISTO) {
            return back()->with('error', 'Solo se puede entregar un pedido que este listo para reclamar.');
        }

        $solicitud->update([
            'estado' => Solicitud::ESTADO_ENTREGADA,
            'fecha_entrega' => now(),
        ]);

        return back()->with('exito', 'Entrega registrada.');
    }

    public function rechazar(Request $request, Solicitud $solicitud): RedirectResponse
    {
        if ($solicitud->esta_cerrada) {
            return back()->with('error', 'La solicitud ya esta cerrada.');
        }

        $datos = $request->validate([
            'nota_almacen' => ['required', 'string', 'max:1000'],
        ], [], ['nota_almacen' => 'motivo']);

        // Si ya se habia descontado inventario (estado listo), se devuelve.
        if ($solicitud->estado === Solicitud::ESTADO_LISTO) {
            foreach ($solicitud->items as $item) {
                if ($item->cantidad_entregada > 0) {
                    Repuesto::whereKey($item->repuesto_id)->increment('existencia', $item->cantidad_entregada);
                    $item->update(['cantidad_entregada' => 0]);
                }
            }
        }

        $solicitud->update([
            'estado' => Solicitud::ESTADO_RECHAZADA,
            'nota_almacen' => $datos['nota_almacen'],
            'atendida_por' => Auth::id(),
        ]);

        return back()->with('exito', 'Solicitud rechazada.');
    }

    /**
     * Reenvia el aviso de "listo para reclamar" cuando el envio anterior fallo.
     */
    public function reenviarAviso(Solicitud $solicitud, SolicitudService $servicio): RedirectResponse
    {
        if ($solicitud->estado !== Solicitud::ESTADO_LISTO) {
            return back()->with('error', 'Solo se avisa cuando el pedido esta listo para reclamar.');
        }

        return $servicio->notificarPedidoListo($solicitud)
            ? back()->with('exito', "Aviso reenviado a {$solicitud->solicitante_email}.")
            : back()->with('error', 'No se pudo enviar el correo. Revise la configuracion SMTP.');
    }
}
