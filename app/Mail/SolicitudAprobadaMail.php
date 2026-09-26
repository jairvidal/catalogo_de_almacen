<?php

namespace App\Mail;

use App\Models\Solicitud;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que el solicitante del ERP aprobo la solicitud y ya paso al
 * almacen. Un solo mensaje para el solicitante y para quien la hizo (hoy
 * comparten buzon): el texto le habla a los dos.
 */
class SolicitudAprobadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Solicitud $solicitud) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud No. {$this->solicitud->numero} aprobada",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-aprobada',
            with: [
                'solicitud' => $this->solicitud->loadMissing('items'),
                'almacen' => config('almacen'),
            ],
        );
    }
}
