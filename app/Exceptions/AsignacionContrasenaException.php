<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No se pudo asignar la contrasena a un solicitante: no cumple las reglas de
 * acceso (inactivo, sin correo, correo compartido) o el correo con la clave no
 * salio y la asignacion se revirtio.
 *
 * El mensaje es apto para el administrador: nunca lleva la contrasena, el hash
 * ni el detalle del servidor SMTP.
 */
class AsignacionContrasenaException extends RuntimeException {}
