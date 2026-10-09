<?php

namespace App\Services;

use App\Exceptions\DecisionSolicitudException;
use App\Mail\NuevaSolicitudMail;
use App\Mail\SolicitudAprobadaMail;
use App\Mail\SolicitudDenegadaMail;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Decision del solicitante del ERP sobre una solicitud por_aprobar:
 *   Aprobar -> pendiente (entra a la bandeja del almacen).
 *   Denegar -> rechazada (nunca llega al almacen).
 *
 * Cada transicion relee la solicitud con bloqueo, dentro de la transaccion, y
 * exige que siga por_aprobar y que sea del solicitante autenticado: dos clics
 * seguidos o dos pestanas no pueden decidir dos veces, y nadie decide sobre
 * una solicitud ajena aunque adivine el id.
 *
 * Los correos salen DESPUES de confirmar la transaccion y un fallo de SMTP
 * nunca tumba la decision (mismo criterio que notificarPedidoListo): se
 * registra en el log y en tbl_solicitudes.error_notificacion.
 */
class AprobacionSolicitudService
{
    /**
     * @param  array<string, string|int|null>  $referencias  columnas de
     *                                                       Solicitud::REFERENCIAS_APROBACION (con su nombre real, cod_OC);
     *                                                       cualquier otra clave se ignora
     * @param  array<int, array<string, string|null>>  $items  id del detalle =>
     *                                                         columnas de SolicitudItem::REFERENCIAS_APROBACION;
     *                                                         un id que no sea de ESTA solicitud se ignora
     *
     * @throws DecisionSolicitudException si ya no esta por aprobar o un codigo
     *                                    de maestra dejo de estar disponible
     */
    public function aprobar(int $solicitudId, SolicitanteErp $aprobador, array $referencias = [], array $items = []): Solicitud
    {
        $solicitud = DB::transaction(function () use ($solicitudId, $aprobador, $referencias, $items) {
            $solicitud = $this->releerPorAprobar($solicitudId, $aprobador);

            // Todo se resuelve (y puede fallar) antes de escribir la primera
            // fila; igual, cualquier excepcion revierte la transaccion entera.
            $cabecera = $this->referenciasAGuardar($referencias);
            $porItem = $this->referenciasDeItemsAGuardar($solicitud, $items);

            $solicitud->forceFill([
                'estado' => Solicitud::ESTADO_PENDIENTE,
                'aprobada_at' => now(),
            ] + $cabecera)->save();

            foreach ($porItem as [$item, $columnas]) {
                $item->forceFill($columnas)->save();
            }

            return $solicitud;
        });

        Log::info('Solicitud aprobada por el solicitante.', [
            'solicitud' => $solicitud->numero,
            'codigo_erp' => $aprobador->col_codigo_erp,
        ]);

        $solicitud->load('items');

        $this->notificarDecision($solicitud, new SolicitudAprobadaMail($solicitud), 'aprobacion');
        $this->avisarAlAlmacen($solicitud);

        return $solicitud;
    }

    public function denegar(int $solicitudId, SolicitanteErp $aprobador, ?string $motivo): Solicitud
    {
        $motivo = trim((string) $motivo);

        $solicitud = DB::transaction(function () use ($solicitudId, $aprobador, $motivo) {
            $solicitud = $this->releerPorAprobar($solicitudId, $aprobador);

            $solicitud->forceFill([
                'estado' => Solicitud::ESTADO_RECHAZADA,
                'denegada_at' => now(),
                'motivo_denegacion' => $motivo === '' ? null : $motivo,
            ])->save();

            return $solicitud;
        });

        Log::info('Solicitud denegada por el solicitante.', [
            'solicitud' => $solicitud->numero,
            'codigo_erp' => $aprobador->col_codigo_erp,
        ]);

        $solicitud->load('items');

        $this->notificarDecision($solicitud, new SolicitudDenegadaMail($solicitud), 'denegacion');

        return $solicitud;
    }

