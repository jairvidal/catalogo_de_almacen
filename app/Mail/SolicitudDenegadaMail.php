<?php

namespace App\Mail;

use App\Models\Solicitud;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso a quien hizo la solicitud de que el solicitante del ERP la denego.
 * Quien la hizo no tiene correo propio: llega al correo del solicitante.
 */
class SolicitudDenegadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Solicitud $solicitud) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud No. {$this->solicitud->numero} denegada",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.solicitud-denegada',
            with: [
                'solicitud' => $this->solicitud->loadMissing('items'),
                'almacen' => config('almacen'),
            ],
        );
    }
}
