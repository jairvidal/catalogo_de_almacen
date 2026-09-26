<?php

namespace App\Mail;

use App\Models\SolicitanteErp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Contrasena del portal de aprobacion, generada por el servidor.
 *
 * NO implementa ShouldQueue a proposito: con una cola, la contrasena en claro
 * quedaria serializada en la tabla jobs (y en failed_jobs si fallara), y
 * AsignacionContrasenaService necesita saber en el acto si el envio fallo para
 * revertir la asignacion.
 *
 * La contrasena es PRIVADA: Laravel pasa a la vista las propiedades publicas
 * de un Mailable, y esta solo debe llegar a la plantilla por `with`.
 */
class ContrasenaSolicitanteMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly SolicitanteErp $solicitante,
        private readonly string $contrasena,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Su acceso al portal de aprobacion de solicitudes',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contrasena-solicitante',
            with: [
                'nombre' => $this->solicitante->col_nombre,
                'usuario' => $this->solicitante->correoNormalizado(),
                'contrasena' => $this->contrasena,
                'url' => route('solicitante.login'),
                'urlCambio' => route('solicitante.contrasena.edit'),
                'almacen' => config('almacen'),
            ],
        );
    }
}
