<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El archivo de solicitantes no se puede leer: no existe, esta vacio o le
 * faltan columnas obligatorias.
 *
 * Es propia y no una RuntimeException a secas porque QueryException tambien
 * desciende de RuntimeException: el comando necesita distinguir "corrija el
 * archivo" (mensaje util, sin datos personales) de un fallo de la base.
 */
class ArchivoSolicitantesInvalidoException extends RuntimeException {}
