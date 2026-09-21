<?php

namespace App\Services;

use App\Models\Parametro;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Acceso a la API de inventario de Sidocsa.
 *
 * Aqui vive UNICAMENTE el trato con el endpoint remoto (token, paginacion y
 * lectura de la respuesta). El recorrido del catalogo y la escritura estan en
 * App\Services\SincronizadorStockRepuestos, igual que DetectorColorMarco separa
 * el analisis de la imagen del comando que escribe la categoria.
 *
 * SEGURIDAD: ni el codigo_api ni el token pueden aparecer en un log, en un
 * mensaje de excepcion ni en la salida del comando. Por eso los errores dicen
 * el codigo HTTP y nunca el cuerpo de la respuesta del endpoint de token.
 */
class InventarioApiSidocsa
{
    private const URL_BASE = 'https://user.appsidocsas.com:98/api/v1';

    public const CLAVE_CACHE_TOKEN = 'sidocsa.inventario.token';

    /**
     * El token vence a los 5 minutos. Se cachea por 4 para no estrenar uno que
     * expire a mitad de la paginacion; el margen de un minuto cubre lo que
     * tarda una pagina lenta.
     */
    private const SEGUNDOS_TOKEN = 240;

    /**
     * Tamano de pagina. UNA SOLA CONSTANTE PARA DOS COSAS, a proposito:
     *  - es el valor que viaja en `cant`, y
     *  - es el corte de la ultima pagina en LectorInventarioErp (una pagina con
     *    menos registros que este numero es la ultima).
     *
     * Antes eran dos (`cant` = 500 y `cant_page` = 100) y el corte miraba la
     * segunda. Al desaparecer `cant_page` del cuerpo, quien fija el tamano de
     * pagina es `cant`: si el corte viviera en otra constante, subir una sin la
     * otra cortaria el recorrido en la primera pagina y la sincronizacion
     * traeria solo una parte del inventario sin avisar.
     *
     * POR QUE 10.000 Y NO 1.000: EL COSTO DEL ERP ES POR LLAMADA, NO POR FILA.
     * Medido contra el endpoint real, una pagina tarda lo mismo sea cual sea su
     * tamano (1.000 filas: 5,28 s; 5.000: 5,38 s; 10.000: 4,62 s), asi que con
     * paginas de 1.000 los ~7.100 items del ERP costaban OCHO viajes de ida y
     * vuelta —unos 40 s de pura latencia— y hoy caben en uno solo. La corrida
     * completa bajo de ~47 s a ~13 s, y esa diferencia es la que decide si el
     * boton "Actualizar" del panel alcanza a responder antes de que IIS corte
     * la peticion: con QUEUE_CONNECTION=sync la sincronizacion ocurre DENTRO de
     * ella (ver ParametroAdminController::sincronizarStock).
     *
     * El precio es tener una pagina entera en memoria: medido, 38 MB de pico
     * contra los 28 MB de antes, muy holgado incluso con un memory_limit de
     * 128M. Si algun dia el ERP se pusiera lento con paginas grandes, bajar
     * este numero es un cambio de una linea y el recorrido se reparte solo.
     */
    public const TAMANO_PAGINA = 10000;

    /**
     * Tope duro de paginas. Sin el, un endpoint que siempre responda lleno
     * dejaria el comando girando para siempre. 50 paginas de 10.000 son 500.000
     * items, sobradisimo contra los ~7.100 que devuelve el ERP con la bodega y
     * los criterios configurados hoy.
     */
    public const MAX_PAGINAS = 50;

    /**
     * Campo de la respuesta que trae el codigo del repuesto. CONFIRMADO por el
     * usuario: es `item`, y se cruza contra repuestos.codigo.
     *
     * Este es el unico punto de mapeo entre la respuesta y el catalogo. El
     * cruce es DIRECTO: repuestos.codigo es un entero, asi que basta con leer
     * el campo y compararlo. (Cuando la columna era nvarchar con ceros a la
     * izquierda hacia falta toda una maquinaria de conversion; ya no.)
     */
    private const CAMPO_CODIGO = 'item';

    /** Campo con la existencia. Lo especifico el usuario. */
    private const CAMPO_CANTIDAD = 'cant_disp';

