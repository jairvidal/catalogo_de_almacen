<?php

namespace App\Services;

use App\Exceptions\SincronizacionEnCursoException;
use App\Models\Parametro;
use App\Models\Repuesto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Trae la existencia del ERP y la escribe en repuestos.stock.
 *
 * Aqui vive la orquestacion (cruzar contra el catalogo y escribir en lotes) para
 * que la disparen por igual el comando repuestos:sincronizar-stock y el boton
 * "Actualizar" de /admin/parametros. InventarioApiSidocsa trata con el endpoint,
 * LectorInventarioErp recorre las paginas —lo comparte con el inicializador de
 * existencia, para que ninguno de los dos duplique el recorrido— y el comando
 * quedo como capa de presentacion en consola.
 *
 * ESTA SINCRONIZACION NO TOCA `existencia`, Y ES LA REGLA QUE NO SE ROMPE.
 * `existencia` es el saldo operativo del almacen (el antiguo
 * cantidad_disponible): es el que topa App\Services\Carrito, el que relee
 * SolicitudService::crearDesdeCarrito dentro de la transaccion y el que
 * descuenta SolicitudService::marcarListo. Si esta sincronizacion lo pisara, el
 * ERP borraria lo ya despachado y el almacen entregaria contra un saldo
 * fantasma. `stock` es el dato que reporta el ERP y ninguna regla de negocio lo
 * consume. La carga inicial de `existencia` es un acto aparte y de una sola vez:
 * App\Services\InicializadorExistenciaRepuestos.
 *
 * EL CRUCE ES DIRECTO: repuestos.codigo es un entero con indice unico y el
 * campo `item` de la API trae ese mismo numero, asi que se comparan tal cual.
 * Hasta el 2026-08-21 la columna era nvarchar con ceros a la izquierda y hacia
 * falta un indice intval(codigo) => codigo con deteccion de colisiones; esa
 * maquinaria se elimino con el cambio de esquema.
 */
class SincronizadorStockRepuestos
{
    /**
     * Codigos por sentencia. SQL Server admite 2100 parametros y cada IN gasta
     * uno por codigo, asi que 120 sobra de margen (mismo lote que usan
     * RepuestoSeeder y repuestos:clasificar).
     */
    private const LOTE = 120;

    /**
     * Marca de la ultima corrida. Va en el cache y no en tbl_parametro porque
     * es estado operativo, no configuracion: en la tabla apareceria en el panel
     * como una fila editable que el administrador no debe tocar. El cache de
     * este proyecto es la base de datos (CACHE_STORE=database), asi que la
     * marca sobrevive a un reinicio igual que una fila.
     */
    public const CLAVE_ULTIMA_CORRIDA = 'repuestos.sincronizar_stock.ultima';

    /**
     * Marca de "hay una corrida trabajando ahora". Es lo que lee el panel para
     * decir "Sincronizacion en curso...".
     *
     * Va aparte del candado porque un lock no se puede consultar sin intentar
     * adquirirlo, y el panel solo quiere mirar. Lleva el mismo vencimiento que
     * el candado: si el proceso muere de golpe, ninguna de las dos se queda
     * pegada para siempre.
     */
    public const CLAVE_EN_CURSO = 'repuestos.sincronizar_stock.en_curso';

    /**
     * Candado que impide que el boton manual y el programador de tareas se
     * pisen. Es la comprobacion atomica: preguntar por CLAVE_EN_CURSO y despues
     * escribirla seria una carrera entre las dos corridas.
     */
    public const CLAVE_CANDADO = 'repuestos.sincronizar_stock.candado';

    /**
     * Vencimiento del candado. Una corrida completa contra el ERP tarda
     * minutos, no horas; 15 deja margen de sobra y garantiza que un proceso
     * muerto no deje la sincronizacion bloqueada para siempre.
     */
    private const SEGUNDOS_CANDADO = 900;

    /** Respaldo cuando tiempo.actualizar falta o trae algo que no es un numero util. */
    private const MINUTOS_POR_DEFECTO = 60;

    /** Cuantos ejemplos se listan en el log de cada anomalia, para poder diagnosticar. */
    private const MUESTRA = 10;

    public function __construct(private readonly LectorInventarioErp $lector) {}

