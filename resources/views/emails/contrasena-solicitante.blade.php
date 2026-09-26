@php
    /**
     * Colores EN LINEA y a mano: un correo no carga app.css. Mismos valores que
     * nueva-solicitud.blade.php, que espejan los tokens --ca-* :
     *   #f20a0a marca (solo filete)   #c40808 marca oscura (boton, texto blanco 6.20:1)
     *   #f2f1ef #e7e4e0 #77726b #625d57 #2e2b28 #1c1a18  neutros calidos
     * Si cambia la paleta en app.css, cambiela tambien aqui.
     *
     * Es el UNICO sitio donde aparece la contrasena en claro. data-contrasena
     * no tiene efecto en el cliente de correo: lo usan las pruebas para leerla.
     */
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso al portal de aprobacion</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f1ef;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2e2b28;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f1ef;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(28,26,24,.12);">

                <tr>
                    <td style="background-color:#f20a0a;height:4px;line-height:4px;font-size:0;">&nbsp;</td>
                </tr>

                <tr>
                    <td style="background-color:#2e2b28;padding:22px 26px;">
                        <p style="margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#c8c3bc;">
                            {{ $almacen['nombre'] }}
                        </p>
                        <h1 style="margin:6px 0 0;font-size:20px;color:#ffffff;font-weight:600;">
                            Su acceso al portal de aprobacion
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px;">
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                            Hola <strong>{{ $nombre }}</strong>,
                        </p>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                            El almacen le asigno una contrasena para entrar al portal donde usted aprueba o deniega
                            las solicitudes de repuestos que se hacen a su nombre.
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="background-color:#f2f1ef;border:1px solid #e7e4e0;border-radius:8px;margin-bottom:22px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:14px;line-height:1.9;">
                                    <span style="color:#625d57;">Usuario:</span>
                                    <strong>{{ $usuario }}</strong><br>
                                    <span style="color:#625d57;">Contrasena:</span>
                                    <code data-contrasena style="font-family:ui-monospace,Consolas,monospace;font-size:16px;font-weight:600;color:#1c1a18;letter-spacing:.06em;">{{ $contrasena }}</code>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 22px;">
                            <a href="{{ $url }}" style="display:inline-block;background-color:#c40808;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;">
                                Entrar al portal
                            </a>
                        </p>

                        <p style="margin:0 0 10px;font-size:14px;line-height:1.6;">
                            Le recomendamos cambiarla la primera vez que entre, desde el enlace
                            <a href="{{ $urlCambio }}" style="color:#c40808;">Cambiar contrasena</a> de la pagina de ingreso.
                        </p>
                        <p style="margin:0;font-size:13px;line-height:1.6;color:#77726b;">
                            Nadie del almacen conoce esta contrasena y nunca se la pediremos. Si usted no esperaba
                            este correo, avise al almacen. Si la olvida, pida que se la restablezcan.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#f2f1ef;padding:16px 26px;border-top:1px solid #e7e4e0;">
                        <p style="margin:0;font-size:12px;color:#625d57;line-height:1.5;">
                            Mensaje automatico del sistema de solicitudes de repuestos.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
