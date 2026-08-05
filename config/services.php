<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Storage de Supabase de donde `repuestos:sincronizar-supabase` baja las
     * fotos nuevas hacia public/img. La clave es de solo lectura en la practica
     * (el comando nunca sube ni borra), pero si es la service_role da permisos
     * totales: va en el .env y nunca en el repositorio.
     */
    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'bucket' => env('SUPABASE_BUCKET'),
        'key' => env('SUPABASE_KEY'),
        'publico' => env('SUPABASE_BUCKET_PUBLICO', true),
    ],

];
