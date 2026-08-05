<?php

namespace App\Mail;

use App\Models\Solicitud;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso interno al almacen de que entro una solicitud nueva al panel.
 */
class NuevaSolicitudMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Solicitud $solicitud) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nueva solicitud de repuestos {$this->solicitud->numero}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nueva-solicitud',
            with: [
                'solicitud' => $this->solicitud->loadMissing('items'),
                'url' => route('admin.solicitudes.show', $this->solicitud),
            ],
        );
    }
}
