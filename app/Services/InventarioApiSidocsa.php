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

    /** Tamano de pagina que se le pide al endpoint de inventario. */
    public const CANT_PAGE = 100;

    /**
     * Valor que el ERP espera en `cant`. NO es un tope de la respuesta: se
     * probo con 2000 y el endpoint devolvio los mismos 500 registros, asi que
     * no esta truncando nada. Lo que acota el resultado es `existencias: 1`
     * junto con la bodega y los grupos: de los ~13.470 repuestos del catalogo
     * en esos cinco grupos, solo ~500 tienen existencia en P2ALM.
     *
     * Quien pagina de verdad es `page`, que va de 1 en adelante hasta que una
     * pagina trae menos de CANT_PAGE registros.
     */
    private const CANT = 500;

    /**
     * Tope duro de paginas. Sin el, un endpoint que siempre responda lleno
     * dejaria el comando girando para siempre. 500 paginas de 100 son 50.000
     * items, holgado contra los ~29.000 del ERP.
     */
    public const MAX_PAGINAS = 500;

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

    private function pedirInventario(int $pagina, string $token): Response
    {
        try {
            return $this->cliente()
                ->withToken($token)
                ->post(self::URL_BASE.'/inventario/consultar', [
                    'cant' => (string) self::CANT,
                    'cant_page' => (string) self::CANT_PAGE,
                    'tipo_inv' => 'INV1455',
                    'id_bod' => (string) Parametro::valor('api.id_bod', ''),
                    'id_cia' => (string) Parametro::valor('api.id_cia', ''),
                    'page' => (string) $pagina,
                    'existencias' => 1,
                    // criterio (SUBGRUPO) y criterio_2 (GRUPO) viajan SIEMPRE y
                    // SIEMPRE como arreglo JSON, aunque queden vacios: el
                    // endpoint espera listas, no cadenas. El administrador los
                    // configura como una lista separada por comas y
                    // Parametro::lista() la parte sin recortarle los espacios a
                    // cada elemento (ver la nota de ese metodo).
                    'criterio' => Parametro::lista(Parametro::API_CRITERIO),
                    'criterio_2' => Parametro::lista(Parametro::API_CRITERIO_2),
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