    /**
     * Llaves bajo las que la respuesta puede venir envuelta. Si el JSON ya es
     * una lista, se usa tal cual.
     *
     * @var list<string>
     */
    private const CAMPOS_LISTA = ['data', 'datos', 'resultado', 'result', 'items', 'inventario'];

    /**
     * Llaves bajo las que puede venir el token.
     *
     * @var list<string>
     */
    private const CAMPOS_TOKEN = ['token', 'access_token', 'accessToken', 'Token'];

    /**
     * Token vigente, del cache o recien pedido.
     *
     * @throws RuntimeException si falta la credencial o el endpoint no responde.
     */
    public function token(): string
    {
        $token = Cache::get(self::CLAVE_CACHE_TOKEN);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = $this->pedirToken();

        Cache::put(self::CLAVE_CACHE_TOKEN, $token, self::SEGUNDOS_TOKEN);

        return $token;
    }

    /**
     * Descarta el token cacheado. Se llama cuando el endpoint responde 401,
     * porque significa que el que teniamos ya no sirve.
     */
    public function olvidarToken(): void
    {
        Cache::forget(self::CLAVE_CACHE_TOKEN);
    }

    /**
     * Una pagina de inventario.
     *
     * Si el endpoint responde 401 se bota el token cacheado y se reintenta UNA
     * sola vez con uno fresco: el token vence a los 5 minutos y una corrida
     * larga puede cruzar ese limite.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public function consultarPagina(int $pagina): array
    {
        $respuesta = $this->pedirInventario($pagina, $this->token());

        if ($respuesta->status() === 401) {
            $this->olvidarToken();
            $respuesta = $this->pedirInventario($pagina, $this->token());
        }

        if ($respuesta->failed()) {
            throw new RuntimeException(
                "La API de inventario respondio HTTP {$respuesta->status()} en la pagina {$pagina}."
            );
        }

        return $this->filas($respuesta->json(), $pagina);
    }

    /**
     * Codigo del repuesto dentro de una fila de la API.
     *
     * Se le hace trim porque el valor puede venir con espacios alrededor. Sin
     * campo `item`, o con el campo vacio, devuelve null y el comando lo cuenta.
     *
     * @param  array<string, mixed>  $fila
     */
    public function extraerCodigo(array $fila): ?string
    {
        if (! array_key_exists(self::CAMPO_CODIGO, $fila)) {
            return null;
        }

        $codigo = trim((string) $fila[self::CAMPO_CODIGO]);

        return $codigo === '' ? null : $codigo;
    }

