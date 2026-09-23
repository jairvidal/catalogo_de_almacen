@php
    /**
     * Colores EN LINEA y a mano: un correo no carga app.css. Los valores
     * espejan los tokens --ca-* :
     *   #f20a0a marca (solo filete, sin texto pequeno encima)
     *   #c40808 marca oscura (relleno del boton, texto blanco: 6.20:1)
     *   #fafafa #f2f1ef #e7e4e0 #77726b #625d57 #2e2b28  neutros calidos
     * Si cambia la paleta en app.css, cambiela tambien aqui.
     */
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva solicitud de repuestos</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f1ef;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2e2b28;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f1ef;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(28,26,24,.12);">

                {{-- Filete de senalizacion: el rojo pleno, sin texto encima. --}}
                <tr>
                    <td style="background-color:#f20a0a;height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                    <td style="background-color:#2e2b28;padding:22px 26px;">
                        <h1 style="margin:0;font-size:19px;color:#ffffff;font-weight:600;">
                            Nueva solicitud No. {{ $solicitud->numero }}
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.8;margin-bottom:20px;">
                            <tr>
                                <td width="120" style="color:#77726b;">Solicitante</td>
                                <td style="font-weight:600;">{{ $solicitud->solicitante_nombre }}</td>
                            </tr>
                            <tr>
                                <td style="color:#77726b;">Cedula</td>
                                <td>{{ $solicitud->solicitante_cedula }}</td>
                            </tr>
                            <tr>
                                <td style="color:#77726b;">Correo</td>
                                <td>{{ $solicitud->solicitante_email }}</td>
                            </tr>
                            @if ($solicitud->solicitante_area)
                                <tr>
                                    <td style="color:#77726b;">Area</td>
                                    <td>{{ $solicitud->solicitante_area }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td style="color:#77726b;">Items</td>
                                <td>{{ $solicitud->items->count() }} referencias / {{ $solicitud->total_unidades }} unidades</td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                            <thead>
                            <tr style="background-color:#f2f1ef;">
                                <th align="left" style="padding:9px;border-bottom:2px solid #e7e4e0;font-size:12px;color:#625d57;text-transform:uppercase;">Codigo</th>
                                <th align="left" style="padding:9px;border-bottom:2px solid #e7e4e0;font-size:12px;color:#625d57;text-transform:uppercase;">Item</th>
                                <th align="right" style="padding:9px;border-bottom:2px solid #e7e4e0;font-size:12px;color:#625d57;text-transform:uppercase;">Cant.</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($solicitud->items as $item)
                                <tr>
                                    <td style="padding:9px;border-bottom:1px solid #e7e4e0;font-family:ui-monospace,Consolas,monospace;color:#77726b;">{{ $item->codigo }}</td>
                                    <td style="padding:9px;border-bottom:1px solid #e7e4e0;">{{ $item->nombre }}</td>
                                    <td align="right" style="padding:9px;border-bottom:1px solid #e7e4e0;font-weight:600;">{{ $item->cantidad_solicitada }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        @if ($solicitud->observaciones)
                            <p style="margin:18px 0 0;font-size:14px;line-height:1.6;background:#f2f1ef;padding:12px 14px;border-radius:6px;">
                                <strong style="color:#625d57;">Observaciones:</strong><br>{{ $solicitud->observaciones }}
                            </p>
                        @endif

                        {{-- Relleno #c40808 y no #f20a0a: la etiqueta es de 14px y sobre el
                             rojo pleno se queda en 4.35:1, por debajo de 4.5:1. --}}
                        <p style="margin:26px 0 0;">
                            <a href="{{ $url }}" style="display:inline-block;background-color:#c40808;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;">
                                Abrir en el panel
                            </a>
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
