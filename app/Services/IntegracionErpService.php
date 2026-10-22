<?php

namespace App\Services;

use App\Exceptions\IntegracionErpException;
use App\Exceptions\ReenvioErpException;
use App\Models\Parametro;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Envio de la solicitud APROBADA al servicio web de importacion del ERP
 * (encabezado V 1.4.39, detalle y bitacora V 1.4.40). Lo llama
 * AprobacionSolicitudService::aprobar() DESPUES del commit, igual que los
 * correos: un fallo aqui nunca revierte ni tumba la aprobacion; queda en el
 * log y en tbl_solicitudes.erp_error.
 *
 * Configuracion (seccion "Importacion ERP" de /admin/parametros, leida con
 * Parametro::valor()):
 *   - erp.importacion_url  -> URL a la que se hace el POST.
 *   - erp.importacion_json -> plantilla del JSON. Los valores con el marcador
 *     "adicionar_dato" son los que completa el codigo.
 *
 * El JSON se arma como String concatenando cada "clave": valor en el orden de
 * la plantilla (pedido del usuario), pero cada clave y cada valor pasan por
 * json_encode y el resultado se valida con json_decode antes de enviarlo:
 * concatenar no puede producir un JSON roto ni dejar que una observacion con
 * comillas cambie la estructura.
 *
 * DETALLE ("movimiento"): el PRIMER objeto del arreglo de la plantilla es el
 * molde; se repite una vez por renglon de tbl_solicitud_detalle, en el orden
 * del formulario (id del detalle), con los valores YA GUARDADOS por la
 * aprobacion (los que imputo el servidor, no lo que digito el navegador).
 *
 * Consecutivo "numero": tbl_consecutivo, fila 'erp.importacion', UPDATE
 * atomico con OUTPUT. Se reserva solo cuando ya se sabe que se va a enviar
 * (URL y plantilla validas) y queda guardado en tbl_solicitudes.erp_numero.
 * Si el envio falla, el numero NO se libera ni se le da a otra solicitud: un
 * timeout no dice si el ERP alcanzo a crear el documento, y reintentar con el
 * MISMO numero deja que el ERP rechace el duplicado en vez de crear dos
 * documentos. La consecuencia es que pueden quedar huecos en la numeracion
 * del ERP si una solicitud nunca llega a enviarse.
 *
 * Cada documento armado queda en BitacoraIntegracionErp
 * (storage/logs/integracion-erp.txt) con el resultado del envio.
 */
class IntegracionErpService
{
    /** Valor de la plantilla que el codigo debe completar. */
    public const MARCADOR = 'adicionar_dato';

    /** Clave de la plantilla que lleva el detalle. */
    public const CLAVE_DETALLE = 'movimiento';

    /** Fila de tbl_consecutivo que numera los documentos enviados al ERP. */
    public const CONSECUTIVO = 'erp.importacion';

    /** Las fechas del documento son las de Colombia, como el resto de fechas visibles. */
    public const ZONA = 'America/Bogota';

    public const FORMATO_FECHA = 'Ymd';

    public const TIMEOUT_SEGUNDOS = 90;

    public const TIMEOUT_CONEXION_SEGUNDOS = 20;

    /** Prefijo de lo que se guarda en erp_error. */
    public const PREFIJO_ERROR = 'Integracion ERP: ';

    private const FLAGS_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Candado (Cache::lock) por solicitud: un solo envio al ERP a la vez. */
    public const CANDADO = 'integracion_erp.solicitud.';

    /** Lo que puede tardar un envio completo (conexion + respuesta), con margen. */
    public const CANDADO_SEGUNDOS = self::TIMEOUT_CONEXION_SEGUNDOS + self::TIMEOUT_SEGUNDOS + 30;

    public function __construct(private BitacoraIntegracionErp $bitacora) {}

