<?php

namespace App\Mail;

use App\Models\Solicitud;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al solicitante de que su pedido ya fue elaborado y puede reclamarlo.
 */
class PedidoListoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Solicitud $solicitud) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Su pedido {$this->solicitud->numero} esta listo para reclamar",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pedido-listo',
            with: [
                'solicitud' => $this->solicitud->loadMissing('items'),
                'almacen' => config('almacen'),
            ],
        );
    }
}
