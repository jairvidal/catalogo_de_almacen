<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bitacora en texto plano de los documentos armados para el ERP (V 1.4.40).
 *
 * Una entrada por intento de envio: fecha y hora de Colombia de la
 * importacion, numero de la solicitud, consecutivo del ERP, resultado del
 * envio y el JSON EXACTO que se mando (el mismo String, sin reformatear), para
 * poder cruzarlo con lo que el ERP dice haber recibido. Se escribe tambien
 * cuando el envio falla: es justo cuando mas hace falta ver que se mando.
 *
 * Mismo patron que BitacoraSincronizacionStock / BitacoraCostoClaude:
 * storage/logs (fuera de git), FILE_APPEND | LOCK_EX, archivo -pruebas bajo
 * PHPUnit, y un fallo al escribir nunca tumba nada.
 *
 * SEGURIDAD: el JSON no lleva credenciales (la URL no se escribe aqui). Lleva
 * datos de la solicitud (codigos, OC, notas), igual que la base.
 *
 * La llama unicamente IntegracionErpService.
 */
class BitacoraIntegracionErp
{
    public const ARCHIVO = 'logs/integracion-erp.txt';

    public const ARCHIVO_PRUEBAS = 'logs/integracion-erp-pruebas.txt';

    private const ZONA = 'America/Bogota';

    private const SEPARADOR = "------------------------------------------------------------------------------------------------------\n";

    private const ENCABEZADO = "BITACORA DE INTEGRACION CON EL ERP (horas de Colombia, UTC-5)\n"
        ."Formato: fecha y hora | solicitud | consecutivo ERP | resultado del envio, y en la linea siguiente el JSON enviado.\n"
        .self::SEPARADOR;

    public function registrar(CarbonInterface $momento, string $solicitud, ?int $numeroErp, string $resultado, string $json): void
    {
        try {
            $linea = sprintf(
                "%s | solicitud: %s | consecutivo ERP: %s | %s\n%s\n%s",
                $momento->copy()->setTimezone(self::ZONA)->format('Y-m-d H:i:s'),
                $solicitud,
                $numeroErp === null ? '-' : (string) $numeroErp,
                $resultado,
                $json,
                self::SEPARADOR,
            );

            $this->anexar($linea);
        } catch (Throwable $e) {
            Log::error('No se pudo escribir la bitacora de integracion con el ERP.', [
                'archivo' => self::ARCHIVO,
                'solicitud' => $solicitud,
                'excepcion' => $e->getMessage(),
            ]);
        }
    }

    public function ruta(): string
    {
        $archivo = app()->runningUnitTests() ? self::ARCHIVO_PRUEBAS : self::ARCHIVO;

        return storage_path(str_replace('/', DIRECTORY_SEPARATOR, $archivo));
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
            throw new RuntimeException('file_put_contents no pudo anexar la entrada.');
        }
    }
}
