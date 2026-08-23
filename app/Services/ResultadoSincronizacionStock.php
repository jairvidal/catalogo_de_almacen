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
     * @param  list<string>  $muestraItemNoNumerico  ejemplos de item no numerico que mando la API
     * @param  list<int>  $muestraSinCorrespondencia  ejemplos de item que el catalogo no tiene
     */
    public function __construct(
        public readonly bool $simulado,
        public readonly int $paginas,
        public readonly int $recibidos,
        public readonly int $actualizados,
        public readonly int $sinCorrespondencia,
        public readonly int $sinItem,
        public readonly int $itemNoNumerico,
        public readonly int $sinCantidad,
        public readonly bool $topeAlcanzado,
        public readonly array $muestraItemNoNumerico = [],
        public readonly array $muestraSinCorrespondencia = [],
    ) {}

    /**
     * Frase para el toast del panel y para el mensaje flash. Se queda en los
     * dos numeros que le importan a quien aprieta el boton: cuanto se actualizo
     * y cuanto llego del ERP que el catalogo no tiene.
     */
    public function resumen(): string
    {
        $inicio = $this->simulado
            ? 'Simulacion terminada'
            : 'Stock actualizado desde el ERP';

        return $inicio.": {$this->actualizados} repuesto(s) actualizado(s), "
            ."{$this->sinCorrespondencia} codigo(s) sin correspondencia en el catalogo "
            ."({$this->paginas} pagina(s) leida(s)).";
    }

    /**
     * Contadores para el log y para la respuesta JSON del boton manual.
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
            'sin_correspondencia' => $this->sinCorrespondencia,
            'sin_item' => $this->sinItem,
            'item_no_numerico' => $this->itemNoNumerico,
            'sin_cantidad' => $this->sinCantidad,
        ];
    }
}
