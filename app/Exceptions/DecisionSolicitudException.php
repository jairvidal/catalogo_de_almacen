<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El solicitante intento aprobar o denegar una solicitud que ya no esta por
 * aprobar (doble clic, dos pestanas, o ya la habia decidido). No es un fallo:
 * el mensaje es apto para mostrarse tal cual.
 */
class DecisionSolicitudException extends RuntimeException {}