    /**
     * Arma el JSON de la solicitud ya aprobada y lo envia al ERP.
     *
     * Nunca lanza: devuelve true si el ERP respondio 2xx y false en cualquier
     * otro caso (con el motivo en $solicitud->erp_error).
     *
     * Toma el mismo candado que reenviar(): si en ese instante ya hay un envio
     * de la misma solicitud en curso (un reenvio desde el panel), no manda un
     * segundo documento y devuelve false sin tocar erp_error.
     */
    public function integracionERP(Solicitud $solicitud): bool
    {
        $candado = Cache::lock(self::CANDADO.$solicitud->id, self::CANDADO_SEGUNDOS);

        try {
            $obtenido = $candado->get();
        } catch (\Throwable $e) {
            // Sin candado disponible (falla el store de cache) se envia igual:
            // la aprobacion no debe perder su envio por esto.
            Log::error('No se pudo tomar el candado del envio al ERP; se envia sin el.', [
                'solicitud' => $solicitud->numero,
                'error' => $e->getMessage(),
            ]);

            return $this->enviarDocumento($solicitud);
        }

        if (! $obtenido) {
            Log::warning('Envio al ERP omitido: ya hay otro envio de la misma solicitud en curso.', [
                'solicitud' => $solicitud->numero,
            ]);

            return false;
        }

        try {
            return $this->enviarDocumento($solicitud);
        } finally {
            $candado->release();
        }
    }

    /**
     * Reenvio desde el panel (boton "Reenviar al ERP", V 1.4.41).
     *
     * Concurrencia: no se puede tener una transaccion abierta durante el POST,
     * asi que la exclusion la da el candado de cache por solicitud (el mismo
     * de integracionERP) y, ya con el candado, se RELEE la solicitud y se
     * vuelve a evaluar puedeReenviarseAlErp(): el segundo clic, o el segundo
     * administrador, encuentra erp_enviado_at puesto y no manda nada. El
     * numero del ERP se reutiliza (reservarNumero).
     *
     * Devuelve la solicitud releida con el resultado (erp_enviado_at o
     * erp_error).
     *
     * @throws ReenvioErpException si no procede
     */
    public function reenviar(int $solicitudId, User $usuario): Solicitud
    {
        $candado = Cache::lock(self::CANDADO.$solicitudId, self::CANDADO_SEGUNDOS);

        if (! $candado->get()) {
            throw new ReenvioErpException('Ya hay un envio de esta solicitud al ERP en curso. Espere un momento y vuelva a consultarla.');
        }

        try {
            $solicitud = Solicitud::query()->findOrFail($solicitudId);

            if ($solicitud->erp_enviado_at !== null) {
                throw new ReenvioErpException("La solicitud {$solicitud->numero} ya fue enviada al ERP (documento {$solicitud->erp_numero}).");
            }

            if (! $solicitud->puedeReenviarseAlErp()) {
                throw new ReenvioErpException("La solicitud {$solicitud->numero} no se puede enviar al ERP: solo se envian las aprobadas por el solicitante que no esten rechazadas.");
            }

            Log::info('Reenvio de la solicitud al ERP desde el panel.', [
                'solicitud' => $solicitud->numero,
                'erp_numero' => $solicitud->erp_numero,
                'usuario_id' => $usuario->id,
                'usuario' => $usuario->name,
            ]);

            $this->enviarDocumento($solicitud);

            return $solicitud;
        } finally {
            $candado->release();
        }
    }

