<?php

namespace App\Exceptions;

use App\Services\UsoClaude;
use RuntimeException;
use Throwable;

/**
 * Fallo al consultar la API de Claude o al interpretar su respuesta.
 *
 * El MENSAJE es apto para mostrarse al usuario del catalogo: nunca lleva la
 * clave, ni el cuerpo de la peticion, ni rutas del servidor. El detalle tecnico
 * va al log de la aplicacion desde quien la lanza.
 *
 * Lleva el uso de tokens cuando la API alcanzo a responder (por ejemplo, un
 * JSON invalido o una respuesta cortada): esos tokens se cobraron igual y la
 * bitacora de costos tiene que registrarlos.
 */
class ClaudeApiException extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly ?UsoClaude $uso = null,
        ?Throwable $previa = null,
    ) {
        parent::__construct($mensaje, 0, $previa);
    }
}
