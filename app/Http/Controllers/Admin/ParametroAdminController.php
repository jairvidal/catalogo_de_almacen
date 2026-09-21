<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SincronizacionEnCursoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ParametroRequest;
use App\Models\Parametro;
use App\Services\ParametroService;
use App\Services\SincronizadorStockRepuestos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ParametroAdminController extends Controller
{
    /**
     * Cuanto se le concede a una sincronizacion manual. Con QUEUE_CONNECTION=sync
     * la corrida ocurre DENTRO de esta peticion: pagina el ERP entero (decenas
     * de miles de items) y eso se pasa del max_execution_time habitual de 30s,
     * asi que el limite se sube a proposito en vez de dejar que PHP corte a
     * mitad de la escritura. El candado del servicio vence a los 900s, de modo
     * que un corte aqui no deja la sincronizacion bloqueada.
     */
    private const SEGUNDOS_SINCRONIZACION_MANUAL = 600;

    public function __construct(
        private readonly ParametroService $parametros,
        private readonly SincronizadorStockRepuestos $sincronizador,
    ) {}

    public function index(Request $request): View
    {
        $termino = $request->string('q')->toString();
        $filtro = $request->string('filtro')->toString();

        // Se resuelve una sola vez: la vista necesita la hora y los minutos, y
        // cada llamada es una consulta al cache.
        $enCursoDesde = $this->sincronizador->corridaEnCursoDesde();

        $parametros = Parametro::query()
            ->buscar($termino)
            ->when($filtro === 'activos', fn ($q) => $q->where('col_estado', Parametro::ESTADO_ACTIVO))
            ->when($filtro === 'inactivos', fn ($q) => $q->where('col_estado', Parametro::ESTADO_INACTIVO))
            ->orderByDesc('col_sistema')
            ->orderBy('col_nombre')
            ->paginate(20)
            ->withQueryString();

        return view('admin.parametros.index', [
            'parametros' => $parametros,
            'termino' => $termino,
            'filtro' => $filtro,
            'totales' => [
                'todos' => Parametro::count(),
                'activos' => Parametro::where('col_estado', Parametro::ESTADO_ACTIVO)->count(),
                'inactivos' => Parametro::where('col_estado', Parametro::ESTADO_INACTIVO)->count(),
            ],
            'sincronizacion' => [
                'modo' => $this->sincronizador->modo(),
                'es_manual' => $this->sincronizador->esManual(),
                'en_curso' => $enCursoDesde !== null,
                // Desde cuando: una corrida recien arrancada y un candado que
                // quedo colgado se veian igual en pantalla.
                'en_curso_desde' => $enCursoDesde?->format('h:i a'),
                'en_curso_minutos' => $enCursoDesde === null
                    ? null
                    : (int) floor((now()->getTimestamp() - $enCursoDesde->getTimestamp()) / 60),
                'minutos' => $this->sincronizador->minutosDeIntervalo(),
                // Las dos fechas van en hora de Colombia; la aplicacion
                // calcula en UTC (ver SincronizadorStockRepuestos).
                'ultima' => $this->sincronizador->ultimaCorridaFormateada(),
                'proxima' => $this->sincronizador->proximaCorrida()?->format('d/m/Y h:i a'),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.parametros.form', [
            'parametro' => new Parametro(['col_estado' => Parametro::ESTADO_ACTIVO]),
        ]);
    }

    public function store(ParametroRequest $request): RedirectResponse
    {
        $parametro = Parametro::create($request->validated());

        return redirect()
            ->route('admin.parametros.index')
            ->with('exito', "Parametro {$parametro->col_nombre} creado.");
    }

    public function edit(Parametro $parametro): View
    {
        return view('admin.parametros.form', ['parametro' => $parametro]);
    }

    public function update(ParametroRequest $request, Parametro $parametro): RedirectResponse
    {
        $parametro->update($request->validated());

        return redirect()
            ->route('admin.parametros.index')
            ->with('exito', "Parametro {$parametro->col_nombre} actualizado.");
    }

    /**
     * Anular no borra: el codigo pide el parametro por nombre y hace falta
     * distinguir el que se apago del que nunca existio.
     */
    public function destroy(Parametro $parametro): RedirectResponse
    {
        try {
            $this->parametros->anular($parametro);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('exito', "Parametro {$parametro->col_nombre} anulado; el sistema dejara de leerlo.");
    }

    /**
     * Boton "Actualizar": dispara la sincronizacion de stock con el ERP a
     * peticion del administrador.
     *
     * Responde JSON cuando la peticion viene del boton (fetch) y redirecciona
     * con flash cuando es un formulario normal, igual que CarritoController::store.
     */
    public function sincronizarStock(Request $request): JsonResponse|RedirectResponse
    {
        // El boton solo se pinta en modo manual; que no se pinte no es la
        // barrera, la barrera es esto. En automatico la corrida es del
        // programador y disparar una a mano adelantaria el turno sin que quede
        // registro de quien lo hizo.
        if (! $this->sincronizador->esManual()) {
            return $this->responder(
                $request,
                false,
                'El modo de actualizacion es automatico: la sincronizacion la dispara la tarea programada. '
                    .'Cambie inv.actualizar a "manual" para actualizar a mano.',
                409
            );
        }

        // Con QUEUE_CONNECTION=sync la sincronizacion corre dentro de esta
        // misma peticion, y paginar el ERP entero se pasa del limite habitual.
        set_time_limit(self::SEGUNDOS_SINCRONIZACION_MANUAL);

        try {
            $resultado = $this->sincronizador->sincronizar();
        } catch (SincronizacionEnCursoException $e) {
            // No es un fallo: el programador o otra pestana llego antes.
            return $this->responder($request, false, $e->getMessage(), 409);
        } catch (Throwable $e) {
            Log::error('Fallo la sincronizacion manual de stock con la API de inventario.', [
                'usuario_id' => $request->user()?->id,
                'excepcion' => $e->getMessage(),
            ]);

            // Solo se repite el mensaje de las excepciones que InventarioApiSidocsa
            // construye a proposito (dicen el codigo HTTP y jamas la credencial
            // ni el token). Cualquier otra cosa sale generica: un mensaje de la
            // base o del sistema de archivos delata rutas del servidor.
            $mensaje = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'No se pudo actualizar el stock desde el ERP. El detalle quedo en el log.';

            return $this->responder($request, false, $mensaje, 502);
        }

        // La marca de la ultima corrida viaja de vuelta para que el panel pinte
        // la fecha nueva sin recargar: el usuario aprieta el boton y ve la
        // linea "Ultima corrida" moverse, que es la unica confirmacion visible
        // de que la corrida quedo registrada.
        return $this->responder($request, true, $resultado->resumen(), 200, $resultado->contadores() + [
            'ultima' => $this->sincronizador->ultimaCorridaFormateada(),
        ]);
    }

    /**
     * Boton "Liberar bloqueo": suelta a la fuerza el candado y la marca de
     * "sincronizacion en curso".
     *
     * Existe porque el `finally` que las suelta no corre si el proceso muere de
     * golpe —IIS cortando la peticion a mitad de la corrida, tipicamente— y
     * entonces el panel se queda diciendo "en curso" y todo intento nuevo
     * rebota con 409 durante los 15 minutos que tardan en vencer. Antes de esto
     * la unica salida era borrar filas de `cache_locks` a mano en SQL Server.
     *
     * Va por formulario normal (no fetch): es una accion puntual, el resultado
     * se ve recargando la tarjeta y asi la vista vuelve a leer el estado real.
     */
    public function liberarSincronizacion(Request $request): RedirectResponse
    {
        $this->sincronizador->liberarBloqueo();

        Log::warning('Bloqueo de sincronizacion liberado desde el panel.', [
            'usuario_id' => $request->user()?->id,
        ]);

        return back()->with(
            'exito',
            'Bloqueo liberado. Si habia una corrida trabajando de verdad, espere a que termine antes de lanzar otra.'
        );
    }

    /**
     * JSON para el boton del panel, flash para un envio sin JavaScript.
     *
     * @param  array<string, int|bool|string|null>  $datos  contadores y fecha; nunca lleva credenciales.
     */
    private function responder(
        Request $request,
        bool $ok,
        string $mensaje,
        int $estado,
        array $datos = []
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'mensaje' => $mensaje] + $datos, $estado);
        }

        return back()->with($ok ? 'exito' : 'error', $mensaje);
    }
}