    /**
     * El envio propiamente dicho. Nunca lanza. Quien lo llama ya tiene (o
     * intento) el candado de la solicitud.
     */
    private function enviarDocumento(Solicitud $solicitud): bool
    {
        $json = null;
        $momento = now();

        try {
            $url = trim((string) Parametro::valor(Parametro::ERP_IMPORTACION_URL, ''));

            if ($url === '') {
                throw new IntegracionErpException('la URL WS de la seccion "Importacion ERP" esta vacia; no se envio.');
            }

            $plantilla = $this->leerPlantilla((string) Parametro::valor(Parametro::ERP_IMPORTACION_JSON, ''));
            $renglones = $this->renglones($solicitud);

            // A partir de aqui ya se va a enviar: se reserva el numero.
            $numero = $this->reservarNumero($solicitud);
            $json = $this->construirJson(
                $plantilla,
                $this->camposDelEncabezado($solicitud, $numero),
                array_map(fn (SolicitudItem $item) => $this->camposDelRenglon($item), $renglones),
            );

            $this->enviar($url, $json, $solicitud);

            $solicitud->forceFill(['erp_enviado_at' => now(), 'erp_error' => null])->save();
            $this->bitacora->registrar($momento, (string) $solicitud->numero, $numero, 'enviado', $json);

            Log::info('Solicitud enviada al ERP.', ['solicitud' => $solicitud->numero, 'erp_numero' => $numero]);

            return true;
        } catch (\Throwable $e) {
            $motivo = $e instanceof IntegracionErpException
                ? $e->getMessage()
                : 'error inesperado al preparar o enviar el documento.';

            Log::error('No se pudo enviar la solicitud al ERP.', [
                'solicitud' => $solicitud->numero,
                'erp_numero' => $solicitud->erp_numero,
                'error' => $e->getMessage(),
                'tipo' => $e::class,
            ]);

            $solicitud->forceFill(['erp_error' => mb_substr(self::PREFIJO_ERROR.$motivo, 0, 500)])->save();

            // Solo si el documento llego a armarse: sin JSON no hay nada que registrar.
            if ($json !== null) {
                $this->bitacora->registrar($momento, (string) $solicitud->numero, $solicitud->erp_numero, 'fallo: '.$motivo, $json);
            }

            return false;
        }
    }

    /**
     * Valores del ENCABEZADO, clave de la plantilla => valor. Solo estas
     * claves se sobrescriben; las demas (tipo_documento, estado,
     * entrega_en_dias...) viajan tal como estan en la plantilla.
     *
     * Todos como texto, igual que los valores de la plantilla. Un dato
     * ausente viaja como "".
     *
     * @return array<string, string>
     */
    public function camposDelEncabezado(Solicitud $solicitud, int $numero): array
    {
        $hoy = now(self::ZONA)->format(self::FORMATO_FECHA);

        return [
            'numero' => (string) $numero,
            'fecha' => $hoy,
            'tercero' => '',
            'referencia' => '',
            // Campo cod_oc del formulario de aprobacion. Con la mayuscula: ver CLAUDE.md.
            'numero_OC' => (string) ($solicitud->cod_OC ?? ''),
            // Campo oculto cod_solicitante del detalle = solicitante_erp_id,
            // que guarda el codigo del ERP del solicitante (col_codigo_erp).
            'solicitante' => (string) ($solicitud->solicitante_erp_id ?? ''),
            'cargo' => '',
            'fecha_entrega' => $hoy,
            // Campo observaciones del formulario de aprobacion.
            'notas' => (string) ($solicitud->observaciones ?? ''),
        ];
    }

    /**
     * Valores de UN renglon del detalle, clave del molde => valor. Salen de lo
     * que la aprobacion guardo en tbl_solicitud_detalle (los campos
     * item-{codigo}-{columna} del formulario, ya imputados por el servidor).
     * bodega_id y un viajan como esten en la plantilla.
     *
     * @return array<string, string>
     */
    public function camposDelRenglon(SolicitudItem $item): array
    {
        return [
            // Columna "Id" de la tabla REPUESTOS = codigo del repuesto (item del ERP).
            'item_id' => $item->codigoDelRepuesto(),
            'motivo_id' => (string) ($item->cod_motivo ?? ''),
            'cant' => $this->cantidad($item->cantidad),
            'und' => (string) ($item->cod_unidad_medida ?? ''),
            'centro_operacion' => (string) ($item->cod_centro_operacion ?? ''),
            'centro_costo' => (string) ($item->cod_centro_de_costo ?? ''),
            'proyecto' => (string) ($item->cod_proyecto ?? ''),
            'notas' => (string) ($item->notas_item ?? ''),
            'descripcion' => '',
        ];
    }

