<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fallo conocido de la integracion con el ERP (configuracion vacia o
 * invalida, el servicio web no respondio o respondio con error). El mensaje
 * es apto para guardarse en tbl_solicitudes.erp_error y mostrarse al
 * aprobador: nunca lleva el cuerpo de la respuesta ni datos del servidor.
 */
class IntegracionErpException extends RuntimeException {}
