@php
    /**
     * Las fotos se incrustan en el propio correo ($message->embed) porque el
     * servidor corre en la red interna y una URL absoluta no cargaria en Gmail.
     * Se limita el numero de imagenes para no inflar el mensaje.
     *
     * Los colores van EN LINEA y a mano: un correo no carga app.css, asi que
     * aqui no hay variables. Los valores espejan los tokens --ca-* :
     *   #f20a0a marca (solo filete y superficies sin texto pequeno encima)
     *   #c40808 marca oscura (rellenos que llevan texto blanco: 6.20:1)
     *   #fafafa #f2f1ef #e7e4e0 #77726b #625d57 #2e2b28  neutros calidos
     * Si cambia la paleta en app.css, cambiela tambien aqui.
     */
    $maxImagenes = 8;
    $incrustadas = 0;
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedido listo para reclamar</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f1ef;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2e2b28;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f1ef;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(28,26,24,.12);">

                {{-- Filete de senalizacion: el rojo pleno, sin texto encima. --}}
                <tr>
                    <td style="background-color:#f20a0a;height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                </tr>

                {{-- Banda de cabecera en grafito. El rojo ya dijo lo suyo arriba;
                     rellenar tambien la banda seria demasiada superficie saturada. --}}
                <tr>
                    <td style="background-color:#2e2b28;padding:24px 28px;">
                        <p style="margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#c8c3bc;">
                            {{ $almacen['nombre'] }}
                        </p>
                        <h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">
                            Su pedido ya esta listo para reclamar
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            Hola <strong>{{ $solicitud->solicitante_nombre }}</strong>,
                        </p>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                            El almacen ya elaboro el pedido correspondiente a su solicitud
                            <strong>{{ $solicitud->numero }}</strong>. Puede acercarse a reclamarlo
                            presentando su documento de identidad.
                        </p>

                        {{-- Dato operativo: fondo rosado tenue + filete rojo a la izquierda,
                             el mismo gesto que .alert en el panel. --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="background-color:#fef0ef;border:1px solid #e7e4e0;border-left:3px solid #f20a0a;border-radius:8px;margin-bottom:24px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:14px;line-height:1.7;">
                                    <strong style="color:#c40808;">Donde reclamarlo</strong><br>
                                    {{ $almacen['ubicacion'] }}<br>
                                    <strong style="color:#c40808;">Horario</strong><br>
                                    {{ $almacen['horario'] }}
                                </td>
                            </tr>
                        </table>

                        <h2 style="margin:0 0 12px;font-size:16px;font-weight:600;color:#1c1a18;">
                            Detalle del pedido
                        </h2>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="border-collapse:collapse;font-size:14px;">
                            <thead>
                            <tr style="background-color:#f2f1ef;">
                                <th align="left" style="padding:10px;border-bottom:2px solid #e7e4e0;color:#625d57;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Item</th>
                                <th align="left" style="padding:10px;border-bottom:2px solid #e7e4e0;color:#625d57;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Codigo</th>
                                <th align="right" style="padding:10px;border-bottom:2px solid #e7e4e0;color:#625d57;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Cantidad</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($solicitud->items as $item)
                                @php
                                    $ruta = $item->foto ? public_path('img/'.$item->foto) : null;
                                    $puedeIncrustar = $ruta && is_file($ruta) && $incrustadas < $maxImagenes;
                                    if ($puedeIncrustar) { $incrustadas++; }
                                @endphp
                                <tr>
                                    <td style="padding:10px;border-bottom:1px solid #e7e4e0;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                @if ($puedeIncrustar)
                                                    <td width="52" style="padding-right:10px;">
                                                        <img src="{{ $message->embed($ruta) }}" width="48" height="48"
                                                             alt="{{ $item->nombre }}"
                                                             style="display:block;width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #e7e4e0;background:#fff;">
                                                    </td>
                                                @endif
                                                <td style="font-weight:500;color:#1c1a18;">{{ $item->nombre }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td style="padding:10px;border-bottom:1px solid #e7e4e0;color:#77726b;font-family:ui-monospace,Consolas,monospace;">
                                        {{ $item->codigo }}
                                    </td>
                                    <td align="right" style="padding:10px;border-bottom:1px solid #e7e4e0;font-weight:600;">
                                        {{ $item->cantidad_entregada ?? $item->cantidad_solicitada }}
                                        @if ($item->cantidad_entregada !== null && $item->cantidad_entregada < $item->cantidad_solicitada)
                                            <br><span style="font-weight:400;font-size:12px;color:#9a5b00;">
                                                de {{ $item->cantidad_solicitada }} solicitadas
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        @if ($solicitud->nota_almacen)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                   style="background-color:#fdf3e3;border-left:3px solid #9a5b00;border-radius:6px;margin-top:20px;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:14px;line-height:1.6;">
                                        <strong style="color:#7a4800;">Nota del almacen:</strong><br>
                                        {{ $solicitud->nota_almacen }}
                                    </td>
                                </tr>
                            </table>
                        @endif

                        <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#77726b;">
                            Solicitud creada el {{ $solicitud->created_at->format('d/m/Y \a \l\a\s h:i a') }}.
                            Si tiene alguna duda, responda este correo o comuniquese con el almacen.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#f2f1ef;padding:16px 28px;border-top:1px solid #e7e4e0;">
                        <p style="margin:0;font-size:12px;color:#625d57;line-height:1.5;">
                            Mensaje automatico del sistema de solicitudes de repuestos. No es necesario confirmar su recepcion.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