    /**
     * Concatena el JSON en el orden de la plantilla. Con $renglones, la clave
     * "movimiento" se arma repitiendo el primer objeto de la plantilla (el
     * molde) una vez por renglon.
     *
     * @param  array<string, string>  $campos
     * @param  list<array<string, string>>|null  $renglones
     *
     * @throws IntegracionErpException
     */
    public function construirJson(object $plantilla, array $campos, ?array $renglones = null): string
    {
        $crudos = [];
        $molde = $this->moldeDelDetalle($plantilla);

        if ($molde !== null && $renglones !== null) {
            $objetos = array_map(fn (array $renglon) => $this->objetoJson($molde, $renglon), $renglones);
            $crudos[self::CLAVE_DETALLE] = '['.implode(', ', $objetos).']';
        }

        $json = $this->objetoJson($plantilla, $campos, $crudos);

        try {
            json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IntegracionErpException('el documento armado no es un JSON valido.');
        }

        return $json;
    }

    /**
     * "{"clave": valor, ...}" en el orden del molde. $campos se codifican con
     * json_encode; $crudos ya son JSON (el arreglo del detalle).
     *
     * @param  array<string, string>  $campos
     * @param  array<string, string>  $crudos
     */
    private function objetoJson(object $molde, array $campos, array $crudos = []): string
    {
        $partes = [];

        foreach (get_object_vars($molde) as $clave => $valorPlantilla) {
            $valor = match (true) {
                array_key_exists($clave, $crudos) => $crudos[$clave],
                array_key_exists($clave, $campos) => json_encode($campos[$clave], self::FLAGS_JSON),
                default => json_encode($valorPlantilla, self::FLAGS_JSON),
            };

            $partes[] = json_encode((string) $clave, self::FLAGS_JSON).': '.$valor;
        }

        return '{'.implode(', ', $partes).'}';
    }

    /** Primer objeto de "movimiento", o null si la plantilla no trae detalle. */
    private function moldeDelDetalle(object $plantilla): ?object
    {
        $detalle = $plantilla->{self::CLAVE_DETALLE} ?? null;

        return is_array($detalle) && isset($detalle[0]) && is_object($detalle[0]) ? $detalle[0] : null;
    }

