<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Recorre todas las paginas de la API de inventario y devuelve las existencias
 * que reporta el ERP, indexadas por codigo.
 *
 * POR QUE ESTA APARTE: la leen DOS consumidores con propositos distintos y no
 * pueden duplicar el recorrido —
 *  - SincronizadorStockRepuestos, que escribe `stock` en cada corrida.
 *  - InicializadorExistenciaRepuestos, que escribe `existencia` una sola vez.
 *
 * Que ese inicializador lea la API en vez de copiar `stock` tiene un motivo:
 * `stock` trae ademas los valores de la carga masiva del ERP del 2026-08-21, y
 * en unas 2.669 de esas filas el valor es un punto de reposicion (stock ==
 * stock_minimo con stock_maximo al doble), no una existencia fisica. Lo que lee
 * este recorrido es lo unico que el ERP confirma hoy.
 *
 * Existe ademas una segunda via, InicializadorExistenciaDesdeStock, que copia
 * `stock` tal cual y NO PASA POR AQUI: el usuario decidio asumir ese riesgo
 * antes que dejar al almacen sin despachar. No es este archivo el que lo
 * impide; las dos vias comparten la bandera inv.existencia_inicializada.
 *
 * Aqui NO se escribe en la base. Solo se lee el endpoint y se normaliza.
 */
class LectorInventarioErp
{
    /** Cuantos ejemplos se guardan de cada anomalia, para poder diagnosticar. */
    private const MUESTRA = 10;

    public function __construct(private readonly InventarioApiSidocsa $api) {}

    /**
     * Lee el inventario completo.
     *
     * Corta cuando una pagina viene vacia o trae menos registros que el tamano
     * de pagina, y siempre con el tope duro de MAX_PAGINAS por delante para que
     * un endpoint que responda lleno siempre no genere un bucle infinito.
     *
     * @throws RuntimeException si falla la API (la propaga InventarioApiSidocsa).
     */
    public function leer(): LecturaInventarioErp
    {
        /** @var array<int, float> $existencias codigo => cantidad */
        $existencias = [];
        $paginas = 0;
        $recibidos = 0;
        $sinItem = 0;
        $itemNoNumerico = 0;
        $sinCantidad = 0;
        $topeAlcanzado = true;
        /** @var list<string> $muestraNoNumericos */
        $muestraNoNumericos = [];

        for ($pagina = 1; $pagina <= InventarioApiSidocsa::MAX_PAGINAS; $pagina++) {
            $filas = $this->api->consultarPagina($pagina);
            $paginas++;
            $recibidos += count($filas);

            foreach ($filas as $fila) {
                $codigo = $this->api->extraerCodigo($fila);

                if ($codigo === null) {
                    $sinItem++;

                    continue;
                }

                if (! ctype_digit($codigo)) {
                    // repuestos.codigo es un entero: un item no numerico no
                    // tiene contra que cruzar. Sin este corte, (int) 'ABC' daria
                    // 0 y el registro cruzaria contra cualquier repuesto cuyo
                    // codigo tambien fuera 0.
                    $itemNoNumerico++;

                    if (count($muestraNoNumericos) < self::MUESTRA) {
                        $muestraNoNumericos[] = $codigo;
                    }

                    continue;
                }

                $cantidad = $this->api->extraerCantidad($fila);

                if ($cantidad === null) {
                    $sinCantidad++;

                    continue;
                }

                $existencias[(int) $codigo] = $cantidad;
            }

            // El tamano de pagina es el mismo numero que viaja en `cant`: una
            // sola constante gobierna las dos cosas (ver InventarioApiSidocsa).
            if (count($filas) < InventarioApiSidocsa::TAMANO_PAGINA) {
                $topeAlcanzado = false;

                break;
            }
        }

        if ($sinItem > 0) {
            Log::warning('Registros de la API de inventario sin el campo de codigo.', [
                'registros' => $sinItem,
                'campo_esperado' => $this->api->campoCodigo(),
            ]);
        }

        return new LecturaInventarioErp(
            existencias: $existencias,
            paginas: $paginas,
            recibidos: $recibidos,
            sinItem: $sinItem,
            itemNoNumerico: $itemNoNumerico,
            sinCantidad: $sinCantidad,
            topeAlcanzado: $topeAlcanzado,
            muestraItemNoNumerico: $muestraNoNumericos,
        );
    }
}
