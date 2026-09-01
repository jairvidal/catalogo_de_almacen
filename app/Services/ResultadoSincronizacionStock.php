<?php

namespace App\Services;

/**
 * Lo que dejo una corrida de SincronizadorStockRepuestos.
 *
 * Existe para que el servicio no tenga que saber quien lo llamo: el comando
 * arma con esto la tabla de la consola y el controlador del boton "Actualizar"
 * arma el toast, sin que ninguno de los dos repita el conteo.
 *
 * Aqui no entra nada sensible: son conteos y codigos de inventario. Ni la
 * credencial ni el token pasan por este objeto, porque su contenido termina en
 * la pantalla del administrador.
 */
class ResultadoSincronizacionStock
{
    /**
     * @param  int  $actualizados  repuestos cuyo stock CAMBIO de valor
     * @param  int  $sinCambio  repuestos que cruzaron pero ya tenian ese stock
     * @param  list<string>  $muestraItemNoNumerico  ejemplos de item no numerico que mando la API
     * @param  list<int>  $muestraSinCorrespondencia  ejemplos de item que el catalogo no tiene
     */
    public function __construct(
        public readonly bool $simulado,
        public readonly int $paginas,
        public readonly int $recibidos,
        public readonly int $actualizados,
        public readonly int $sinCambio,
        public readonly int $sinCorrespondencia,
        public readonly int $sinItem,
        public readonly int $itemNoNumerico,
        public readonly int $sinCantidad,
        public readonly bool $topeAlcanzado,
        public readonly array $muestraItemNoNumerico = [],
        public readonly array $muestraSinCorrespondencia = [],
    ) {}

    /** Registros del ERP que encontraron su repuesto en el catalogo. */
    public function emparejados(): int
    {
        return $this->actualizados + $this->sinCambio;
    }

    /**
     * Registros que el ERP mando y que NO terminaron en una escritura, sea cual
     * sea el motivo: ya estaban al dia, no existen en el catalogo, o venian sin
     * item, con item no numerico o sin cantidad.
     */
    public function noActualizados(): int
    {
        return max(0, $this->recibidos - $this->actualizados);
    }

    /**
     * Frase para el toast del panel y para el mensaje flash.
     *
     * NO ES UNA SOLA FRASE CON NUMEROS DENTRO, Y ESA ES LA GRACIA: un
     * "0 repuesto(s) actualizado(s)" no dice si el ERP no mando nada, si nada
     * cruzo con el catalogo o si simplemente el stock ya estaba al dia, que son
     * tres situaciones distintas —dos averias y una normalidad— y quien aprieta
     * el boton no tiene el log a mano para distinguirlas. Cada caso trae su
     * propia frase.
     */
    public function resumen(): string
    {
        $prefijo = $this->simulado ? 'Simulacion (no se escribio nada): ' : '';

        if ($this->recibidos === 0) {
            return $prefijo.'El ERP no devolvio ningun registro para la bodega y los criterios configurados, '
                .'asi que no habia nada que actualizar. Revise api.id_bod, api.criterio y api.criterio_2.';
        }

        if ($this->emparejados() === 0) {
            return $prefijo."Ninguno de los {$this->recibidos} registro(s) que devolvio el ERP corresponde a un "
                .'repuesto del catalogo, asi que no se actualizo ninguno. '
                ."Sin correspondencia: {$this->sinCorrespondencia}.";
        }

        if ($this->actualizados === 0) {
            return $prefijo."El stock ya estaba al dia: se revisaron {$this->emparejados()} repuesto(s) y ninguno "
                ."cambio de existencia ({$this->sinCorrespondencia} codigo(s) del ERP no estan en el catalogo).";
        }

        $verbo = $this->simulado ? 'cambiarian' : 'cambiaron';

        return $prefijo."{$this->actualizados} repuesto(s) {$verbo} de stock; {$this->sinCambio} ya estaban al dia y "
            ."{$this->sinCorrespondencia} codigo(s) del ERP no estan en el catalogo "
            ."({$this->recibidos} registro(s) en {$this->paginas} pagina(s)).";
    }

    /**
     * Contadores para el log, para la bitacora y para la respuesta JSON del
     * boton manual.
     *
     * @return array<string, int|bool>
     */
    public function contadores(): array
    {
        return [
            'simulacion' => $this->simulado,
            'paginas' => $this->paginas,
            'recibidos' => $this->recibidos,
            'actualizados' => $this->actualizados,
            'no_actualizados' => $this->noActualizados(),
            'sin_cambio' => $this->sinCambio,
            'sin_correspondencia' => $this->sinCorrespondencia,
            'sin_item' => $this->sinItem,
            'item_no_numerico' => $this->itemNoNumerico,
            'sin_cantidad' => $this->sinCantidad,
        ];
    }
}
