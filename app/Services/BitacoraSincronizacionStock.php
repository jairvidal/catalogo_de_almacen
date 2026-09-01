<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Bitacora en texto plano de las corridas de sincronizacion de stock.
 *
 * Es un archivo para leer con el bloc de notas, no un log tecnico: una linea
 * por corrida con la fecha, cuantos items se actualizaron y cuantos no, y el
 * desglose de por que no. El log de Laravel sigue teniendo el detalle completo;
 * esto es lo que el administrador puede abrir y entender sin filtrar nada.
 *
 * VA EN storage/logs Y NO EN LA RAIZ DEL REPOSITORIO: es salida de ejecucion,
 * no documentacion versionada como changelog.txt, y storage/ ya esta ignorada
 * por git. Si viviera junto al changelog, cada corrida ensuciaria el arbol de
 * trabajo y acabaria en un commit.
 *
 * LA LLAMA UNICAMENTE App\Services\SincronizadorStockRepuestos, que es el dueno
 * de la orquestacion, para que la consola y el boton del panel escriban lo
 * mismo sin que ninguno de los dos repita la logica.
 *
 * SEGURIDAD: aqui solo entran contadores. Ni codigo_api, ni el token, ni
 * siquiera los codigos de los repuestos pasan por este archivo.
 */
class BitacoraSincronizacionStock
{
    /** Ruta dentro de storage/. */
    public const ARCHIVO = 'logs/sincronizacion.txt';

    /**
     * Archivo que se usa bajo PHPUnit.
     *
     * Las pruebas corren la sincronizacion de verdad contra un Http::fake(), y
     * sin esta separacion cada `php artisan test` metia decenas de renglones de
     * juguete en el archivo que lee el almacen. La bitacora real solo la
     * escriben las corridas reales.
     */
    public const ARCHIVO_PRUEBAS = 'logs/sincronizacion-pruebas.txt';

    /**
     * Zona en la que se fecha cada linea. La bitacora la lee el almacen en
     * Colombia; con la zona de la aplicacion (UTC) cada renglon saldria cinco
     * horas adelantado y nadie podria cruzarlo con lo que vio en pantalla.
     */
    private const ZONA = 'America/Bogota';

    private const ENCABEZADO = "BITACORA DE SINCRONIZACION DE STOCK CON EL ERP (horas de Colombia, UTC-5)\n"
        ."Formato: fecha y hora | actualizados: items cuyo stock cambio | no actualizados: los demas que mando el ERP\n"
        ."Los no actualizados se desglosan en: ya al dia (el stock ya coincidia), sin correspondencia (el codigo no\n"
        ."esta en el catalogo), sin item, item no numerico y sin cantidad.\n"
        ."------------------------------------------------------------------------------------------------------\n";

    /**
     * Anexa una linea con lo que dejo la corrida.
     *
     * NO PROPAGA NINGUNA EXCEPCION, a proposito y por el mismo criterio que el
     * correo de "pedido listo": un disco lleno o un permiso mal puesto no puede
     * tumbar una sincronizacion que ya escribio el stock correctamente. El
     * fallo queda en el log de la aplicacion.
     */
    public function registrar(ResultadoSincronizacionStock $resultado): void
    {
        try {
            $this->anexar($this->linea($resultado));
        } catch (Throwable $e) {
            Log::error('No se pudo escribir la bitacora de sincronizacion de stock.', [
                'archivo' => self::ARCHIVO,
                'excepcion' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ruta absoluta del archivo, para el comando y para las pruebas.
     *
     * Se arma con el separador del sistema y no concatenando 'logs/...', para
     * que en Windows no salga un "storage\logs/sincronizacion.txt" que nadie
     * puede copiar y pegar en el explorador.
     */
    public function ruta(): string
    {
        $archivo = app()->runningUnitTests() ? self::ARCHIVO_PRUEBAS : self::ARCHIVO;

        return storage_path(str_replace('/', DIRECTORY_SEPARATOR, $archivo));
    }

    /**
     * Una corrida, una linea. El desglose va entre parentesis para que se lea
     * de corrido y para que "no actualizados" no quede como un numero opaco.
     */
    private function linea(ResultadoSincronizacionStock $resultado): string
    {
        return sprintf(
            '%s | actualizados: %d | no actualizados: %d (ya al dia: %d, sin correspondencia: %d, '
                ."sin item: %d, item no numerico: %d, sin cantidad: %d) | recibidos: %d | paginas: %d\n",
            Carbon::now(self::ZONA)->format('Y-m-d H:i:s'),
            $resultado->actualizados,
            $resultado->noActualizados(),
            $resultado->sinCambio,
            $resultado->sinCorrespondencia,
            $resultado->sinItem,
            $resultado->itemNoNumerico,
            $resultado->sinCantidad,
            $resultado->recibidos,
            $resultado->paginas,
        );
    }

    /**
     * Anexa, nunca sobrescribe, y crea el archivo la primera vez con el
     * encabezado que explica las columnas.
     *
     * LOCK_EX porque la tarea programada y el boton del panel pueden escribir
     * casi a la vez: sin el, dos lineas podrian entrelazarse. El candado del
     * sincronizador ya lo hace improbable, pero el costo aqui es cero.
     */
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
