<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bitacora en texto plano del costo de cada consulta a la API de Claude.
 *
 * Mismo patron que BitacoraSincronizacionStock: una linea por operacion,
 * fechada en hora de Colombia, en storage/logs (salida de ejecucion, fuera de
 * git), anexando con LOCK_EX y con un archivo aparte bajo PHPUnit para que las
 * pruebas no ensucien la bitacora real.
 *
 * SEGURIDAD: solo entran contadores, el modelo y el estado. Ni la clave, ni la
 * imagen, ni el texto que devuelve Claude.
 */
class BitacoraCostoClaude
{
    public const OPERACION_BUSQUEDA = 'busqueda_imagen';

    public const OPERACION_INDICE = 'indice_imagenes';

    private const ZONA = 'America/Bogota';

    private const ENCABEZADO = "BITACORA DE COSTOS DE LA API DE CLAUDE (horas de Colombia, UTC-5)\n"
        ."Formato: fecha | operacion | modelo | llamadas | tokens de entrada | salida | cache escritura | cache lectura\n"
        ."| costo USD estimado con los precios del .env | resultado (coincidencias o imagenes) | duracion | estado\n"
        ."------------------------------------------------------------------------------------------------------\n";

    public function __construct(private readonly CalculadoraCostoClaude $calculadora) {}

    /**
     * Anexa una linea. NO PROPAGA NINGUNA EXCEPCION: un disco lleno no puede
     * tumbar una busqueda que ya se pago. El fallo queda en el log de Laravel.
     *
     * @param  string  $resultado  p.ej. "coincidencias: 3" o "imagenes: 8"
     * @param  string  $estado  "ok" o una descripcion corta del fallo
     */
    public function registrar(string $operacion, UsoClaude $uso, string $resultado, float $segundos, string $estado = 'ok'): void
    {
        try {
            $this->anexar(sprintf(
                '%s | %s | modelo: %s | llamadas: %d | entrada: %d | salida: %d | cache escritura: %d | cache lectura: %d'
                    ." | costo USD: %.6f | %s | duracion: %.1f s | estado: %s\n",
                Carbon::now(self::ZONA)->format('Y-m-d H:i:s'),
                $operacion,
                (string) config('services.anthropic.modelo'),
                $uso->llamadas,
                $uso->entrada,
                $uso->salida,
                $uso->cacheEscritura,
                $uso->cacheLectura,
                $this->calculadora->costo($uso),
                $resultado,
                $segundos,
                // Una sola linea por operacion: un salto de linea en el estado
                // partiria el renglon.
                str_replace(["\r", "\n", '|'], ' ', $estado),
            ));
        } catch (Throwable $e) {
            Log::error('No se pudo escribir la bitacora de costos de Claude.', [
                'excepcion' => $e->getMessage(),
            ]);
        }
    }

    public function ruta(): string
    {
        $archivo = basename((string) config('services.anthropic.log_costos', 'claude-costos.txt'));

        if (app()->runningUnitTests()) {
            $archivo = pathinfo($archivo, PATHINFO_FILENAME).'-pruebas.'.(pathinfo($archivo, PATHINFO_EXTENSION) ?: 'txt');
        }

        return storage_path('logs'.DIRECTORY_SEPARATOR.$archivo);
    }

    private function anexar(string $linea): void
    {
        $ruta = $this->ruta();
        $carpeta = dirname($ruta);

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0775, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No existe la carpeta {$carpeta} y no se pudo crear.");
        }

        $contenido = file_exists($ruta) ? $linea : self::ENCABEZADO.$linea;

        if (file_put_contents($ruta, $contenido, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('file_put_contents no pudo anexar la linea.');
        }
    }
}