    /**
     * Existencia dentro de una fila de la API.
     *
     * Devuelve float y NO int: repuestos.stock es decimal(12,3) porque el
     * almacen mide en KG y hay items con fraccion. Truncar aqui perderia esos
     * decimales en silencio y el catalogo mostraria menos existencia de la que
     * reporta el ERP.
     *
     * @param  array<string, mixed>  $fila
     */
    public function extraerCantidad(array $fila): ?float
    {
        if (! array_key_exists(self::CAMPO_CANTIDAD, $fila)) {
            return null;
        }

        $valor = $fila[self::CAMPO_CANTIDAD];

        if (! is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }

    public function campoCodigo(): string
    {
        return self::CAMPO_CODIGO;
    }

    /**
     * Pide un token nuevo. El cuerpo lleva la credencial, asi que ningun
     * mensaje de error de este metodo puede repetir la peticion ni la respuesta.
     */
    private function pedirToken(): string
    {
        $codigo = (string) Parametro::valor('codigo_api', '');

        if ($codigo === '') {
            throw new RuntimeException(
                'El parametro codigo_api esta vacio o inactivo; configurelo en /admin/parametros.'
            );
        }

        try {
            $respuesta = $this->cliente()->post(self::URL_BASE.'/token', ['codigo' => $codigo]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con la API de inventario para pedir el token.', 0, $e);
        }

        if ($respuesta->failed()) {
            throw new RuntimeException(
                "La API de inventario respondio HTTP {$respuesta->status()} al pedir el token."
            );
        }

        $token = $this->buscarToken($respuesta->json());

        if ($token === null) {
            // Se registra que llaves trajo la respuesta, no su contenido.
            Log::warning('La respuesta del token de inventario no trae ninguna llave conocida.', [
                'llaves' => $this->llaves($respuesta->json()),
                'esperadas' => self::CAMPOS_TOKEN,
            ]);

            throw new RuntimeException('La API de inventario no devolvio un token reconocible.');
        }

        return $token;
    }

    /**
     * Arma el cuerpo de la consulta. ES EL UNICO SITIO donde se construye: la
     * consola y el boton del panel pasan los dos por aqui.
     *
     * `cant` y `page` van como numeros JSON; `id_cia`, `id_bod` y `tipo_inv`
     * como cadenas. `criterio` (SUBGRUPO) y `criterio_2` (GRUPO) son arreglos
     * de IDs numericos del ERP y viajan SIEMPRE, aunque queden vacios: el
     * endpoint espera la llave.
     */
    private function pedirInventario(int $pagina, string $token): Response
    {
        try {
            return $this->cliente()
                ->withToken($token)
                ->post(self::URL_BASE.'/inventario/consultar', [
                    'cant' => self::TAMANO_PAGINA,
                    'page' => $pagina,
                    'id_cia' => (string) Parametro::valor('api.id_cia', ''),
                    'id_bod' => (string) Parametro::valor('api.id_bod', ''),
                    'tipo_inv' => 'INV1455',
                    'criterio' => Parametro::listaEnteros(Parametro::API_CRITERIO),
                    'criterio_2' => Parametro::listaEnteros(Parametro::API_CRITERIO_2),
                    'existencias' => 1,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("No se pudo conectar con la API de inventario en la pagina {$pagina}.", 0, $e);
        }
    }

    /**
     * Cliente base. La verificacion del certificado TLS se deja como viene
     * (activada): si el host da problema de certificado hay que arreglar el
     * host, no apagar la verificacion.
     */
    private function cliente()
    {
        return Http::acceptJson()
            ->timeout(30)
            ->connectTimeout(10)
            // Backoff creciente. Solo se reintenta lo que puede mejorar solo
            // (corte de red, 429, 5xx); un 401 o un 400 no se reintentan aqui
            // porque el 401 lo resuelve consultarPagina() con token nuevo.
            ->retry([500, 2000], 0, fn (Throwable $e) => $this->vaAReintentarse($e), throw: false);
    }

    private function vaAReintentarse(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        $estado = $e->response->status();

        return $estado === 429 || $estado >= 500;
    }

    /**
     * Normaliza el cuerpo de la respuesta a una lista de filas.
     *
     * @return list<array<string, mixed>>
     */
    private function filas(mixed $cuerpo, int $pagina): array
    {
        if ($cuerpo === null) {
            return [];
        }

        if (array_is_list((array) $cuerpo)) {
            return array_values(array_filter((array) $cuerpo, 'is_array'));
        }

        foreach (self::CAMPOS_LISTA as $campo) {
            if (isset($cuerpo[$campo]) && is_array($cuerpo[$campo])) {
                return array_values(array_filter($cuerpo[$campo], 'is_array'));
            }
        }

        Log::warning('La pagina de inventario no trae una lista reconocible de registros.', [
            'pagina' => $pagina,
            'llaves' => $this->llaves($cuerpo),
            'esperadas' => self::CAMPOS_LISTA,
        ]);

        return [];
    }

    private function buscarToken(mixed $cuerpo): ?string
    {
        if (! is_array($cuerpo)) {
            return is_string($cuerpo) && $cuerpo !== '' ? $cuerpo : null;
        }

        foreach (self::CAMPOS_TOKEN as $campo) {
            if (isset($cuerpo[$campo]) && is_string($cuerpo[$campo]) && $cuerpo[$campo] !== '') {
                return $cuerpo[$campo];
            }

            // Variante habitual: el token viene envuelto en data/datos.
            foreach (['data', 'datos'] as $envoltura) {
                if (isset($cuerpo[$envoltura][$campo]) && is_string($cuerpo[$envoltura][$campo]) && $cuerpo[$envoltura][$campo] !== '') {
                    return $cuerpo[$envoltura][$campo];
                }
            }
        }

        return null;
    }

    /**
     * Nombres de las llaves de la respuesta, para poder diagnosticar sin
     * volcar el contenido (que puede traer el token).
     *
     * @return list<string>
     */
    private function llaves(mixed $cuerpo): array
    {
        return is_array($cuerpo) ? array_map('strval', array_keys($cuerpo)) : [];
    }
}
