<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\Repuesto;
use App\Services\BitacoraSincronizacionStock;
use App\Services\InventarioApiSidocsa;
use App\Services\SincronizadorStockRepuestos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 *
 * Toda la API se responde con Http::fake(): nunca se llama al endpoint real.
 */
class SincronizarStockTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // El token y la marca de la ultima corrida viven en el cache, que en
        // este proyecto es la base de datos: se limpian las tres claves para que
        // una prueba no arrastre el estado de la anterior.
        Cache::forget(InventarioApiSidocsa::CLAVE_CACHE_TOKEN);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_EN_CURSO);

        $this->parametro('codigo_api', 'CODIGO-DE-PRUEBA');
        $this->parametro('tiempo.actualizar', '60');
        $this->parametro('api.id_bod', 'P2ALM');
        $this->parametro('api.id_cia', '1');
        // Los dos criterios son listas de IDs numericos separados por coma.
        $this->parametro(Parametro::API_CRITERIO_2, '2002');
        $this->parametro(Parametro::API_CRITERIO, '');
        // El modo por defecto es automatico: es el que deja correr la tarea.
        $this->parametro(Parametro::INV_ACTUALIZAR, Parametro::INV_AUTOMATICO);
        // Sin esto, el inicializador se niega a trabajar (su respaldo es "ya
        // inicializada", que es lo seguro fuera de las pruebas).
        $this->parametro(Parametro::INV_EXISTENCIA_INICIALIZADA, Parametro::INV_NO);
    }

    protected function tearDown(): void
    {
        Cache::forget(InventarioApiSidocsa::CLAVE_CACHE_TOKEN);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_EN_CURSO);

        parent::tearDown();
    }

    private function parametro(string $nombre, string $valor): Parametro
    {
        $parametro = Parametro::firstOrNew(['col_nombre' => $nombre]);
        $parametro->col_valor = $valor;
        $parametro->col_estado = Parametro::ESTADO_ACTIVO;
        $parametro->save();

        return $parametro;
    }

    private function repuesto(int $codigo, float $existencia = 7, float $stock = 0): Repuesto
    {
        return Repuesto::create([
            'codigo' => $codigo,
            'nombre' => 'Repuesto de prueba '.$codigo,
            'unidad_medida' => 'UND',
            'existencia' => $existencia,
            'stock' => $stock,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * Relleno para llenar una pagina. Los codigos van en un rango de 9 digitos
     * que el catalogo real no usa (alli ninguno pasa de 6), asi que no cruzan
     * con ningun repuesto de verdad.
     *
     * @return list<array<string, mixed>>
     */
    private function filas(int $cantidad, int $base = 990000000, int $existencia = 5): array
    {
        $filas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = ['item' => (string) ($base + $i), 'cant_disp' => $existencia];
        }

        return $filas;
    }

    /**
     * Codigo de 9 digitos, muy por encima de los del catalogo real, para no
     * chocar con el indice unico de repuestos.codigo.
     */
    private function codigoDePrueba(): int
    {
        return random_int(900000000, 949999999);
    }

    public function test_el_token_se_cachea_y_no_se_vuelve_a_pedir(): void
    {
        Http::fake([
            '*/api/v1/token' => Http::sequence()
                ->push(['token' => 'token-1'])
                ->push(['token' => 'token-2']),
        ]);

        $api = app(InventarioApiSidocsa::class);

        $this->assertSame('token-1', $api->token());
        $this->assertSame('token-1', $api->token());

        $this->assertSame(1, $this->llamadasA('token'));
    }

    public function test_al_olvidar_el_token_se_pide_uno_nuevo(): void
    {
        Http::fake([
            '*/api/v1/token' => Http::sequence()
                ->push(['token' => 'token-1'])
                ->push(['token' => 'token-2']),
        ]);

        $api = app(InventarioApiSidocsa::class);

        $this->assertSame('token-1', $api->token());
        $api->olvidarToken();
        $this->assertSame('token-2', $api->token());

        $this->assertSame(2, $this->llamadasA('token'));
    }

    public function test_recorre_todas_las_paginas_hasta_una_incompleta(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::sequence()
                // Pagina llena: obliga a pedir la siguiente. El tamano de pagina
                // es el mismo numero que viaja en `cant` (una sola constante).
                ->push(['data' => $this->filas(InventarioApiSidocsa::TAMANO_PAGINA)])
                // Pagina incompleta: aqui corta el recorrido.
                ->push(['data' => [['item' => (string) $codigo, 'cant_disp' => 42]]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $this->assertSame(2, $this->llamadasA('inventario/consultar'));
        $this->assertSame(42.0, Repuesto::where('codigo', $codigo)->value('stock'));
    }

    public function test_un_401_bota_el_token_y_reintenta_con_uno_nuevo(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo);

        Http::fake([
            '*/api/v1/token' => Http::sequence()
                ->push(['token' => 'token-vencido'])
                ->push(['token' => 'token-fresco']),
            '*/api/v1/inventario/consultar' => Http::sequence()
                ->push(['error' => 'token vencido'], 401)
                ->push(['data' => [['item' => (string) $codigo, 'cant_disp' => 11]]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        // Dos tokens: el cacheado se boto al ver el 401.
        $this->assertSame(2, $this->llamadasA('token'));
        $this->assertSame(11.0, Repuesto::where('codigo', $codigo)->value('stock'));

        // El reintento viajo con el token nuevo.
        $ultima = Http::recorded(fn ($peticion) => str_contains($peticion->url(), 'inventario/consultar'))->last();
        $this->assertSame('Bearer token-fresco', $ultima[0]->header('Authorization')[0]);
    }

    public function test_los_codigos_sin_correspondencia_no_crean_repuestos(): void
    {
        $existente = $this->codigoDePrueba();
        $this->repuesto($existente);
        $fantasma = $this->codigoDePrueba();

        $repuestosAntes = Repuesto::count();

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $existente, 'cant_disp' => 3],
                ['item' => (string) $fantasma, 'cant_disp' => 99],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $this->assertSame($repuestosAntes, Repuesto::count());
        $this->assertNull(Repuesto::where('codigo', $fantasma)->first());
        $this->assertSame(3.0, Repuesto::where('codigo', $existente)->value('stock'));
    }

    public function test_simular_no_escribe(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 4);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 500],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock', ['--simular' => true])->assertSuccessful();

        $this->assertSame(4.0, Repuesto::where('codigo', $codigo)->value('stock'));
        // Una simulacion tampoco consume el turno de la proxima corrida.
        $this->assertNull(Cache::get(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA));
    }

    /**
     * El numero que ve el administrador tiene que ser el de los repuestos que
     * CAMBIARON, no el de las filas que la sentencia toco: en SQL Server un
     * UPDATE reporta como afectadas todas las que empareja, cambien o no, y con
     * ese numero una corrida en vacio se veia igual que una con novedades.
     */
    public function test_una_segunda_corrida_sin_novedades_no_cuenta_actualizados(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $sincronizador = app(SincronizadorStockRepuestos::class);

        $primera = $sincronizador->sincronizar();
        $this->assertSame(1, $primera->actualizados);
        $this->assertSame(0, $primera->sinCambio);

        $segunda = $sincronizador->sincronizar();

        $this->assertSame(0, $segunda->actualizados);
        $this->assertSame(1, $segunda->sinCambio);
        $this->assertSame(1, $segunda->emparejados());
        // Y el mensaje lo dice con todas las letras, en vez de un "0" mudo.
        $this->assertStringContainsString('ya estaba al dia', $segunda->resumen());
        $this->assertSame(250.0, Repuesto::where('codigo', $codigo)->value('stock'));
    }

    /**
     * Un cero por falta de cruce y un cero porque no hay novedades son dos
     * cosas distintas: una es una averia de configuracion y la otra es la
     * normalidad. El mensaje tiene que separarlas.
     */
    public function test_el_mensaje_distingue_no_cruzar_de_no_tener_novedades(): void
    {
        $fantasma = $this->codigoDePrueba();

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $fantasma, 'cant_disp' => 3],
            ]]),
        ]);

        $resultado = app(SincronizadorStockRepuestos::class)->sincronizar();

        $this->assertSame(0, $resultado->actualizados);
        $this->assertSame(0, $resultado->sinCambio);
        $this->assertSame(1, $resultado->sinCorrespondencia);
        $this->assertStringContainsString('corresponde a un repuesto del catalogo', $resultado->resumen());
        $this->assertStringNotContainsString('ya estaba al dia', $resultado->resumen());
    }

    /** Sin registros del ERP el aviso apunta a los parametros del filtro, no a un "0" sin explicacion. */
    public function test_el_mensaje_avisa_cuando_el_erp_no_devuelve_nada(): void
    {
        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => []]),
        ]);

        $resultado = app(SincronizadorStockRepuestos::class)->sincronizar();

        $this->assertSame(0, $resultado->recibidos);
        $this->assertStringContainsString('no devolvio ningun registro', $resultado->resumen());
        $this->assertStringContainsString('api.criterio', $resultado->resumen());
    }

    /*
    |--------------------------------------------------------------------------
    | Bitacora storage/logs/sincronizacion.txt
    |--------------------------------------------------------------------------
    */

    public function test_cada_corrida_anexa_una_linea_a_la_bitacora(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $previo = $this->bitacoraGuardada();

        try {
            $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();
            $primera = $this->lineasNuevasDeLaBitacora($previo);

            $this->assertCount(1, $primera);
            $this->assertStringContainsString('actualizados: 1', $primera[0]);
            $this->assertStringContainsString('no actualizados: 0', $primera[0]);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \|/', $primera[0]);

            // Se ANEXA: la segunda corrida no borra la primera y deja ver que
            // esta vez no hubo novedades.
            $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();
            $ambas = $this->lineasNuevasDeLaBitacora($previo);

            $this->assertCount(2, $ambas);
            $this->assertStringContainsString('actualizados: 0', $ambas[1]);
            $this->assertStringContainsString('ya al dia: 1', $ambas[1]);
        } finally {
            $this->restaurarBitacora($previo);
        }
    }

    /** Una simulacion no deja rastro en ninguna parte, tampoco en la bitacora. */
    public function test_simular_no_escribe_la_bitacora(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $previo = $this->bitacoraGuardada();

        try {
            $this->artisan('repuestos:sincronizar-stock', ['--simular' => true])->assertSuccessful();

            $this->assertSame([], $this->lineasNuevasDeLaBitacora($previo));
        } finally {
            $this->restaurarBitacora($previo);
        }
    }

    /** Ni la credencial ni el token pueden acabar en un archivo que alguien abre con el bloc de notas. */
    public function test_la_bitacora_no_lleva_credenciales(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-secreto']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $previo = $this->bitacoraGuardada();

        try {
            $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();
            $linea = implode('', $this->lineasNuevasDeLaBitacora($previo));

            $this->assertStringNotContainsString('CODIGO-DE-PRUEBA', $linea);
            $this->assertStringNotContainsString('token-secreto', $linea);
        } finally {
            $this->restaurarBitacora($previo);
        }
    }

    /**
     * Un fallo al escribir la bitacora no puede tumbar una sincronizacion que
     * ya escribio el stock; mismo criterio que el correo de "pedido listo".
     *
     * Se provoca de verdad, no con un doble: en el lugar del archivo se pone
     * una carpeta, de modo que file_put_contents no pueda escribir.
     */
    public function test_un_fallo_de_la_bitacora_no_tumba_la_sincronizacion(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $previo = $this->bitacoraGuardada();
        $ruta = app(BitacoraSincronizacionStock::class)->ruta();

        if (file_exists($ruta)) {
            unlink($ruta);
        }

        mkdir($ruta);
        Log::spy();

        try {
            $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();
        } finally {
            rmdir($ruta);
            $this->restaurarBitacora($previo);
        }

        // El stock quedo escrito y el fallo de la bitacora quedo en el log.
        $this->assertSame(250.0, Repuesto::where('codigo', $codigo)->value('stock'));
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'bitacora de sincronizacion'))
            ->once();
    }

    /** Contenido actual de la bitacora, para poder devolverla como estaba. */
    private function bitacoraGuardada(): ?string
    {
        $ruta = app(BitacoraSincronizacionStock::class)->ruta();

        return file_exists($ruta) ? file_get_contents($ruta) : null;
    }

    /**
     * Lineas que la corrida anadio al final de la bitacora.
     *
     * @return list<string>
     */
    private function lineasNuevasDeLaBitacora(?string $previo): array
    {
        $ruta = app(BitacoraSincronizacionStock::class)->ruta();

        if (! file_exists($ruta)) {
            return [];
        }

        $contenido = file_get_contents($ruta);
        $nuevo = $previo === null ? $contenido : substr($contenido, strlen($previo));

        return array_values(array_filter(
            preg_split('/\R/', $nuevo) ?: [],
            fn (string $linea): bool => str_contains($linea, 'actualizados:')
        ));
    }

    private function restaurarBitacora(?string $previo): void
    {
        $ruta = app(BitacoraSincronizacionStock::class)->ruta();

        if ($previo === null) {
            if (file_exists($ruta)) {
                unlink($ruta);
            }

            return;
        }

        file_put_contents($ruta, $previo, LOCK_EX);
    }

    /**
     * LA PRUEBA QUE SOSTIENE TODO EL DISENO: separa el dato del ERP (`stock`)
     * del saldo operativo (`existencia`). Si esta falla, el ERP borra lo ya
     * despachado y el almacen entrega contra un saldo fantasma.
     */
    public function test_la_sincronizacion_no_toca_existencia(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $repuesto = Repuesto::where('codigo', $codigo)->firstOrFail();

        $this->assertSame(250.0, $repuesto->stock);
        $this->assertSame(7.0, $repuesto->existencia);
    }

    /*
    |--------------------------------------------------------------------------
    | fecha_actual_ERP y fecha_actualizacion
    |--------------------------------------------------------------------------
    */

    /**
     * Fija fecha_actualizacion a un valor conocido sin pasar por el modelo.
     *
     * Va por el query builder con la columna dentro del arreglo a proposito:
     * fecha_actualizacion es el UPDATED_AT del modelo y Eloquent solo la agrega
     * sola cuando no venia ya en los valores.
     */
    private function fijarFechaActualizacion(int $codigo, string $fecha): void
    {
        Repuesto::where('codigo', $codigo)->update(['fecha_actualizacion' => $fecha]);
    }

    private function fechaActualizacionDe(int $codigo): string
    {
        return (string) Repuesto::where('codigo', $codigo)->firstOrFail()
            ->fecha_actualizacion?->format('Y-m-d H:i:s');
    }

    /** El ERP confirmo el repuesto: queda la fecha de la corrida. */
    public function test_la_sincronizacion_escribe_fecha_actual_erp_en_los_que_cambiaron(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        $this->assertNull(Repuesto::where('codigo', $codigo)->value('fecha_actual_ERP'));

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $antesDeCorrer = now()->subSecond();
        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $repuesto = Repuesto::where('codigo', $codigo)->firstOrFail();

        $this->assertSame(250.0, $repuesto->stock);
        $this->assertNotNull($repuesto->fecha_actual_ERP);
        $this->assertTrue($repuesto->fecha_actual_ERP->greaterThanOrEqualTo($antesDeCorrer));
    }

    /**
     * ESTE ES EL CASO QUE DA SENTIDO A LA COLUMNA: un repuesto con existencia
     * estable no cambia de stock, pero el ERP acaba de confirmarlo igual. Si
     * solo se marcaran los que cambian, media bodega apareceria con la fecha de
     * hace meses y se leeria como que el ERP dejo de reportarla.
     */
    public function test_la_sincronizacion_escribe_fecha_actual_erp_en_los_que_ya_estaban_al_dia(): void
    {
        $codigo = $this->codigoDePrueba();
        // Ya trae exactamente el stock que va a reportar el ERP.
        $this->repuesto($codigo, existencia: 7, stock: 250);

        $vieja = now()->subDays(30)->startOfSecond();
        Repuesto::where('codigo', $codigo)->update(['fecha_actual_ERP' => $vieja]);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $resultado = app(SincronizadorStockRepuestos::class)->sincronizar();

        // No cambio de stock: sigue contando como "ya al dia", no como actualizado.
        $this->assertSame(0, $resultado->actualizados);
        $this->assertSame(1, $resultado->sinCambio);

        $repuesto = Repuesto::where('codigo', $codigo)->firstOrFail();

        $this->assertSame(250.0, $repuesto->stock);
        $this->assertTrue($repuesto->fecha_actual_ERP->greaterThan($vieja));
    }

    /**
     * LA PRUEBA DEL PEDIDO: fecha_actualizacion es el UPDATED_AT del modelo y
     * dice cuando alguien edito el repuesto en el panel. La sincronizacion la
     * movia en cada corrida, con lo cual no se podia distinguir una edicion de
     * una pasada del ERP.
     */
    public function test_la_sincronizacion_no_mueve_fecha_actualizacion(): void
    {
        $cambia = $this->codigoDePrueba();
        $alDia = $this->codigoDePrueba();
        $this->repuesto($cambia, existencia: 7, stock: 0);
        $this->repuesto($alDia, existencia: 7, stock: 250);

        $marca = '2026-01-15 08:30:00';
        $this->fijarFechaActualizacion($cambia, $marca);
        $this->fijarFechaActualizacion($alDia, $marca);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $cambia, 'cant_disp' => 250],
                ['item' => (string) $alDia, 'cant_disp' => 250],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        // El que cambio de stock y el que ya estaba al dia: ninguno de los dos
        // caminos de escritura puede tocar fecha_actualizacion.
        $this->assertSame($marca, $this->fechaActualizacionDe($cambia));
        $this->assertSame($marca, $this->fechaActualizacionDe($alDia));

        // Y la fecha del ERP si se movio en los dos.
        $this->assertNotNull(Repuesto::where('codigo', $cambia)->value('fecha_actual_ERP'));
        $this->assertNotNull(Repuesto::where('codigo', $alDia)->value('fecha_actual_ERP'));
    }

    /** Una simulacion no deja rastro en ninguna parte, tampoco en la fecha del ERP. */
    public function test_simular_no_escribe_fecha_actual_erp(): void
    {
        $cambia = $this->codigoDePrueba();
        $alDia = $this->codigoDePrueba();
        $this->repuesto($cambia, existencia: 7, stock: 4);
        $this->repuesto($alDia, existencia: 7, stock: 250);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $cambia, 'cant_disp' => 500],
                ['item' => (string) $alDia, 'cant_disp' => 250],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock', ['--simular' => true])->assertSuccessful();

        $this->assertNull(Repuesto::where('codigo', $cambia)->value('fecha_actual_ERP'));
        $this->assertNull(Repuesto::where('codigo', $alDia)->value('fecha_actual_ERP'));
        $this->assertSame(4.0, Repuesto::where('codigo', $cambia)->value('stock'));
    }

    /**
     * repuestos.stock es decimal(12,3) porque el almacen mide en KG. Truncar a
     * entero perderia los decimales en silencio.
     */
    public function test_una_cantidad_fraccionaria_no_se_trunca(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 12.5],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $this->assertSame(12.5, Repuesto::where('codigo', $codigo)->value('stock'));
    }

    /**
     * Dos cantidades fraccionarias distintas no pueden acabar en el mismo grupo:
     * PHP convierte a entero las claves numericas de un arreglo, asi que agrupar
     * por el float habria escrito la misma existencia en los dos repuestos.
     */
    public function test_dos_fracciones_distintas_no_se_mezclan(): void
    {
        $uno = $this->codigoDePrueba();
        $otro = $this->codigoDePrueba();
        $this->repuesto($uno, existencia: 0, stock: 0);
        $this->repuesto($otro, existencia: 0, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $uno, 'cant_disp' => 12.5],
                ['item' => (string) $otro, 'cant_disp' => 12.9],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $this->assertSame(12.5, Repuesto::where('codigo', $uno)->value('stock'));
        $this->assertSame(12.9, Repuesto::where('codigo', $otro)->value('stock'));
    }

    public function test_un_fallo_de_la_api_termina_con_codigo_distinto_de_cero(): void
    {
        Http::fake([
            '*/api/v1/token' => Http::response(['error' => 'Codigo invalido.'], 401),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertFailed();
    }

    public function test_el_filtro_del_programador_respeta_tiempo_actualizar(): void
    {
        $this->parametro('tiempo.actualizar', '60');
        $sincronizador = app(SincronizadorStockRepuestos::class);

        // Sin marca previa, la primera corrida siempre pasa.
        $this->assertTrue($sincronizador->debeCorrer());

        $sincronizador->marcarCorrida();
        $this->assertFalse($sincronizador->debeCorrer());

        // Con el intervalo cumplido vuelve a pasar.
        Cache::forever(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA, now()->subMinutes(61)->getTimestamp());
        $this->assertTrue($sincronizador->debeCorrer());
    }

    /**
     * En modo manual la tarea programada no se dispara nunca: la sincronizacion
     * sale solo del boton del panel.
     */
    public function test_el_filtro_del_programador_no_deja_pasar_en_modo_manual(): void
    {
        $sincronizador = app(SincronizadorStockRepuestos::class);

        $this->parametro(Parametro::INV_ACTUALIZAR, Parametro::INV_MANUAL);
        $this->assertFalse($sincronizador->debeCorrer());

        // Y con el modo automatico vuelve a pasar, sin marca previa.
        $this->parametro(Parametro::INV_ACTUALIZAR, Parametro::INV_AUTOMATICO);
        $this->assertTrue($sincronizador->debeCorrer());
    }

    /**
     * Si el parametro falta o esta inactivo se mantiene el comportamiento que
     * habia antes de que existiera: automatico. Apagar la sincronizacion en
     * silencio dejaria el stock envejeciendo sin que nadie se entere.
     */
    public function test_sin_el_parametro_el_modo_cae_en_automatico(): void
    {
        $modo = Parametro::where('col_nombre', Parametro::INV_ACTUALIZAR)->firstOrFail();
        $modo->col_estado = Parametro::ESTADO_INACTIVO;
        $modo->save();

        $sincronizador = app(SincronizadorStockRepuestos::class);

        $this->assertSame(Parametro::INV_AUTOMATICO, $sincronizador->modo());
        $this->assertFalse($sincronizador->esManual());
    }

    /**
     * Un valor escrito a mano en la base que no este en la lista cerrada no
     * puede dejar el sistema en un modo inexistente.
     */
    public function test_un_modo_desconocido_cae_en_automatico(): void
    {
        $this->parametro(Parametro::INV_ACTUALIZAR, 'a_ratos');

        $this->assertSame(Parametro::INV_AUTOMATICO, app(SincronizadorStockRepuestos::class)->modo());
    }

    /**
     * El unico punto de mapeo entre la respuesta de la API y repuestos.codigo:
     * el campo es `item`, y viene con trim.
     */
    public function test_el_codigo_se_lee_del_campo_item(): void
    {
        $api = app(InventarioApiSidocsa::class);

        $this->assertSame('3729', $api->extraerCodigo(['item' => '3729']));
        // Puede llegar con espacios alrededor.
        $this->assertSame('3729', $api->extraerCodigo(['item' => '  3729  ']));
        // Ningun otro campo cuenta como codigo.
        $this->assertNull($api->extraerCodigo(['id_item' => '3729', 'codigo' => '3729']));
        $this->assertNull($api->extraerCodigo(['item' => '   ']));
        $this->assertNull($api->extraerCodigo([]));
    }

    /**
     * Un item no numerico no puede pasar por intval y terminar cruzando contra
     * cualquier codigo que tambien de 0.
     */
    public function test_un_item_no_numerico_se_salta(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 7, stock: 0);

        $repuestosAntes = Repuesto::count();

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => 'ABC-123', 'cant_disp' => 500],
                ['item' => '', 'cant_disp' => 400],
                ['item' => (string) $codigo, 'cant_disp' => 6],
            ]]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $this->assertSame(6.0, Repuesto::where('codigo', $codigo)->value('stock'));
        $this->assertSame($repuestosAntes, Repuesto::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Carga inicial de existencia
    |--------------------------------------------------------------------------
    */

    /**
     * Esta via de la carga inicial escribe UNICAMENTE lo que confirma la API:
     * un repuesto con stock alto que el ERP no reporta no recibe saldo. Copiar
     * `stock` es la OTRA via (repuestos:inicializar-existencia-desde-stock, ver
     * InicializarExistenciaDesdeStockTest), con el riesgo de los puntos de
     * reposicion asumido a proposito; aqui ese origen sigue sin usarse.
     */
    public function test_la_inicializacion_solo_escribe_lo_que_reporta_la_api(): void
    {
        $reportado = $this->codigoDePrueba();
        $noReportado = $this->codigoDePrueba();

        $this->repuesto($reportado, existencia: 0, stock: 0);
        // Tiene stock alto pero la API no lo menciona: no puede recibir saldo.
        $this->repuesto($noReportado, existencia: 0, stock: 999);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $reportado, 'cant_disp' => 30],
            ]]),
        ]);

        $this->artisan('repuestos:inicializar-existencia', ['--forzar' => true])->assertSuccessful();

        $this->assertSame(30.0, Repuesto::where('codigo', $reportado)->value('existencia'));
        $this->assertSame(0.0, Repuesto::where('codigo', $noReportado)->value('existencia'));
    }

    /**
     * La segunda corrida no puede volver a escribir: barreria lo ya despachado.
     */
    public function test_la_segunda_corrida_no_toca_existencia(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 30],
            ]]),
        ]);

        $this->artisan('repuestos:inicializar-existencia', ['--forzar' => true])->assertSuccessful();
        $this->assertSame(30.0, Repuesto::where('codigo', $codigo)->value('existencia'));

        // El almacen despacha 25 unidades.
        Repuesto::where('codigo', $codigo)->update(['existencia' => 5]);

        // Segunda corrida: el parametro ya quedo marcado y no se escribe nada.
        $this->artisan('repuestos:inicializar-existencia', ['--forzar' => true])->assertSuccessful();

        $this->assertSame(5.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /**
     * El respaldo del parametro es "ya inicializada", al reves que el de
     * inv.actualizar: si no se puede confirmar que hace falta, no se escribe.
     */
    public function test_sin_el_parametro_la_inicializacion_no_corre(): void
    {
        $marca = Parametro::where('col_nombre', Parametro::INV_EXISTENCIA_INICIALIZADA)->firstOrFail();
        $marca->col_estado = Parametro::ESTADO_INACTIVO;
        $marca->save();

        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 30],
            ]]),
        ]);

        $this->artisan('repuestos:inicializar-existencia', ['--forzar' => true])->assertSuccessful();

        $this->assertSame(0.0, Repuesto::where('codigo', $codigo)->value('existencia'));
        // Ni siquiera se llamo a la API.
        $this->assertSame(0, $this->llamadasA('inventario/consultar'));
    }

    /**
     * Un repuesto que ya tiene saldo operativo nunca se pisa, aunque la carga
     * inicial todavia no se haya marcado.
     */
    public function test_la_inicializacion_no_pisa_un_saldo_vivo(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 5, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 999],
            ]]),
        ]);

        $this->artisan('repuestos:inicializar-existencia', ['--forzar' => true])->assertSuccessful();

        $this->assertSame(5.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    public function test_la_inicializacion_simulada_no_escribe(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => (string) $codigo, 'cant_disp' => 30],
            ]]),
        ]);

        $this->artisan('repuestos:inicializar-existencia', ['--simular' => true])->assertSuccessful();

        $this->assertSame(0.0, Repuesto::where('codigo', $codigo)->value('existencia'));
        // Y la marca sigue sin ponerse: la carga inicial no se ha hecho.
        $this->assertSame(Parametro::INV_NO, Parametro::valor(Parametro::INV_EXISTENCIA_INICIALIZADA));
    }

    /*
    |--------------------------------------------------------------------------
    | Cuerpo de la peticion al endpoint de inventario
    |--------------------------------------------------------------------------
    */

    /**
     * Dispara una sincronizacion contra un endpoint falso y devuelve el cuerpo
     * de la unica peticion de inventario, ya decodificado.
     *
     * @return array<string, mixed>
     */
    private function cuerpoDeLaConsulta(): array
    {
        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => []]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        $peticion = Http::recorded(
            fn ($peticion) => str_contains($peticion->url(), 'inventario/consultar')
        )->first()[0];

        return $peticion->data();
    }

    /**
     * El contrato del endpoint: criterio (SUBGRUPO) y criterio_2 (GRUPO) viajan
     * como arreglos JSON de IDs NUMERICOS, y el administrador los configura como
     * una lista separada por comas.
     */
    public function test_criterio_y_criterio_2_viajan_como_arreglos(): void
    {
        $this->parametro(Parametro::API_CRITERIO_2, '2002,2010,2013,2014');
        $this->parametro(Parametro::API_CRITERIO, '3038,1230');

        $cuerpo = $this->cuerpoDeLaConsulta();

        $this->assertSame([2002, 2010, 2013, 2014], $cuerpo['criterio_2']);
        $this->assertSame([3038, 1230], $cuerpo['criterio']);

        // El resto del cuerpo acordado con el ERP, para que un cambio en los
        // criterios no se lleve por delante lo demas. id_bod e id_cia salen de
        // los parametros que siembra setUp().
        $this->assertSame(InventarioApiSidocsa::TAMANO_PAGINA, $cuerpo['cant']);
        $this->assertSame(1, $cuerpo['page']);
        $this->assertSame('INV1455', $cuerpo['tipo_inv']);
        $this->assertSame('P2ALM', $cuerpo['id_bod']);
        $this->assertSame('1', $cuerpo['id_cia']);
        $this->assertSame(1, $cuerpo['existencias']);

        // cant_page desaparecio del cuerpo: el tamano de pagina lo fija `cant`.
        $this->assertArrayNotHasKey('cant_page', $cuerpo);
    }

    /**
     * cant y page son numeros JSON, no cadenas entrecomilladas; id_cia, id_bod y
     * tipo_inv siguen siendo cadenas. Se comprueba sobre el JSON crudo porque
     * data() no distingue "1000" de 1000 al leerlo de vuelta.
     */
    public function test_cant_y_page_viajan_como_numeros_y_los_ids_como_cadenas(): void
    {
        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => []]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        Http::assertSent(function ($peticion) {
            if (! str_contains($peticion->url(), 'inventario/consultar')) {
                return false;
            }

            $cuerpo = $peticion->body();

            return str_contains($cuerpo, '"cant":'.InventarioApiSidocsa::TAMANO_PAGINA)
                && str_contains($cuerpo, '"page":1')
                && str_contains($cuerpo, '"existencias":1')
                && str_contains($cuerpo, '"id_cia":"1"')
                && str_contains($cuerpo, '"id_bod":"P2ALM"')
                && str_contains($cuerpo, '"tipo_inv":"INV1455"')
                && ! str_contains($cuerpo, 'cant_page');
        });
    }

    /**
     * Un criterio sin configurar viaja igual, como arreglo vacio: el endpoint
     * espera la llave siempre. Se comprueba sobre el JSON crudo porque en PHP
     * un arreglo vacio tambien podria serializarse como objeto ({}).
     */
    public function test_un_criterio_vacio_viaja_como_arreglo_vacio(): void
    {
        $this->parametro(Parametro::API_CRITERIO, '');
        $this->parametro(Parametro::API_CRITERIO_2, '');

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => []]),
        ]);

        $this->artisan('repuestos:sincronizar-stock')->assertSuccessful();

        Http::assertSent(function ($peticion) {
            if (! str_contains($peticion->url(), 'inventario/consultar')) {
                return false;
            }

            return $peticion->data()['criterio'] === []
                && $peticion->data()['criterio_2'] === []
                && str_contains($peticion->body(), '"criterio":[]')
                && str_contains($peticion->body(), '"criterio_2":[]');
        });
    }

    /**
     * Los criterios son IDs, asi que listaEnteros() SI le hace trim a cada
     * elemento (la razon para no hacerlo era el nombre de grupo con espacio
     * significativo, y un ID no lo tiene) y descarta los elementos vacios.
     */
    public function test_la_lista_de_ids_recorta_los_espacios_y_descarta_los_vacios(): void
    {
        $this->parametro(Parametro::API_CRITERIO_2, ' 2002 ,,2010,');

        $this->assertSame(
            [2002, 2010],
            Parametro::listaEnteros(Parametro::API_CRITERIO_2)
        );

        $this->assertSame(
            [2002, 2010],
            $this->cuerpoDeLaConsulta()['criterio_2']
        );
    }

    /**
     * Un elemento que no sea numerico se descarta y NO se cuela como 0: (int)
     * 'ELECTRICO' seria 0, que para la API es un filtro valido y traeria el
     * universo equivocado. Es el caso de una instalacion que venga de la version
     * anterior, donde el valor todavia trae nombres de grupo.
     */
    public function test_un_criterio_no_numerico_se_descarta_y_no_viaja_como_cero(): void
    {
        $this->parametro(Parametro::API_CRITERIO_2, 'ELECTRICO,2002,MATERIAS PRIMAS');

        $this->assertSame(
            [2002],
            Parametro::listaEnteros(Parametro::API_CRITERIO_2)
        );

        $this->assertSame([2002], $this->cuerpoDeLaConsulta()['criterio_2']);
    }

    /**
     * Y si TODOS los elementos son texto, el criterio viaja vacio: la consulta
     * sale sin filtro de grupo, que es exactamente el fallo que el Log::warning
     * de listaEnteros() tiene que dejar visible.
     */
    public function test_un_criterio_todo_texto_queda_vacio(): void
    {
        $this->parametro(Parametro::API_CRITERIO_2, 'ELECTRICO,MATERIAS PRIMAS');

        $this->assertSame([], Parametro::listaEnteros(Parametro::API_CRITERIO_2));
        $this->assertSame([], $this->cuerpoDeLaConsulta()['criterio_2']);
    }

    /**
     * Parametro::listaEnteros() lee por lista(), que lee por valor(): respeta el
     * estado. Un criterio inactivo es un filtro que no se aplica, no un valor
     * que se cuela. El valor es numerico a proposito, para que lo unico que lo
     * deje fuera sea el estado.
     */
    public function test_un_criterio_inactivo_no_viaja(): void
    {
        $criterio = $this->parametro(Parametro::API_CRITERIO_2, '2002');
        $criterio->col_estado = Parametro::ESTADO_INACTIVO;
        $criterio->save();

        $this->assertSame([], $this->cuerpoDeLaConsulta()['criterio_2']);
    }

    private function llamadasA(string $fragmento): int
    {
        return Http::recorded(fn ($peticion) => str_contains($peticion->url(), $fragmento))->count();
    }
}