    /**
     * Relee con bloqueo de fila (updlock + holdlock en SQL Server) y aplica las
     * dos reglas de la decision. La pertenencia es la de siempre,
     * Solicitud::scopeDelSolicitante(): no se duplica aqui.
     *
     * @throws ModelNotFoundException si no existe o no es del solicitante (404:
     *                                no se confirma que la solicitud exista)
     * @throws DecisionSolicitudException si ya no esta por aprobar
     */
    private function releerPorAprobar(int $solicitudId, SolicitanteErp $aprobador): Solicitud
    {
        $solicitud = Solicitud::whereKey($solicitudId)
            ->delSolicitante($aprobador)
            ->lockForUpdate()
            ->first();

        if ($solicitud === null) {
            throw (new ModelNotFoundException)->setModel(Solicitud::class, [$solicitudId]);
        }

        if ($solicitud->estado !== Solicitud::ESTADO_POR_APROBAR) {
            throw new DecisionSolicitudException(
                "La solicitud {$solicitud->numero} ya no esta por aprobar: la decision ya se habia tomado."
            );
        }

        return $solicitud;
    }

    /**
     * Lista blanca de las columnas de referencia (nada fuera de
     * REFERENCIAS_APROBACION llega al forceFill). Cada codigo de maestra se
     * RELEE aqui, dentro de la transaccion: el FormRequest lo valido, pero la
     * maestra pudo inactivarse entre tanto. Se guarda la clave tal como esta
     * en la maestra, no la que escribio el formulario (la collation es CI:
     * "abc" encontraria "ABC").
     *
     * Todas se escriben, tambien las que llegan vacias: aprobar sin
     * diligenciarlas las deja en null.
     *
     * @param  array<string, string|int|null>  $referencias
     * @return array<string, string|int|null>
     */
    private function referenciasAGuardar(array $referencias): array
    {
        $columnas = [];

        foreach (array_keys(Solicitud::REFERENCIAS_APROBACION) as $columna) {
            $valor = $referencias[$columna] ?? null;
            $relacion = Solicitud::REFERENCIAS_DE_MAESTRA[$columna] ?? null;

            if ($relacion !== null && $valor !== null) {
                $modelo = (new Solicitud)->{$relacion}()->getRelated();
                $maestra = $modelo::activaPorCodigo((string) $valor);

                if ($maestra === null) {
                    throw new DecisionSolicitudException(
                        'El '.mb_strtolower(Solicitud::REFERENCIAS_APROBACION[$columna])
                        ." elegido ({$valor}) ya no esta disponible. Elija otro y apruebe de nuevo."
                    );
                }

                $valor = (string) $maestra->getKey();
            }

            $columnas[$columna] = $valor;
        }

        return $columnas;
    }

    /**
     * Columnas de referencia por item, listas para guardar. Mismo criterio que
     * la cabecera, con tres reglas propias:
     *
     *  - Lista blanca de ids: solo los items de ESTA solicitud (leidos aqui,
     *    con la solicitud ya bloqueada). Un id ajeno se ignora: nunca se toca
     *    un item de otra solicitud.
     *  - Se escriben TODOS los items de la solicitud; el que no llega en la
     *    peticion queda con sus referencias en null, como la cabecera.
     *  - cod_unidad_negocio se impone siempre (UNIDAD_NEGOCIO_POR_DEFECTO).
     *
     * Los codigos se RELEEN en su maestra activa con una consulta por maestra
     * (no por item), y se exige de nuevo el largo de la columna y la forma de
     * la cantidad: el FormRequest ya lo hizo, pero la maestra pudo cambiar y
     * este metodo no debe confiar en quien lo llama.
     *
     * @param  array<int, array<string, string|null>>  $items
     * @return list<array{0: SolicitudItem, 1: array<string, string|null>}>
     */
    private function referenciasDeItemsAGuardar(Solicitud $solicitud, array $items): array
    {
        $detalle = $solicitud->items()->orderBy('id')->get();
        $pedidos = [];

        foreach ($detalle as $item) {
            $campos = $items[$item->id] ?? [];
            $columnas = [];

            foreach (array_keys(SolicitudItem::REFERENCIAS_APROBACION) as $columna) {
                $valor = $campos[$columna] ?? null;
                $valor = $valor === null ? null : trim((string) $valor);
                $columnas[$columna] = $valor === '' ? null : $valor;
            }

            $columnas['cod_unidad_negocio'] = SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO;
            $pedidos[] = [$item, $columnas];
        }

        foreach ($pedidos as [$item, $columnas]) {
            $this->exigirFormaDeItem($item, $columnas);
        }

        foreach (SolicitudItem::REFERENCIAS_DE_MAESTRA as $columna => $relacion) {
            $codigos = array_values(array_filter(array_map(fn ($pedido) => $pedido[1][$columna], $pedidos), fn ($valor) => $valor !== null));
            $modelo = (new SolicitudItem)->{$relacion}()->getRelated();
            $activas = $modelo::activasPorCodigos($codigos);

            foreach ($pedidos as $i => [$item, $columnas]) {
                $valor = $columnas[$columna];

                if ($valor === null) {
                    continue;
                }

                $maestra = $activas[mb_strtolower($valor)] ?? null;

                if ($maestra === null) {
                    throw new DecisionSolicitudException(
                        'En el item '.$item->codigo.', la opcion de '.mb_strtolower(rtrim(SolicitudItem::REFERENCIAS_APROBACION[$columna], '.'))
                        ." ({$valor}) ya no esta disponible. Elija otra y apruebe de nuevo."
                    );
                }

                // La clave tal como esta en la maestra (collation CI).
                $pedidos[$i][1][$columna] = (string) $maestra->getKey();
            }
        }

        return $pedidos;
    }

