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
 * ESCRIBE `stock` Y `fecha_actual_ERP`, Y NO TOCA `fecha_actualizacion`.
 * fecha_actualizacion es el UPDATED_AT del modelo (ver App\Models\Repuesto), asi
 * que hasta el 2026-09-21 cada corrida se la movia a miles de repuestos y era
 * imposible saber si una fila la habia editado una persona en el panel o solo la
 * habia rozado el ERP. Por eso las dos escrituras van dentro de
 * Repuesto::withoutTimestamps() y la marca de la corrida va en su propia
 * columna, fecha_actual_ERP, que se escribe a TODOS los repuestos que el ERP
 * confirmo —cambien o no de stock— para que una fecha vieja signifique de
 * verdad "el ERP dejo de reportar este item".
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

    /**
     * Diferencia a partir de la cual dos existencias se consideran distintas.
     *
     * repuestos.stock es decimal(12,3): media milesima es la mitad del ultimo
     * decimal que la columna sabe guardar. Comparar con === seria un error de
     * bulto —son floats— y comparar sin margen dejaria un item que el ERP
     * reporta como 12.5001 reescribiendose en cada corrida contra el 12.500 que
     * la base pudo almacenar, para siempre y sin cambiar nada.
     */
    private const TOLERANCIA = 0.0005;

    /**
     * Zona en la que se le muestran las fechas al administrador.
     *
     * Los datos siguen guardandose y calculandose en la zona de la aplicacion
     * (UTC): esto es SOLO presentacion. Sin esto el panel decia "Ultima corrida:
     * 11:38 pm" para una corrida que en Colombia ocurrio a las 6:38 pm, y el
     * administrador leia esa hora imposible como que la marca no se habia
     * movido.
     */
    private const ZONA_VISIBLE = 'America/Bogota';

    public function __construct(
        private readonly LectorInventarioErp $lector,
        private readonly BitacoraSincronizacionStock $bitacora,
    ) {}

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
     * Desde cuando dice el sistema que hay una corrida en curso, EN HORA DE
     * COLOMBIA (ver ZONA_VISIBLE), o null si no hay ninguna.
     *
     * La marca guarda el timestamp de cuando arranco, y hasta ahora nadie lo
     * leia: el panel solo decia "Sincronizacion en curso..." sin mas. Esa frase
     * sola no distingue una corrida que arranco hace diez segundos de un
     * candado que quedo colgado porque el proceso murio, que son dos
     * situaciones opuestas —una hay que esperarla y la otra hay que liberarla—
     * y el administrador no tenia con que diferenciarlas.
     */
    public function corridaEnCursoDesde(): ?Carbon
    {
        $marca = Cache::get(self::CLAVE_EN_CURSO);

        return is_numeric($marca)
            ? Carbon::createFromTimestamp((int) $marca, self::ZONA_VISIBLE)
            : null;
    }

    /**
     * Suelta el candado y la marca a la fuerza.
     *
     * POR QUE HACE FALTA: las dos se sueltan en el `finally` de sincronizar(),
     * pero un `finally` no corre si el proceso muere de golpe —y eso es
     * exactamente lo que pasa cuando IIS corta la peticion del boton a mitad de
     * la corrida—. Entonces quedan colgadas hasta vencer (15 minutos) y durante
     * ese rato el panel dice "en curso" y CADA intento nuevo rebota con un 409
     * sin llegar a llamar al ERP: el sistema se ve averiado mucho despues de
     * que el fallo real ocurrio.
     *
     * Salir de ahi requeria borrar filas de `cache_locks` a mano, y ni siquiera
     * `php artisan cache:clear` servia: el store de base de datos solo vacia la
     * tabla `cache`, los candados viven en otra y sobreviven.
     *
     * ES UNA PALANCA MANUAL Y PELIGROSA a proposito: si de verdad hay una
     * corrida trabajando, liberar el candado permite que arranque una segunda
     * en paralelo. Por eso no se llama sola en ningun sitio, la dispara el
     * administrador desde el panel y la vista se lo advierte.
     */
    public function liberarBloqueo(): void
    {
        Cache::lock(self::CLAVE_CANDADO)->forceRelease();
        Cache::forget(self::CLAVE_EN_CURSO);

        Log::warning('Se libero a mano el bloqueo de la sincronizacion de stock.');
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

    /**
     * Ultima corrida, EN HORA DE COLOMBIA (ver ZONA_VISIBLE). La marca guardada
     * es un timestamp unix, o sea que no cambia de valor: lo unico que cambia
     * es la zona en la que se lee para pintarla.
     */
    public function ultimaCorrida(): ?Carbon
    {
        $marca = Cache::get(self::CLAVE_ULTIMA_CORRIDA);

        return is_numeric($marca)
            ? Carbon::createFromTimestamp((int) $marca, self::ZONA_VISIBLE)
            : null;
    }

    /** Como se pinta la ultima corrida en el panel. Un solo formato para la vista y para el JSON del boton. */
    public function ultimaCorridaFormateada(): ?string
    {
        return $this->ultimaCorrida()?->format('d/m/Y h:i a');
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

        [$porCantidad, $porCambiar, $alDia, $sinCorrespondencia] =
            $this->cruzarContraElCatalogo($lectura->existencias);

        // UNA SOLA MARCA PARA TODA LA CORRIDA: se calcula aqui y se pasa a las
        // dos escrituras, de modo que las miles de filas que confirmo el ERP
        // queden con la misma fecha y se puedan agrupar de un vistazo. Si cada
        // sentencia usara su propio now(), una corrida de varios minutos
        // dejaria un abanico de fechas que no distingue una corrida de otra.
        $confirmadoEn = now();

        // Simulando se reporta lo que cambiaria; escribiendo, lo que la base
        // dice que cambio de verdad. Los dos numeros deben coincidir, y si no
        // coinciden la fuente de verdad es la base.
        $actualizados = ($simular || $porCambiar === 0)
            ? $porCambiar
            : $this->escribirStock($porCantidad, $confirmadoEn);

        // Los que ya estaban al dia NO cambian de stock, pero el ERP los acaba
        // de confirmar igual que a los demas: ver marcarConfirmacionErp(). Una
        // simulacion no escribe nada, tampoco la fecha.
        if (! $simular && $alDia !== []) {
            $this->marcarConfirmacionErp($alDia, $confirmadoEn);
        }

        $resultado = new ResultadoSincronizacionStock(
            simulado: $simular,
            paginas: $lectura->paginas,
            recibidos: $lectura->recibidos,
            actualizados: $actualizados,
            sinCambio: count($alDia),
            sinCorrespondencia: count($sinCorrespondencia),
            sinItem: $lectura->sinItem,
            itemNoNumerico: $lectura->itemNoNumerico,
            sinCantidad: $lectura->sinCantidad,
            topeAlcanzado: $lectura->topeAlcanzado,
            muestraItemNoNumerico: $lectura->muestraItemNoNumerico,
            muestraSinCorrespondencia: array_slice($sinCorrespondencia, 0, self::MUESTRA),
        );

        Log::info('Sincronizacion de stock terminada.', $resultado->contadores());

        // Una simulacion no deja rastro en ninguna parte: ni en el stock, ni en
        // la marca de la ultima corrida, ni en la bitacora.
        if (! $simular) {
            $this->bitacora->registrar($resultado);
        }

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
     * Cruza lo que mando el ERP contra el catalogo, separa lo que de verdad
     * cambia de lo que ya estaba al dia y agrupa por cantidad lo primero.
     *
     * POR QUE SE COMPARA CONTRA EL STOCK QUE YA HAY: un UPDATE en SQL Server
     * reporta como afectadas TODAS las filas que empareja, cambien o no de
     * valor, asi que sin esta comparacion "actualizados" era en realidad
     * "escritos" y decia 7.092 tanto cuando el ERP traia novedades como cuando
     * no habia movido un solo item. El administrador no podia distinguir una
     * corrida util de una corrida en vacio. De paso deja de reescribir miles de
     * filas identicas —y de tocarles updated_at— en cada corrida.
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
     * DEVUELVE LOS CODIGOS DE LOS QUE YA ESTABAN AL DIA, no solo cuantos son:
     * esas filas no cambian de stock pero el ERP acaba de confirmarlas, asi que
     * les toca su fecha_actual_ERP igual que a las demas (ver
     * marcarConfirmacionErp).
     *
     * @param  array<int, float>  $existencias  codigo => cantidad
     * @return array{0: array<string, array{cantidad: float, codigos: list<int>}>, 1: int, 2: list<int>, 3: list<int>}
     */
    private function cruzarContraElCatalogo(array $existencias): array
    {
        /** @var array<string, array{cantidad: float, codigos: list<int>}> $porCantidad */
        $porCantidad = [];
        $porCambiar = 0;
        /** @var list<int> $alDia */
        $alDia = [];
        /** @var list<int> $sinCorrespondencia */
        $sinCorrespondencia = [];

        foreach (array_chunk($existencias, self::LOTE, preserve_keys: true) as $lote) {
            // Se trae tambien el stock actual: es la misma consulta y el mismo
            // indice, con una columna mas, y sin ella no hay como saber cual
            // fila cambia de verdad.
            $enElCatalogo = Repuesto::query()
                ->whereIn('codigo', array_keys($lote))
                ->pluck('stock', 'codigo')
                ->mapWithKeys(fn ($stock, $codigo): array => [
                    // Un stock nulo NO se convierte a 0: se deja en null para
                    // que cuente como distinto de cualquier cantidad y la
                    // primera corrida le escriba el valor del ERP.
                    (int) $codigo => $stock === null ? null : (float) $stock,
                ])
                ->all();

            foreach ($lote as $codigo => $cantidad) {
                if (! array_key_exists($codigo, $enElCatalogo)) {
                    // No se crea ningun repuesto: lo que el catalogo no tiene,
                    // se cuenta y se reporta.
                    $sinCorrespondencia[] = $codigo;

                    continue;
                }

                $actual = $enElCatalogo[$codigo];

                if ($actual !== null && abs($actual - $cantidad) < self::TOLERANCIA) {
                    $alDia[] = $codigo;

                    continue;
                }

                $clave = (string) $cantidad;

                $porCantidad[$clave]['cantidad'] = $cantidad;
                $porCantidad[$clave]['codigos'][] = $codigo;
                $porCambiar++;
            }
        }

        return [$porCantidad, $porCambiar, $alDia, $sinCorrespondencia];
    }

    /**
     * Escribe la existencia del ERP en repuestos.stock y la fecha de esta
     * corrida en fecha_actual_ERP. `existencia` NO se toca aqui: ver la nota de
     * cabecera.
     *
     * Las dos columnas viajan en la MISMA sentencia porque son la misma fila y
     * el mismo lote: marcar la fecha aparte seria recorrer dos veces los mismos
     * codigos sin ganar nada.
     *
     * @param  array<string, array{cantidad: float, codigos: list<int>}>  $porCantidad
     * @return int filas afectadas
     */
    private function escribirStock(array $porCantidad, Carbon $confirmadoEn): int
    {
        $afectadas = 0;

        // withoutTimestamps: fecha_actualizacion es el updated_at del modelo y
        // esta sincronizacion no puede moverla. Ver la nota de cabecera.
        Repuesto::withoutTimestamps(function () use ($porCantidad, $confirmadoEn, &$afectadas): void {
            foreach ($porCantidad as $grupo) {
                foreach (array_chunk($grupo['codigos'], self::LOTE) as $lote) {
                    $afectadas += Repuesto::whereIn('codigo', $lote)
                        ->update([
                            'stock' => $grupo['cantidad'],
                            'fecha_actual_ERP' => $confirmadoEn,
                        ]);
                }
            }
        });

        return $afectadas;
    }

    /**
     * Marca fecha_actual_ERP en los repuestos que el ERP confirmo pero que no
     * cambiaron de stock.
     *
     * POR QUE SE MARCAN TAMBIEN ESTOS, Y NO SOLO LOS QUE CAMBIARON: la columna
     * responde "hace cuanto que el ERP confirmo este item", no "hace cuanto que
     * se movio". Un repuesto con existencia estable durante meses —que es lo
     * normal en media bodega— apareceria con la fecha de hace meses y se leeria
     * como que el ERP dejo de reportarlo, que es justo lo contrario de lo que
     * pasa. Marcando a todos los confirmados, una fecha vieja significa de
     * verdad que el item se cayo del inventario del ERP, que es la senal util.
     *
     * @param  list<int>  $codigos
     */
    private function marcarConfirmacionErp(array $codigos, Carbon $confirmadoEn): void
    {
        // withoutTimestamps: esta sentencia existe precisamente para NO tocar
        // fecha_actualizacion de miles de filas que nadie edito.
        Repuesto::withoutTimestamps(function () use ($codigos, $confirmadoEn): void {
            // Mismo lote de 120 de siempre: cada codigo del IN gasta uno de los
            // 2100 parametros que admite SQL Server.
            foreach (array_chunk($codigos, self::LOTE) as $lote) {
                Repuesto::whereIn('codigo', $lote)
                    ->update(['fecha_actual_ERP' => $confirmadoEn]);
            }
        });
    }
}
