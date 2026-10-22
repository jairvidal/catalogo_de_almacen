<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El reenvio al ERP desde el panel no procede (ya fue enviada, no fue
 * aprobada, esta cerrada o hay otro envio de la misma solicitud en curso).
 * No es un fallo del ERP: no se escribe en erp_error. El mensaje es apto para
 * la pantalla.
 */
class ReenvioErpException extends RuntimeException {}