    /**
     * @param  array<string, string|null>  $columnas
     *
     * @throws DecisionSolicitudException
     */
    private function exigirFormaDeItem(SolicitudItem $item, array $columnas): void
    {
        foreach (SolicitudItem::LARGO_MAXIMO as $columna => $largo) {
            if ($columnas[$columna] !== null && mb_strlen($columnas[$columna]) > $largo) {
                throw new DecisionSolicitudException(
                    "En el item {$item->codigo}, ".mb_strtolower(rtrim(SolicitudItem::REFERENCIAS_APROBACION[$columna], '.'))
                    ." admite hasta {$largo} caracteres."
                );
            }
        }

        $cantidad = $columnas['cantidad'];

        if ($cantidad !== null && (! preg_match(SolicitudItem::PATRON_CANTIDAD, $cantidad) || (float) $cantidad <= 0)) {
            throw new DecisionSolicitudException(
                "En el item {$item->codigo}, la cantidad debe ser un numero mayor que cero con hasta "
                .SolicitudItem::CANTIDAD_DECIMALES.' decimales.'
            );
        }
    }

    /**
     * Un solo correo por decision, con todos los destinatarios en el "Para":
     * hoy el solicitante y quien hizo la solicitud comparten buzon y
     * destinatariosDeDecision() ya quita los repetidos.
     */
    private function notificarDecision(Solicitud $solicitud, Mailable $correo, string $tipo): bool
    {
        try {
            $destinos = $solicitud->destinatariosDeDecision();

            if ($destinos === []) {
                throw new \RuntimeException('El solicitante no tiene correo registrado en el ERP.');
            }

            Mail::to($destinos)->send($correo);

            $solicitud->forceFill(['error_notificacion' => null])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error("No se pudo enviar el aviso de {$tipo} de la solicitud", [
                'solicitud' => $solicitud->numero,
                'error' => $e->getMessage(),
            ]);

            $solicitud->forceFill([
                'error_notificacion' => mb_substr("Aviso de {$tipo}: ".$e->getMessage(), 0, 500),
            ])->save();

            return false;
        }
    }

    /**
     * Aviso opcional al almacen (ALMACEN_NOTIFICACION_EMAIL) de que entro una
     * solicitud a su bandeja. Sale al APROBAR y no al crear: antes de eso el
     * almacen no la ve ni la puede atender, el enlace del correo daria 404 y
     * una solicitud que termine denegada solo habria sido ruido.
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
            Mail::to($destinos->all())->send(new NuevaSolicitudMail($solicitud));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar al almacen de la nueva solicitud', [
                'solicitud' => $solicitud->numero,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
