<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ya hay una sincronizacion de stock corriendo.
 *
 * Es un conflicto de estado, no un error: la peticion estaba bien formada y lo
 * unico que pasa es que otra corrida (el programador de tareas o el boton del
 * panel) llego antes. Por eso el controlador la traduce a un 409 y no a un 500.
 */
class SincronizacionEnCursoException extends RuntimeException {}