    /**
     * Cantidad aprobada como texto con punto decimal y sin ceros de sobra
     * ("2.500" -> "2.5", "3.000" -> "3"); vacia si no hay.
     */
    private function cantidad(string|int|float|null $cantidad): string
    {
        if ($cantidad === null || $cantidad === '') {
            return '';
        }

        $texto = (string) $cantidad;

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    /**
     * Renglones del detalle en el orden del formulario, releidos de la base
     * (con lo que guardo la aprobacion) y con el repuesto para su codigo.
     *
     * @return list<SolicitudItem>
     */
    private function renglones(Solicitud $solicitud): array
    {
        return $solicitud->items()->with('repuesto:id,codigo')->orderBy('id')->get()->all();
    }

    /**
     * La plantilla tiene que ser un objeto JSON y no puede pedir (en el
     * encabezado o en el molde del detalle) un dato que el codigo no sabe
     * completar. Se detecta antes de reservar el numero, para no gastarlo.
     *
     * @throws IntegracionErpException
     */
    private function leerPlantilla(string $texto): object
    {
        if (trim($texto) === '') {
            throw new IntegracionErpException('la Estructura JSON de la seccion "Importacion ERP" esta vacia; no se envio.');
        }

        try {
            $plantilla = json_decode($texto, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IntegracionErpException('la Estructura JSON de la seccion "Importacion ERP" no es un JSON valido; no se envio.');
        }

        if (! is_object($plantilla)) {
            throw new IntegracionErpException('la Estructura JSON debe ser un objeto { ... }; no se envio.');
        }

        $this->exigirMarcadoresConocidos($plantilla, array_keys($this->camposDelEncabezado(new Solicitud, 0)), '');

        if (property_exists($plantilla, self::CLAVE_DETALLE)) {
            $molde = $this->moldeDelDetalle($plantilla);

            if ($molde === null) {
                throw new IntegracionErpException('"movimiento" debe ser un arreglo cuyo primer elemento sea un objeto { ... }; no se envio.');
            }

            $this->exigirMarcadoresConocidos($molde, array_keys($this->camposDelRenglon(new SolicitudItem)), 'movimiento.');
        }

        return $plantilla;
    }

    /**
     * @param  list<string>  $conocidas
     *
     * @throws IntegracionErpException
     */
    private function exigirMarcadoresConocidos(object $objeto, array $conocidas, string $prefijo): void
    {
        foreach (get_object_vars($objeto) as $clave => $valor) {
            if ($valor === self::MARCADOR && ! in_array($clave, $conocidas, true)) {
                throw new IntegracionErpException("la plantilla pide completar \"{$prefijo}{$clave}\" y el sistema no sabe con que dato; no se envio.");
            }
        }
    }

    /**
     * Numero del documento en el ERP para ESTA solicitud. Si ya tiene uno
     * (un envio anterior fallo) se reutiliza; si no, se toma el siguiente del
     * consecutivo con un UPDATE atomico (dos aprobaciones simultaneas nunca
     * reciben el mismo) y se guarda en la misma transaccion, con la solicitud
     * bloqueada. El indice unico ux_tbl_solicitudes_erp_numero es la ultima
     * defensa.
     *
     * @throws IntegracionErpException
     */
    private function reservarNumero(Solicitud $solicitud): int
    {
        return DB::transaction(function () use ($solicitud) {
            $actual = Solicitud::whereKey($solicitud->id)->lockForUpdate()->value('erp_numero');

            if ($actual !== null) {
                $solicitud->forceFill(['erp_numero' => (int) $actual])->syncOriginalAttribute('erp_numero');

                return (int) $actual;
            }

            $fila = DB::selectOne(
                'update [tbl_consecutivo]
                    set [col_ultimo] = [col_ultimo] + 1, [updated_at] = getdate()
                 output inserted.[col_ultimo] as numero
                  where [col_nombre] = ?',
                [self::CONSECUTIVO]
            );

            if ($fila === null) {
                throw new IntegracionErpException('no existe el consecutivo "'.self::CONSECUTIVO.'" en tbl_consecutivo.');
            }

            $numero = (int) $fila->numero;
            $solicitud->forceFill(['erp_numero' => $numero])->save();

            return $numero;
        });
    }

    /**
     * POST con el JSON exacto que se armo (withBody: no se re-codifica). TLS
     * verificado (opcion por defecto del cliente). El cuerpo enviado va a la
     * bitacora, no al log; de la respuesta solo un extracto en el log, nunca
     * en erp_error.
     *
     * @throws IntegracionErpException
     */
    private function enviar(string $url, string $json, Solicitud $solicitud): void
    {
        try {
            $respuesta = Http::acceptJson()
                ->connectTimeout(self::TIMEOUT_CONEXION_SEGUNDOS)
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->withBody($json, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            throw new IntegracionErpException('no se pudo conectar con el servicio web del ERP (sin respuesta o tiempo agotado).');
        }

        if (! $respuesta->successful()) {
            Log::warning('El servicio web del ERP rechazo el documento.', [
                'solicitud' => $solicitud->numero,
                'estado_http' => $respuesta->status(),
                'respuesta' => mb_substr($respuesta->body(), 0, 500),
            ]);

            throw new IntegracionErpException("el servicio web del ERP respondio HTTP {$respuesta->status()}.");
        }
    }
}
