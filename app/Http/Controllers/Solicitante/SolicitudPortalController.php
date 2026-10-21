<?php

namespace App\Http\Controllers\Solicitante;

use App\Exceptions\DecisionSolicitudException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AprobarSolicitudRequest;
use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Proyecto;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Services\AprobacionSolicitudService;
use App\Services\IntegracionErpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        $solicitud = Solicitud::query()
            ->whereKey($id)
            ->delSolicitante($this->solicitante())
            ->firstOrFail();

        // Las listas solo hacen falta mientras se puede aprobar; despues las
        // referencias (cabecera e items) se muestran en solo lectura, y solo
        // entonces se precargan las maestras de los items.
        if ($solicitud->esta_por_aprobar) {
            // El repuesto da la unidad de medida y la bodega de solo lectura de
            // cada item (SolicitudItem::COLUMNAS_DEL_REPUESTO).
            $solicitud->load(SolicitudItem::relacionRepuestoParaAprobar('items.'));
            [$opciones, $opcionesItem] = $this->opcionesDeAprobacion();
        } else {
            $solicitud->load(array_merge(
                ['items', 'centroOperacion', 'centroDeCosto', 'proyecto'],
                SolicitudItem::relacionesDeReferencia('items.')
            ));
            [$opciones, $opcionesItem] = [[], []];
        }

        return view('solicitante.solicitudes.show', [
            'solicitud' => $solicitud,
            'opcionesReferencia' => $opciones,
            'opcionesItem' => $opcionesItem,
        ]);
    }

    /**
     * Opciones de las listas de la cabecera y de los items, con UNA consulta
     * por maestra: las tres de la cabecera se reutilizan para los items (las
     * columnas del detalle tienen el largo de la clave de su maestra, asi que
     * todo codigo cabe) y todos los renglones comparten el mismo arreglo.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
     */
    private function opcionesDeAprobacion(): array
    {
        $cabecera = [
            'cod_centro_operacion' => CentroOperacion::opcionesActivas(),
            'cod_centro_de_costo' => CentroDeCosto::opcionesActivas(),
            'cod_proyecto' => Proyecto::opcionesActivas(),
        ];

        $porItem = [];

        foreach (SolicitudItem::REFERENCIAS_DE_MAESTRA as $columna => $relacion) {
            // Sin lista: unidad de medida y bodega salen del repuesto (solo lectura).
            if (array_key_exists($columna, SolicitudItem::COLUMNAS_DEL_REPUESTO)) {
                continue;
            }

            $porItem[$columna] = $cabecera[$columna]
                ?? (new SolicitudItem)->{$relacion}()->getRelated()::opcionesActivas();
        }

        return [$cabecera, $porItem];
    }

    public function aprobar(AprobarSolicitudRequest $request, int $id, AprobacionSolicitudService $servicio): RedirectResponse
    {
        try {
            $solicitud = $servicio->aprobar($id, $this->solicitante(), $request->referencias(), $request->items(), $request->observaciones());
        } catch (DecisionSolicitudException $e) {
            // withInput: si un codigo dejo de estar disponible, la persona no
            // tiene que volver a digitar lo demas.
            return back()->withInput()->with('error', $e->getMessage());
        }

        $respuesta = redirect()
            ->route('solicitante.solicitudes.show', $solicitud->id)
            ->with(...$this->aviso($solicitud, "Solicitud {$solicitud->numero} aprobada. Ya paso al almacen."));

        // Aviso aparte (flash "aviso"): la aprobacion SI quedo registrada; lo
        // que fallo fue el envio al ERP, y no se mezcla con el del correo.
        if ($solicitud->erp_error !== null) {
            $respuesta->with('aviso', 'La aprobacion quedo registrada, pero no se pudo enviar al ERP: '
                .Str::after($solicitud->erp_error, IntegracionErpService::PREFIJO_ERROR)
                .' Informe al administrador.');
        }

        return $respuesta;
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
