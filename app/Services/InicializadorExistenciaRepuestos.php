<?php

namespace App\Services;

use App\Models\Parametro;
use App\Models\Repuesto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Carga inicial de repuestos.existencia con lo que reporta la API del ERP.
 *
 * POR QUE ESTO ES UN SERVICIO APARTE Y NO UN PASO DE LA SINCRONIZACION
 * -------------------------------------------------------------------
 * `existencia` es el saldo operativo del almacen: lo topa App\Services\Carrito,
 * lo relee SolicitudService::crearDesdeCarrito dentro de la transaccion y lo
 * descuenta SolicitudService::marcarListo cuando se despacha. Si la
 * sincronizacion con el ERP lo escribiera en cada corrida, borraria todo lo ya
 * despachado y el almacen entregaria contra un saldo fantasma.
 *
 * Se evaluo meter la inicializacion dentro de SincronizadorStockRepuestos
 * protegida por una bandera. Se descarto: dejaria en el camino rutinario (el
 * que corre solo cada hora y el que dispara un boton) una rama capaz de
 * destruir el saldo operativo, a un `if` mal editado de distancia. Separarlo
 * hace que la regla se cumpla POR CONSTRUCCION — el sincronizador simplemente
 * no tiene codigo que escriba `existencia` — y deja el acto irreversible en un
 * comando que hay que teclear y confirmar.
 *
 * POR QUE LEE LA API Y NO COPIA `stock`
 * -------------------------------------
 * `stock` no contiene solo lo que confirmo la API: arrastra tambien los valores
 * de la carga masiva del ERP del 2026-08-21, y en 2.669 de esas filas el valor
 * es un punto de reposicion (stock == stock_minimo, con stock_maximo al doble),
 * no una existencia fisica. Copiar `stock` a `existencia` le habria dado saldo
 * a repuestos que no lo tienen. Solo cuenta lo que el ERP reporta ahora, con su
 * `cant_disp`, y por eso se comparte LectorInventarioErp con el sincronizador.
 *
 * TRES BARRERAS, PORQUE EL ERROR NO TIENE VUELTA ATRAS
 * ----------------------------------------------------
 * 1. El parametro inv.existencia_inicializada, que vive en la base y por eso
 *    sobrevive a reinicios y a un `cache:clear` (una bandera en el cache no).
 *    EL RESPALDO CUANDO FALTA O ESTA INACTIVO ES "YA INICIALIZADA", al reves
 *    que el respaldo de inv.actualizar: alli lo seguro es seguir sincronizando,
 *    aqui lo seguro es NO escribir. Si no se puede confirmar que hace falta, no
 *    se hace.
 * 2. El UPDATE toca unicamente las filas con existencia = 0. Una fila que ya
 *    tiene saldo operativo nunca se pisa, ni aunque alguien vuelva a correr el
 *    comando.
 * 3. La confirmacion interactiva del comando (ver InicializarExistenciaRepuestos).
 *
 * Volver a permitirlo es cambiar el parametro a "No" desde /admin/parametros:
 * una accion visible y deliberada, no una bandera escondida en la consola.
 */
class InicializadorExistenciaRepuestos
{
    /**
     * Codigos por sentencia. SQL Server admite 2100 parametros y cada IN gasta
     * uno por codigo; 120 es el mismo lote que usa el resto del proyecto.
     */
    private const LOTE = 120;

    public function __construct(private readonly LectorInventarioErp $lector) {}

    /** Si ya se hizo la carga inicial. Ante la duda, dice que si. */
    public function yaSeInicializo(): bool
    {
        return Parametro::valor(Parametro::INV_EXISTENCIA_INICIALIZADA, Parametro::INV_SI) !== Parametro::INV_NO;
    }

    /**
     * Lee la API y calcula que filas recibirian saldo, sin escribir nada.
     *
     * @throws RuntimeException si falla la API.
     */
    public function previsualizar(): ResultadoInicializacionExistencia
    {
        return $this->resolver($this->lector->leer());
    }

