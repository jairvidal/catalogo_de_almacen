<?php

namespace App\Services;

use App\Mail\NuevaSolicitudMail;
use App\Mail\PedidoListoMail;
use App\Models\Repuesto;
use App\Models\Solicitud;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class SolicitudService
{
    public function __construct(private readonly Carrito $carrito) {}

    /**
     * Crea la solicitud a partir del carrito de la sesion.
     *
     * @param  array<string, mixed>  $datosSolicitante
     *
     * @throws ValidationException si el carrito esta vacio o el stock ya no alcanza.
     */
    public function crearDesdeCarrito(array $datosSolicitante): Solicitud
    {
        $lineas = $this->carrito->lineas();

        if ($lineas->isEmpty()) {
            throw ValidationException::withMessages([
                'carrito' => 'No hay repuestos seleccionados. Agregue al menos uno desde el catalogo.',
            ]);
        }

        $solicitud = DB::transaction(function () use ($lineas, $datosSolicitante) {
            // Se releen los repuestos dentro de la transaccion para validar contra
            // el stock real y no contra el que vio el usuario al armar el carrito.
            $repuestos = Repuesto::whereIn('id', $lineas->pluck('id'))->get()->keyBy('id');

            $errores = [];

            foreach ($lineas as $linea) {
                $repuesto = $repuestos->get($linea->id);

                if (! $repuesto || ! $repuesto->activo) {
                    $errores[] = "El repuesto \"{$linea->nombre}\" ya no esta disponible en el catalogo.";

                    continue;
                }

                if ($linea->cantidad_pedida > $repuesto->cantidad_disponible) {
                    $errores[] = "De \"{$repuesto->nombre}\" solo quedan {$repuesto->cantidad_disponible} unidades.";
                }
            }

            if ($errores !== []) {
                throw ValidationException::withMessages(['carrito' => $errores]);
            }

            $solicitud = new Solicitud($datosSolicitante);
            $solicitud->estado = Solicitud::ESTADO_PENDIENTE;
            $solicitud->numero = $this->siguienteNumero();
            $solicitud->save();

            foreach ($lineas as $linea) {
                $solicitud->items()->create([
                    'repuesto_id' => $linea->id,
                    'codigo' => $linea->codigo,
                    'nombre' => $linea->nombre,
                    'foto' => $linea->foto,
                    'cantidad_solicitada' => $linea->cantidad_pedida,
                ]);
            }

            return $solicitud;
        });

        $this->carrito->vaciar();

        $this->avisarAlAlmacen($solicitud);

        return $solicitud->load('items');
    }

    /**
     * Consecutivo por anio: SOL-2026-000001.
     *
     * Se calcula dentro de la transaccion de creacion; el indice unico sobre
     * `numero` es la garantia final ante concurrencia y por eso store() reintenta.
     */
    private function siguienteNumero(): string
    {
        $anio = now()->format('Y');
        $prefijo = "SOL-{$anio}-";

        $ultimo = Solicitud::where('numero', 'like', $prefijo.'%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Marca el pedido como listo, descuenta el inventario y notifica por correo.
     *
     * @param  array<int, int>  $cantidadesEntregadas  item_id => cantidad realmente alistada
     */
    public function marcarListo(Solicitud $solicitud, User $usuario, array $cantidadesEntregadas, ?string $nota = null): Solicitud
    {
        DB::transaction(function () use ($solicitud, $usuario, $cantidadesEntregadas, $nota) {
            $solicitud->load('items.repuesto');

            foreach ($solicitud->items as $item) {
                $cantidad = (int) ($cantidadesEntregadas[$item->id] ?? $item->cantidad_solicitada);
                $cantidad = max(0, min($cantidad, $item->cantidad_solicitada));

                $repuesto = $item->repuesto;

                if ($repuesto && $cantidad > 0) {
                    // Descuento condicionado: si otro pedido consumio el stock
                    // entre tanto, el UPDATE no afecta filas y se topa la cantidad.
                    $afectadas = Repuesto::whereKey($repuesto->id)
                        ->where('cantidad_disponible', '>=', $cantidad)
                        ->decrement('cantidad_disponible', $cantidad);

                    if ($afectadas === 0) {
                        $disponible = (int) Repuesto::whereKey($repuesto->id)->value('cantidad_disponible');
                        $cantidad = max(0, $disponible);

                        if ($cantidad > 0) {
                            Repuesto::whereKey($repuesto->id)->decrement('cantidad_disponible', $cantidad);
                        }
                    }
                }

                $item->cantidad_entregada = $cantidad;
                $item->save();
            }

            $solicitud->estado = Solicitud::ESTADO_LISTO;
            $solicitud->atendida_por = $usuario->id;
            $solicitud->fecha_listo = now();
            $solicitud->fecha_en_proceso ??= now();
            $solicitud->nota_almacen = $nota ?: $solicitud->nota_almacen;
            $solicitud->save();
        });

        $this->notificarPedidoListo($solicitud->fresh('items'));

        return $solicitud->refresh()->load('items');
    }

    /**
     * Envia (o reenvia) el aviso de "pedido listo" al solicitante.
     * Un fallo de SMTP no debe tumbar el cambio de estado: se registra y ya.
     */
    public function notificarPedidoListo(Solicitud $solicitud): bool
    {
        try {
            Mail::to($solicitud->solicitante_email)->send(new PedidoListoMail($solicitud));

            $solicitud->forceFill([
                'notificado_at' => now(),
                'error_notificacion' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de pedido listo', [
                'solicitud' => $solicitud->numero,
                'error' => $e->getMessage(),
            ]);

            $solicitud->forceFill([
                'error_notificacion' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return false;
        }
    }

    /**
     * Aviso opcional al almacen cuando entra una solicitud nueva.
     */
    private function avisarAlAlmacen(Solicitud $solicitud): void
    {
        $destinos = collect(explode(',', (string) config('almacen.notificacion_email')))
            ->map(fn ($correo) => trim($correo))
            ->filter()
            ->values();

        if ($destinos->isEmpty()) {
            return;
        }

        try {
            Mail::to($destinos->all())->send(new NuevaSolicitudMail($solicitud->load('items')));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar al almacen de la nueva solicitud', [
                'solicitud' => $solicitud->numero,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reintenta la creacion cuando dos personas confirman al mismo tiempo y
     * chocan contra el indice unico del consecutivo.
     *
     * @param  array<string, mixed>  $datosSolicitante
     */
    public function crearConReintento(array $datosSolicitante, int $intentos = 3): Solicitud
    {
        for ($i = 1; $i <= $intentos; $i++) {
            try {
                return $this->crearDesdeCarrito($datosSolicitante);
            } catch (QueryException $e) {
                // 2601/2627 = violacion de indice unico en SQL Server.
                $esDuplicado = in_array((int) ($e->errorInfo[1] ?? 0), [2601, 2627], true);

                if (! $esDuplicado || $i === $intentos) {
                    throw $e;
                }

                usleep(random_int(50_000, 200_000));
            }
        }

        throw new \RuntimeException('No se pudo generar el consecutivo de la solicitud.');
    }
}
