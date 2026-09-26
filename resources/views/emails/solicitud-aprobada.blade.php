@php
    /**
     * Colores EN LINEA y a mano: un correo no carga app.css. Mismos valores que
     * pedido-listo.blade.php, que espejan los tokens --ca-* :
     *   #f20a0a marca (solo filete)   #1f7a3d / #e9f3ec exito
     *   #f2f1ef #e7e4e0 #77726b #625d57 #2e2b28 #1c1a18  neutros calidos
     * Si cambia la paleta en app.css, cambiela tambien aqui.
     *
     * Un solo mensaje para el solicitante del ERP (quien aprobo) y para la
     * persona que hizo la solicitud: hoy comparten buzon, asi que el texto le
     * habla a los dos.
     */
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Solicitud aprobada</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f1ef;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2e2b28;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f1ef;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(28,26,24,.12);">

                <tr>
                    <td style="background-color:#f20a0a;height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                    <td style="background-color:#2e2b28;padding:24px 28px;">
                        <p style="margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#c8c3bc;">
                            {{ $almacen['nombre'] }}
                        </p>
                        <h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;color:#ffffff;font-weight:600;">
                            Solicitud No. {{ $solicitud->numero }} aprobada
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            Hola,
                        </p>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                            La solicitud No. <strong>{{ $solicitud->numero }}</strong>
                            @if ($solicitud->nombre_completo)
                                que registro <strong>{{ $solicitud->nombre_completo }}</strong>
                            @endif
                            fue <strong style="color:#1f7a3d;">aprobada</strong> por
                            <strong>{{ $solicitud->solicitante_nombre }}</strong>
                            el {{ $solicitud->aprobada_at?->format('d/m/Y \a \l\a\s h:i a') }}
                            y ya paso al almacen para su preparacion.
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="background-color:#e9f3ec;border:1px solid #bcd7c5;border-left:3px solid #1f7a3d;border-radius:8px;margin-bottom:24px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:14px;line-height:1.7;">
                                    Cuando el pedido este listo para reclamar le llegara otro correo a esta misma direccion.
                                </td>
                            </tr>
                        </table>

                        <h2 style="margin:0 0 12px;font-size:16px;font-weight:600;color:#1c1a18;">
                            Detalle de la solicitud
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
                                <tr>
                                    <td style="padding:10px;border-bottom:1px solid #e7e4e0;font-weight:500;color:#1c1a18;">{{ $item->nombre }}</td>
                                    <td style="padding:10px;border-bottom:1px solid #e7e4e0;color:#77726b;font-family:ui-monospace,Consolas,monospace;">{{ $item->codigo }}</td>
                                    <td align="right" style="padding:10px;border-bottom:1px solid #e7e4e0;font-weight:600;">{{ $item->cantidad_solicitada }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        @if ($solicitud->observaciones)
                            <p style="margin:18px 0 0;font-size:14px;line-height:1.6;background:#f2f1ef;padding:12px 14px;border-radius:6px;">
                                <strong style="color:#625d57;">Observaciones:</strong><br>{{ $solicitud->observaciones }}
                            </p>
                        @endif

                        <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#77726b;">
                            Solicitud creada el {{ $solicitud->created_at->format('d/m/Y \a \l\a\s h:i a') }}.
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
