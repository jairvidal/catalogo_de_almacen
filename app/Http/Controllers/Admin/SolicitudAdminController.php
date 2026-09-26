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
        // El estado llega por la URL (tarjetas de conteo y select de la fila de
        // filtros comparten el mismo parametro). Solo se acepta una clave de
        // Solicitud::ESTADOS; cualquier otra cosa equivale a "todas".
        $estado = $request->string('estado')->toString();
        $estado = array_key_exists($estado, Solicitud::ESTADOS) ? $estado : '';

        $conteos = Solicitud::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return view('admin.solicitudes.index', [
            ...$this->bandeja($request, $estado !== '' ? $estado : null, true),
            'conteos' => $conteos,
            'totalGeneral' => $conteos->sum(),
            'mostrarMetricas' => true,
            'estadoFijo' => null,
            'rutaListado' => 'admin.solicitudes.index',
            'parametrosBase' => [],
        ]);
    }

    /**
     * Bandeja de lo que ya esta elaborado y espera a que lo reclamen.
     *
     * Reusa la vista del listado, pero aqui el estado lo fija la RUTA y no la
     * URL: por eso no se pintan las tarjetas de filtro por estado ni se
     * consultan sus conteos, y un `estado` en la URL se ignora.
     */
    public function listos(Request $request): View
    {
        return view('admin.solicitudes.index', [
            ...$this->bandeja($request, Solicitud::ESTADO_LISTO, false),
            'mostrarMetricas' => false,
            'estadoFijo' => Solicitud::ESTADO_LISTO,
            'rutaListado' => 'admin.solicitudes.listos',
            'parametrosBase' => [],
        ]);
    }

    /**
     * Consulta comun de las dos bandejas: mismos filtros por columna, mismo
     * orden y misma paginacion. Un solo sitio para que no se separen.
     *
     * Devuelve tambien lo que la vista necesita para repintar los filtros y
     * armar los enlaces de orden, ya depurado: la vista no vuelve a leer el
     * request.
     *
     * @param  bool  $estadoPorUrl  si el estado forma parte de la consulta que
     *                              conservan los enlaces (listado general) o lo
     *                              fija la ruta (Listos)
     * @return array<string, mixed>
     */
    private function bandeja(Request $request, ?string $estado, bool $estadoPorUrl): array
    {
        $filtros = [];

        foreach (['numero', 'solicitante', 'items', 'atendida'] as $columna) {
            $filtros[$columna] = trim($request->string($columna)->toString());
        }

        // Lista blanca: un `orden` desconocido cae al orden por defecto.
        $orden = $request->string('orden')->toString();
        $orden = in_array($orden, Solicitud::COLUMNAS_BANDEJA, true) ? $orden : null;
        $direccion = $request->string('direccion')->toString() === 'desc' ? 'desc' : 'asc';

        // Lo que conservan la paginacion, los enlaces de orden y las tarjetas:
        // solo parametros ya depurados, nunca el query string crudo.
        $consulta = array_filter([
            ...$filtros,
            'estado' => $estadoPorUrl ? (string) $estado : '',
            'orden' => (string) $orden,
            'direccion' => $orden !== null ? $direccion : '',
        ], fn ($valor) => $valor !== '');

        $solicitudes = Solicitud::query()
            ->with('atendidaPor')
            ->withCount('items')
            ->estado($estado)
            ->filtrarPorColumnas($filtros)
            ->ordenarBandeja($orden, $direccion)
            ->paginate(15)
            ->appends($consulta);

        return [
            'solicitudes' => $solicitudes,
            'filtros' => $filtros,
            'estadoActivo' => (string) $estado,
            'orden' => $orden,
            'direccion' => $direccion,
            'consulta' => $consulta,
        ];
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
            return back()->with('exito', "Pedido elaborado. Se envio el aviso a {$solicitud->correoDeAviso()}.");
        }

        return back()->with('error', 'El pedido quedo elaborado, pero no se pudo enviar el correo. '.$this->causaSinAviso($solicitud).' y use "Reenviar aviso".');
    }

    /**
     * Pista para el almacenista cuando el aviso no salio: sin correo en el ERP
     * no hay nada que revisar en el SMTP.
     */
    private function causaSinAviso(Solicitud $solicitud): string
    {
        return $solicitud->correoDeAviso() === null
            ? 'El solicitante no tiene correo registrado en el ERP: corrijalo en el ERP, vuelva a importar'
            : 'Revise la configuracion SMTP';
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
            ? back()->with('exito', "Aviso reenviado a {$solicitud->correoDeAviso()}.")
            : back()->with('error', 'No se pudo enviar el correo. '.$this->causaSinAviso($solicitud).'.');
    }
}