    /**
     * Corre la sincronizacion completa.
     *
     * @throws SincronizacionEnCursoException si ya hay otra corriendo.
     * @throws \RuntimeException si falla la API (la propaga InventarioApiSidocsa).
     */
    public function sincronizar(bool $simular = false): ResultadoSincronizacionStock
    {
        $candado = Cache::lock(self::CLAVE_CANDADO, self::SEGUNDOS_CANDADO);

        if (! $candado->get()) {
            throw new SincronizacionEnCursoException(
                'Ya hay una sincronizacion de stock en curso; espere a que termine.'
            );
        }

        Cache::put(self::CLAVE_EN_CURSO, now()->getTimestamp(), self::SEGUNDOS_CANDADO);

        try {
            // La marca se pone ANTES de trabajar: si la API falla, la proxima
            // corrida automatica espera el intervalo completo en vez de
            // reintentar cada minuto contra un endpoint caido.
            if (! $simular) {
                $this->marcarCorrida();
            }

            return $this->correr($simular);
        } finally {
            // finally y no al final del try: si la API revienta, la marca y el
            // candado tienen que soltarse igual o el panel se queda diciendo
            // "en curso" para siempre y el boton no vuelve a servir.
            Cache::forget(self::CLAVE_EN_CURSO);
            $candado->release();
        }
    }

    /** Si hay una corrida trabajando en este momento. */
    public function hayCorridaEnCurso(): bool
    {
        return Cache::has(self::CLAVE_EN_CURSO);
    }

    /**
     * Modo de actualizacion configurado en el parametro inv.actualizar.
     *
     * EL RESPALDO ES 'automatico' A PROPOSITO: es como se comportaba el sistema
     * antes de que existiera el parametro, y un stock desactualizado no avisa.
     * Si el parametro falta o alguien lo deja inactivo, lo seguro es que el ERP
     * siga alimentando el catalogo, no que la sincronizacion se apague en
     * silencio. Un valor que no este en la lista cerrada tambien cae aqui.
     */
    public function modo(): string
    {
        $modo = (string) Parametro::valor(Parametro::INV_ACTUALIZAR, Parametro::INV_AUTOMATICO);

        return array_key_exists($modo, Parametro::opcionesDe(Parametro::INV_ACTUALIZAR))
            ? $modo
            : Parametro::INV_AUTOMATICO;
    }

    public function esManual(): bool
    {
        return $this->modo() === Parametro::INV_MANUAL;
    }

    /**
     * Filtro del programador de tareas: la tarea esta registrada cada minuto y
     * solo deja pasar la corrida cuando el modo es automatico y ya pasaron los
     * minutos configurados en el parametro tiempo.actualizar.
     */
    public function debeCorrer(): bool
    {
        // En modo manual el programador no dispara NUNCA: la sincronizacion
        // sale solo del boton "Actualizar" del panel.
        if ($this->esManual()) {
            return false;
        }

        $ultima = Cache::get(self::CLAVE_ULTIMA_CORRIDA);

        if (! is_numeric($ultima)) {
            return true;
        }

        return (now()->getTimestamp() - (int) $ultima) >= ($this->minutosDeIntervalo() * 60);
    }

    public function marcarCorrida(): void
    {
        // Marca de tiempo unix: no depende de la zona horaria ni de como
        // serialice el cache un objeto de fecha.
        Cache::forever(self::CLAVE_ULTIMA_CORRIDA, now()->getTimestamp());
    }

    /**
     * Minutos entre dos corridas automaticas. Un parametro mal escrito
     * ("cada hora", "0") no puede convertirse en una llamada a la API por
     * minuto, asi que cae al respaldo.
     */
    public function minutosDeIntervalo(): int
    {
        $minutos = (int) Parametro::valor('tiempo.actualizar', (string) self::MINUTOS_POR_DEFECTO);

        return $minutos < 1 ? self::MINUTOS_POR_DEFECTO : $minutos;
    }

    public function ultimaCorrida(): ?Carbon
    {
        $marca = Cache::get(self::CLAVE_ULTIMA_CORRIDA);

        return is_numeric($marca)
            ? Carbon::createFromTimestamp((int) $marca, config('app.timezone'))
            : null;
    }

    /** Cuando le toca a la proxima corrida automatica, o null si nunca ha corrido. */
    public function proximaCorrida(): ?Carbon
    {
        return $this->ultimaCorrida()?->addMinutes($this->minutosDeIntervalo());
    }

