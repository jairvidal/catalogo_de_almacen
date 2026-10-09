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

    /*
     * API de mensajes de Anthropic (Claude) para la busqueda de repuestos por
     * imagen del catalogo publico y para el indice previo de public/img
     * (`repuestos:indexar-imagenes`).
     *
     * La clave va SOLO en el .env: nunca en un log, en una respuesta ni en el
     * repositorio. Se lee siempre por config(), nunca con env() fuera de aqui.
     *
     * Los precios son USD por MILLON de tokens y solo sirven para calcular el
     * costo que se anota en la bitacora; no cambian lo que cobra Anthropic. Si
     * cambia el modelo hay que cambiar tambien los precios.
     */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'modelo' => env('ANTHROPIC_MODELO', 'claude-opus-5-5'),
        // low | medium | high | xhigh | max. Vacio = no se envia (obligatorio
        // dejarlo vacio con modelos que no lo aceptan, como Haiku 4.5).
        'esfuerzo' => env('ANTHROPIC_ESFUERZO', 'low'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8000),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 90),
        // Reintento del lado de Anthropic con otro modelo si el primero declina
        // (parametro fallbacks: "default"). Solo se envia con los modelos que lo
        // admiten; ver ClienteClaudeApi::MODELOS_CON_FALLBACK.
        'fallback_servidor' => (bool) env('ANTHROPIC_FALLBACK_SERVIDOR', true),

        'precios' => [
            'entrada' => (float) env('ANTHROPIC_PRECIO_ENTRADA', 4.00),
            'salida' => (float) env('ANTHROPIC_PRECIO_SALIDA', 20.00),
            'cache_escritura' => (float) env('ANTHROPIC_PRECIO_CACHE_ESCRITURA', 5.00),
            'cache_lectura' => (float) env('ANTHROPIC_PRECIO_CACHE_LECTURA', 0.20),
        ],

        // Bitacora de costos en storage/logs (bajo PHPUnit, con sufijo -pruebas).
        'log_costos' => env('ANTHROPIC_LOG_COSTOS', 'claude-costos.txt'),

        'imagen' => [
            // Porcentaje minimo de coincidencia para mostrar un repuesto.
            'umbral' => (int) env('ANTHROPIC_IMAGEN_UMBRAL', 80),
            // Tamano maximo del archivo que sube el usuario, en KB.
            'max_kb' => (int) env('ANTHROPIC_IMAGEN_MAX_KB', 10240),
            // Lado mayor, en pixeles, al que se reduce cada imagen antes de enviarla.
            'lado_consulta' => (int) env('ANTHROPIC_IMAGEN_LADO_CONSULTA', 1024),
            'lado_catalogo' => (int) env('ANTHROPIC_IMAGEN_LADO_CATALOGO', 512),
            'lado_indice' => (int) env('ANTHROPIC_IMAGEN_LADO_INDICE', 768),
            // Cuantos candidatos del primer paso se verifican mirando su foto real.
            'candidatos' => (int) env('ANTHROPIC_IMAGEN_CANDIDATOS', 8),
            'verificar' => (bool) env('ANTHROPIC_IMAGEN_VERIFICAR', true),
            // Fotos por peticion al construir el indice.
            'lote_indice' => (int) env('ANTHROPIC_IMAGEN_LOTE_INDICE', 8),
            // Freno de gasto del endpoint publico: por IP y por minuto, y global por dia.
            'por_minuto' => (int) env('ANTHROPIC_IMAGEN_POR_MINUTO', 5),
            'por_dia' => (int) env('ANTHROPIC_IMAGEN_POR_DIA', 300),
        ],
    ],

];
