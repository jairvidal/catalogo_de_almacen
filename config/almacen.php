<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aviso de solicitudes nuevas
    |--------------------------------------------------------------------------
    |
    | Correos que reciben una copia cuando entra una solicitud nueva al panel.
    | Se pueden listar varios separados por coma. Si queda vacio, no se envia
    | ningun aviso de entrada (el correo al solicitante si se envia siempre).
    |
    */

    'notificacion_email' => env('ALMACEN_NOTIFICACION_EMAIL', ''),

    /*
    |--------------------------------------------------------------------------
    | Datos de contacto que se muestran al solicitante
    |--------------------------------------------------------------------------
    */

    'nombre' => env('ALMACEN_NOMBRE', 'Almacen de Repuestos'),
    'horario' => env('ALMACEN_HORARIO', 'Lunes a viernes de 7:00 a.m. a 5:00 p.m.'),
    'ubicacion' => env('ALMACEN_UBICACION', 'Ventanilla del almacen, primer piso'),

];