    /** El trabajo en si, ya con el candado tomado. */
    private function correr(bool $simular): ResultadoSincronizacionStock
    {
        $lectura = $this->lector->leer();

        [$porCantidad, $emparejados, $sinCorrespondencia] = $this->cruzarContraElCatalogo($lectura->existencias);

        $actualizados = ($simular || $emparejados === 0)
            ? $emparejados
            : $this->escribirStock($porCantidad);

        $resultado = new ResultadoSincronizacionStock(
            simulado: $simular,
            paginas: $lectura->paginas,
            recibidos: $lectura->recibidos,
            actualizados: $actualizados,
            sinCorrespondencia: count($sinCorrespondencia),
            sinItem: $lectura->sinItem,
            itemNoNumerico: $lectura->itemNoNumerico,
            sinCantidad: $lectura->sinCantidad,
            topeAlcanzado: $lectura->topeAlcanzado,
            muestraItemNoNumerico: $lectura->muestraItemNoNumerico,
            muestraSinCorrespondencia: array_slice($sinCorrespondencia, 0, self::MUESTRA),
        );

        Log::info('Sincronizacion de stock terminada.', $resultado->contadores());

        if ($sinCorrespondencia !== []) {
            // Son codigos de inventario, no datos sensibles, y sin ellos no hay
            // como diagnosticar si el desfase es de formato o de bodega.
            Log::info('Codigos de la API de inventario sin correspondencia en el catalogo.', [
                'total' => count($sinCorrespondencia),
                'muestra' => $resultado->muestraSinCorrespondencia,
            ]);
        }

        return $resultado;
    }

    /**
     * Cruza lo que mando el ERP contra el catalogo y agrupa por cantidad.
     *
     * EL CRUCE VA POR LOTES CONTRA LA BASE, no trayendo el catalogo a memoria.
     * La tabla pasó de 591 a 28.490 filas: un pluck('codigo') cargaria las
     * 28.490 en cada corrida para descartar casi todas, mientras que el lado
     * del ERP (lo que ya esta en memoria) es el conjunto pequeno y filtrado por
     * bodega y grupos. Cada lote de 120 codigos resuelve un `where codigo in
     * (...)` que aprovecha el indice unico de la columna; 120 es el mismo tope
     * de siempre por el limite de 2100 parametros de SQL Server.
     *
     * Se agrupa por cantidad para no emitir un UPDATE por repuesto: media
     * bodega comparte la misma existencia, empezando por el 0, asi que un
     * UPDATE por valor y lote resuelve miles de filas en pocas sentencias.
     *
     * La clave del agrupamiento es la cantidad EN TEXTO y no el float: PHP
     * convierte a entero las claves numericas de un arreglo, asi que 12.5 y
     * 12.9 acabarian en el mismo grupo y se escribiria una existencia falsa.
     *
     * @param  array<int, float>  $existencias  codigo => cantidad
     * @return array{0: array<string, array{cantidad: float, codigos: list<int>}>, 1: int, 2: list<int>}
     */
    private function cruzarContraElCatalogo(array $existencias): array
    {
        /** @var array<string, array{cantidad: float, codigos: list<int>}> $porCantidad */
        $porCantidad = [];
        $emparejados = 0;
        /** @var list<int> $sinCorrespondencia */
        $sinCorrespondencia = [];

        foreach (array_chunk($existencias, self::LOTE, preserve_keys: true) as $lote) {
            $enElCatalogo = array_flip(
                Repuesto::query()
                    ->whereIn('codigo', array_keys($lote))
                    ->pluck('codigo')
                    ->map(fn ($codigo): int => (int) $codigo)
                    ->all()
            );

            foreach ($lote as $codigo => $cantidad) {
                if (! isset($enElCatalogo[$codigo])) {
                    // No se crea ningun repuesto: lo que el catalogo no tiene,
                    // se cuenta y se reporta.
                    $sinCorrespondencia[] = $codigo;

                    continue;
                }

                $clave = (string) $cantidad;

                $porCantidad[$clave]['cantidad'] = $cantidad;
                $porCantidad[$clave]['codigos'][] = $codigo;
                $emparejados++;
            }
        }

        return [$porCantidad, $emparejados, $sinCorrespondencia];
    }

    /**
     * Escribe la existencia del ERP en repuestos.stock, y en ninguna otra
     * columna. `existencia` NO se toca aqui: ver la nota de cabecera.
     *
     * @param  array<string, array{cantidad: float, codigos: list<int>}>  $porCantidad
     * @return int filas afectadas
     */
    private function escribirStock(array $porCantidad): int
    {
        $afectadas = 0;

        foreach ($porCantidad as $grupo) {
            foreach (array_chunk($grupo['codigos'], self::LOTE) as $lote) {
                $afectadas += Repuesto::whereIn('codigo', $lote)
                    ->update(['stock' => $grupo['cantidad']]);
            }
        }

        return $afectadas;
    }
}
