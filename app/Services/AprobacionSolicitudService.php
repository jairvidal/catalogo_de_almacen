<?php

namespace App\Services;

use App\Exceptions\DecisionSolicitudException;
use App\Mail\NuevaSolicitudMail;
use App\Mail\SolicitudAprobadaMail;
use App\Mail\SolicitudDenegadaMail;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
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
 * registra en el log y en solicitudes.error_notificacion.
 */
class AprobacionSolicitudService
{
    public function aprobar(int $solicitudId, SolicitanteErp $aprobador): Solicitud
    {
        $solicitud = DB::transaction(function () use ($solicitudId, $aprobador) {
            $solicitud = $this->releerPorAprobar($solicitudId, $aprobador);

            $solicitud->forceFill([
                'estado' => Solicitud::ESTADO_PENDIENTE,
                'aprobada_at' => now(),
            ])->save();

            return $solicitud;
        });

        Log::info('Solicitud aprobada por el solicitante.', [
            'solicitud' => $solicitud->numero,
            'solicitante_erp_id' => $aprobador->id,
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
            'solicitante_erp_id' => $aprobador->id,
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
