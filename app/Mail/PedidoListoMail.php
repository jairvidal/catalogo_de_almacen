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
            // El consecutivo ya no lleva el prefijo SOL-{anio}-, asi que en el
            // asunto va precedido de "No." para que seis digitos sueltos no se
            // lean como una cifra cualquiera.
            subject: "Su pedido No. {$this->solicitud->numero} esta listo para reclamar",
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
