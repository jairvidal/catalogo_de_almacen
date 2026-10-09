<?php

namespace App\Services;

use App\Exceptions\ClaudeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trato con POST /v1/messages de Anthropic, y nada mas.
 *
 * Se usa el cliente Http de Laravel y no el SDK oficial de PHP a proposito: es
 * una sola llamada sin herramientas ni streaming, el proyecto ya habla con la
 * API del ERP de la misma forma (InventarioApiSidocsa) y las pruebas la simulan
 * con Http::fake() sin agregar una dependencia nueva al composer.json.
 *
 * Cada llamada pide la respuesta como JSON con esquema (output_config.format),
 * asi que no hay que pescar el JSON dentro de texto libre. El razonamiento del
 * modelo se deja en su modo adaptativo por defecto (en Claude Opus 5.5 no se
 * puede apagar) y se acota con el esfuerzo del .env.
 *
 * SEGURIDAD: la clave viaja solo en la cabecera x-api-key. Ningun mensaje de
 * excepcion ni linea de log lleva la clave, la imagen o el cuerpo enviado.
 */
class ClienteClaudeApi
{
    /**
     * Modelos que aceptan `fallbacks: "default"` (beta
     * server-side-fallback-2026-07-01). Con cualquier otro el parametro no se
     * envia, aunque el .env lo pida, porque la API lo rechazaria con un 400.
     */
    public const MODELOS_CON_FALLBACK = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

    /** Codigos que vale la pena reintentar: limite de tasa y saturacion. */
    private const REINTENTABLES = [429, 500, 502, 503, 504, 529];

    public function estaConfigurado(): bool
    {
        return trim((string) config('services.anthropic.key')) !== '';
    }

    /**
     * Envia un mensaje de usuario y devuelve el JSON que respondio el modelo,
     * ya decodificado, junto con los tokens consumidos.
     *
     * @param  list<array<string, mixed>>  $sistema  bloques de texto del system (pueden llevar cache_control)
     * @param  list<array<string, mixed>>  $contenido  bloques del mensaje del usuario (imagenes y texto)
     * @param  array<string, mixed>  $esquema  JSON Schema de la respuesta
     * @return array{datos: array<string, mixed>, uso: UsoClaude}
     *
     * @throws ClaudeApiException con un mensaje apto para el usuario
     */
    public function json(array $sistema, array $contenido, array $esquema): array
    {
        if (! $this->estaConfigurado()) {
            throw new ClaudeApiException('La busqueda por imagen no esta configurada en este servidor.');
        }

        $respuesta = $this->enviar($this->cuerpo($sistema, $contenido, $esquema));
        $cuerpo = $respuesta->json();
        $uso = UsoClaude::desdeRespuesta(is_array($cuerpo['usage'] ?? null) ? $cuerpo['usage'] : []);

        if (! $respuesta->successful() || ! is_array($cuerpo)) {
            Log::warning('La API de Claude respondio con error.', [
                'status' => $respuesta->status(),
                'tipo' => is_array($cuerpo) ? ($cuerpo['error']['type'] ?? null) : null,
                'mensaje' => is_array($cuerpo) ? ($cuerpo['error']['message'] ?? null) : null,
            ]);

            throw new ClaudeApiException($this->mensajeDeError($respuesta->status()), $uso->llamadas > 0 ? $uso : null);
        }

        $motivo = $cuerpo['stop_reason'] ?? null;

        if ($motivo === 'refusal') {
            Log::warning('Claude declino analizar la imagen.', ['categoria' => $cuerpo['stop_details']['category'] ?? null]);

            throw new ClaudeApiException('El servicio de reconocimiento no pudo analizar esta imagen. Pruebe con otra foto.', $uso);
        }

        if ($motivo === 'max_tokens') {
            Log::warning('La respuesta de Claude se corto por max_tokens.', ['max_tokens' => config('services.anthropic.max_tokens')]);

            throw new ClaudeApiException('El servicio de reconocimiento devolvio una respuesta incompleta. Intente de nuevo.', $uso);
        }

        return ['datos' => $this->extraerJson($cuerpo, $uso), 'uso' => $uso];
    }

    /**
     * @return array<string, mixed>
     */
    private function cuerpo(array $sistema, array $contenido, array $esquema): array
    {
        $modelo = (string) config('services.anthropic.modelo');
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $esquema]];
        $esfuerzo = trim((string) config('services.anthropic.esfuerzo'));

        if ($esfuerzo !== '') {
            $outputConfig['effort'] = $esfuerzo;
        }

        $cuerpo = [
            'model' => $modelo,
            'max_tokens' => (int) config('services.anthropic.max_tokens', 8000),
            'system' => $sistema,
            'messages' => [['role' => 'user', 'content' => $contenido]],
            'output_config' => $outputConfig,
        ];

        if ($this->usaFallback()) {
            $cuerpo['fallbacks'] = 'default';
        }

        return $cuerpo;
    }

    private function usaFallback(): bool
    {
        return (bool) config('services.anthropic.fallback_servidor')
            && in_array((string) config('services.anthropic.modelo'), self::MODELOS_CON_FALLBACK, true);
    }

    private function enviar(array $cuerpo): Response
    {
        $cabeceras = [
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
        ];

        if ($this->usaFallback()) {
            $cabeceras['anthropic-beta'] = self::BETA_FALLBACK;
        }

        try {
            return Http::withHeaders($cabeceras)
                ->acceptJson()
                ->asJson()
                ->timeout(max(10, (int) config('services.anthropic.timeout', 90)))
                ->connectTimeout(10)
                // throw: false para que un 4xx/5xx llegue como respuesta y se
                // traduzca a un mensaje legible en vez de una RequestException.
                ->retry(2, 1500, fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), self::REINTENTABLES, true)), throw: false)
                ->post((string) config('services.anthropic.url'), $cuerpo);
        } catch (ConnectionException $e) {
            Log::warning('No se pudo conectar con la API de Claude.', ['excepcion' => $e->getMessage()]);

            throw new ClaudeApiException('No se pudo conectar con el servicio de reconocimiento de imagenes. Intente de nuevo en un momento.', null, $e);
        }
    }

    /**
     * El texto de la respuesta es el JSON pedido. Puede venir precedido por
     * bloques `thinking` (vacios por defecto), por eso se busca el de texto.
     *
     * @return array<string, mixed>
     */
    private function extraerJson(array $cuerpo, UsoClaude $uso): array
    {
        foreach ((array) ($cuerpo['content'] ?? []) as $bloque) {
            if (($bloque['type'] ?? null) !== 'text') {
                continue;
            }

            $datos = json_decode((string) ($bloque['text'] ?? ''), true);

            if (is_array($datos)) {
                return $datos;
            }
        }

        Log::warning('La respuesta de Claude no trajo el JSON esperado.', ['stop_reason' => $cuerpo['stop_reason'] ?? null]);

        throw new ClaudeApiException('El servicio de reconocimiento devolvio una respuesta que no se pudo interpretar. Intente de nuevo.', $uso);
    }

    private function mensajeDeError(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'La busqueda por imagen no esta disponible: la credencial del servicio no es valida.',
            $status === 429 => 'El servicio de reconocimiento esta ocupado. Intente de nuevo en un minuto.',
            $status === 413 => 'La imagen es demasiado grande para el servicio de reconocimiento.',
            $status >= 500 => 'El servicio de reconocimiento no esta disponible en este momento. Intente mas tarde.',
            default => "El servicio de reconocimiento rechazo la consulta (HTTP {$status}).",
        };
    }
}