    /**
     * Escribe el saldo operativo con lo que reporta el ERP y marca el parametro
     * para que no se repita.
     *
     * @throws RuntimeException si la carga inicial ya se hizo o si falla la API.
     */
    public function inicializar(): ResultadoInicializacionExistencia
    {
        if ($this->yaSeInicializo()) {
            throw new RuntimeException(
                'La carga inicial de existencia ya se hizo (parametro '.Parametro::INV_EXISTENCIA_INICIALIZADA
                .'). Repetirla sobreescribiria el saldo operativo del almacen. Si de verdad hace falta, '
                .'cambie ese parametro a "No" desde /admin/parametros.'
            );
        }

        // La API se lee FUERA de la transaccion: tarda minutos y mantener la
        // transaccion abierta mientras tanto bloquearia la tabla sin necesidad.
        $previo = $this->resolver($this->lector->leer());

        return DB::transaction(function () use ($previo): ResultadoInicializacionExistencia {
            $afectadas = 0;

            foreach ($previo->porCantidad as $grupo) {
                foreach (array_chunk($grupo['codigos'], self::LOTE) as $lote) {
                    // El where existencia = 0 se repite aqui a proposito: entre
                    // la lectura y la escritura pudo entrar un despacho.
                    $afectadas += Repuesto::whereIn('codigo', $lote)
                        ->where('existencia', 0)
                        ->update(['existencia' => $grupo['cantidad']]);
                }
            }

            // El UPDATE y la marca van juntos: si se escribiera el saldo y la
            // marca no, la proxima corrida volveria a escribirlo.
            Parametro::query()
                ->where('col_nombre', Parametro::INV_EXISTENCIA_INICIALIZADA)
                ->update(['col_valor' => Parametro::INV_SI]);

            Log::info('Carga inicial de repuestos.existencia realizada.', [
                'filas' => $afectadas,
                'recibidos_del_erp' => $previo->recibidos,
                'sin_correspondencia' => $previo->sinCorrespondencia,
            ]);

            return new ResultadoInicializacionExistencia(
                simulado: false,
                recibidos: $previo->recibidos,
                candidatas: $previo->candidatas,
                afectadas: $afectadas,
                yaConSaldo: $previo->yaConSaldo,
                sinCorrespondencia: $previo->sinCorrespondencia,
                porCantidad: [],
            );
        });
    }

    /**
     * Cruza lo que mando el ERP contra el catalogo y agrupa por cantidad.
     *
     * Mismo criterio que SincronizadorStockRepuestos: se cruza por lotes contra
     * la base para no traer las 28.490 filas a memoria, y se agrupa por cantidad
     * para resolver miles de filas en pocas sentencias. La clave del agrupamiento
     * es la cantidad EN TEXTO porque PHP convierte a entero las claves numericas
     * de un arreglo, y 12.5 y 12.9 acabarian en el mismo grupo.
     */
    private function resolver(LecturaInventarioErp $lectura): ResultadoInicializacionExistencia
    {
        /** @var array<string, array{cantidad: float, codigos: list<int>}> $porCantidad */
        $porCantidad = [];
        $candidatas = 0;
        $yaConSaldo = 0;
        $sinCorrespondencia = 0;

        foreach (array_chunk($lectura->existencias, self::LOTE, preserve_keys: true) as $lote) {
            $enElCatalogo = Repuesto::query()
                ->whereIn('codigo', array_keys($lote))
                ->pluck('existencia', 'codigo');

            foreach ($lote as $codigo => $cantidad) {
                $existenciaActual = $enElCatalogo->get($codigo);

                if ($existenciaActual === null) {
                    $sinCorrespondencia++;

                    continue;
                }

                if ((float) $existenciaActual > 0) {
                    // Ya tiene saldo operativo: no se pisa nunca.
                    $yaConSaldo++;

                    continue;
                }

                if ($cantidad <= 0) {
                    // Escribir un 0 sobre un 0 no cambia nada.
                    continue;
                }

                $clave = (string) $cantidad;

                $porCantidad[$clave]['cantidad'] = $cantidad;
                $porCantidad[$clave]['codigos'][] = $codigo;
                $candidatas++;
            }
        }

        return new ResultadoInicializacionExistencia(
            simulado: true,
            recibidos: $lectura->recibidos,
            candidatas: $candidatas,
            afectadas: 0,
            yaConSaldo: $yaConSaldo,
            sinCorrespondencia: $sinCorrespondencia,
            porCantidad: $porCantidad,
        );
    }
}
