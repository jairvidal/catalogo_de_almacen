<?php

namespace App\Services;

use App\Models\Parametro;
use App\Models\Repuesto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Carga inicial de repuestos.existencia COPIANDO repuestos.stock.
 *
 * `existencia` es el saldo operativo del almacen: lo topa App\Services\Carrito,
 * lo relee SolicitudService::crearDesdeCarrito dentro de la transaccion y lo
 * descuenta SolicitudService::marcarListo al despachar. Tras recrear la tabla
 * con el esquema del ERP quedo en 0 y el almacen no puede despachar nada; esta
 * clase existe para desbloquearlo de una sola vez.
 *
 * EL RIESGO QUE SE ASUMIO, Y QUE HAY QUE DEJAR ESCRITO
 * ----------------------------------------------------
 * `stock` no es solo lo que confirma la API: arrastra tambien los valores de la
 * carga masiva del ERP del 2026-08-21, y en unas 2.669 filas ese valor es un
 * PUNTO DE REPOSICION (stock == stock_minimo, con stock_maximo al doble) y no
 * una existencia fisica. Copiarlo tal cual le da saldo en el catalogo a
 * repuestos que en la estanteria pueden no tenerlo, y el almacen se enterara al
 * ir a buscarlos. EL USUARIO LEYO ESTE ARGUMENTO Y DECIDIO COPIAR `stock` IGUAL,
 * sin excluir esas filas: la alternativa —esperar a la API— dejaba el almacen
 * sin despachar. La decision esta tomada con el dato delante; quien venga
 * despues no tiene que volver a discutirla, pero si tiene que saber que un
 * saldo inicial puede no corresponder a una existencia real y que el ajuste se
 * hace repuesto por repuesto desde el panel.
 *
 * POR QUE ES UNA CLASE HERMANA Y NO UN MODO DE InicializadorExistenciaRepuestos
 * -----------------------------------------------------------------------------
 * Por el mismo motivo por el que aquella se separo del sincronizador: dos
 * escrituras irreversibles del saldo operativo detras del mismo `if` es una
 * edicion desafortunada de distancia entre "copiar el stock" y "escribir lo que
 * dice la API". Separadas, cada comando hace una sola cosa, se teclea con su
 * propio nombre y se lee entero antes de confirmarlo. Ademas no comparten casi
 * nada: aquella lee la API, cruza en PHP y escribe agrupando por cantidad; esta
 * resuelve el caso completo en UNA sentencia SQL. Lo unico verdaderamente comun
 * es la bandera, y por eso la bandera vive en el modelo (ver
 * Parametro::existenciaYaInicializada) y no duplicada en las dos clases.
 *
 * TRES BARRERAS, PORQUE EL ERROR NO TIENE VUELTA ATRAS
 * ----------------------------------------------------
 * 1. El parametro inv.existencia_inicializada, EL MISMO que usa la via de la
 *    API: el hecho "existencia ya recibio su carga inicial" es uno solo y no
 *    puede tener dos banderas. Si una via ya inicializo, la otra no corre.
 *    Su respaldo es "ya inicializada": ante la duda, no se escribe.
 * 2. El UPDATE toca unicamente las filas con existencia = 0. Una fila con saldo
 *    operativo no se pisa jamas, ni aunque alguien vuelva a correr el comando.
 * 3. La confirmacion interactiva del comando (ver InicializarExistenciaDesdeStock).
 *
 * Volver a permitirlo es cambiar el parametro a "No" desde /admin/parametros:
 * una accion visible y deliberada, no una bandera escondida en la consola.
 */
class InicializadorExistenciaDesdeStock
{
    /** Si ya se hizo la carga inicial, por cualquiera de las dos vias. */
    public function yaSeInicializo(): bool
    {
        return Parametro::existenciaYaInicializada();
    }

    /** Cuenta que pasaria, sin escribir absolutamente nada. */
    public function previsualizar(): ResultadoInicializacionDesdeStock
    {
        return $this->contar(simulado: true, afectadas: 0);
    }

    /**
     * Copia stock a existencia en las filas que siguen en cero y marca la
     * bandera para que no se repita.
     *
     * @throws RuntimeException si la carga inicial ya se hizo.
     */
    public function inicializar(): ResultadoInicializacionDesdeStock
    {
        if ($this->yaSeInicializo()) {
            throw new RuntimeException(
                'La carga inicial de existencia ya se hizo (parametro '.Parametro::INV_EXISTENCIA_INICIALIZADA
                .'). Repetirla sobreescribiria el saldo operativo del almacen. Si de verdad hace falta, '
                .'cambie ese parametro a "No" desde /admin/parametros.'
            );
        }

        return DB::transaction(function (): ResultadoInicializacionDesdeStock {
            // Los contadores se leen dentro de la transaccion y antes del UPDATE:
            // asi el reporte describe la misma foto que se acaba de escribir.
            $previo = $this->contar(simulado: false, afectadas: 0);

            // TODO EL TRABAJO EN UNA SOLA SENTENCIA: son ~28.500 filas y no hay
            // ninguna lista de codigos que partir, asi que el limite de 2100
            // parametros de SQL Server no aplica y traerlas a memoria para
            // recorrerlas seria gastar minutos en lo que la base hace de una.
            // La columna de origen se envuelve con la gramatica del conector en
            // vez de interpolar texto: no viene de fuera, pero un identificador
            // no puede ir como parametro enlazado y esta es la forma correcta de
            // citarlo.
            $afectadas = Repuesto::query()
                ->where('existencia', 0)
                // Escribir un 0 sobre un 0 no cambia nada y solo mueve la fecha.
                ->where('stock', '>', 0)
                ->update(['existencia' => DB::raw(DB::getQueryGrammar()->wrap('stock'))]);

            // El UPDATE y la marca van juntos: si se escribiera el saldo y la
            // marca no, la proxima corrida volveria a escribirlo.
            Parametro::marcarExistenciaInicializada();

            $resultado = new ResultadoInicializacionDesdeStock(
                simulado: false,
                total: $previo->total,
                candidatas: $previo->candidatas,
                afectadas: $afectadas,
                yaConSaldo: $previo->yaConSaldo,
                sinStock: $previo->sinStock,
            );

            Log::info(
                'Carga inicial de repuestos.existencia copiando repuestos.stock.',
                $resultado->contadores()
            );

            return $resultado;
        });
    }

    /**
     * Los cuatro conteos, en una sola pasada por la tabla.
     *
     * Los casos son excluyentes y exhaustivos —existencia es not null desde la
     * migracion 2026_08_22_100000, asi que toda fila cae en uno— y por eso la
     * suma de los tres tiene que dar el total.
     */
    private function contar(bool $simulado, int $afectadas): ResultadoInicializacionDesdeStock
    {
        $fila = DB::table((new Repuesto)->getTable())
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when existencia <> 0 then 1 else 0 end) as con_saldo')
            ->selectRaw('sum(case when existencia = 0 and stock > 0 then 1 else 0 end) as candidatas')
            ->selectRaw('sum(case when existencia = 0 and (stock is null or stock <= 0) then 1 else 0 end) as sin_stock')
            ->first();

        return new ResultadoInicializacionDesdeStock(
            simulado: $simulado,
            total: (int) ($fila->total ?? 0),
            candidatas: (int) ($fila->candidatas ?? 0),
            afectadas: $afectadas,
            yaConSaldo: (int) ($fila->con_saldo ?? 0),
            sinStock: (int) ($fila->sin_stock ?? 0),
        );
    }
}
