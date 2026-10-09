<?php

namespace Tests\Feature;

use App\Models\ImagenIndice;
use App\Models\Repuesto;
use App\Services\BitacoraCostoClaude;
use App\Services\CalculadoraCostoClaude;
use App\Services\UsoClaude;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Busqueda de repuestos por imagen con la API de Claude (POST
 * catalogo/buscar-por-imagen) y su bitacora de costos.
 *
 * La API se simula con Http::fake(): ninguna prueba gasta dinero. Corren contra
 * la base de .env, por eso DatabaseTransactions y no RefreshDatabase.
 *
 * Las fotos del catalogo que necesita el paso de verificacion se crean como
 * archivos temporales en public/img y se borran al terminar.
 */
class BusquedaImagenTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $archivosTemporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'clave-de-prueba',
            'services.anthropic.modelo' => 'claude-opus-5-5',
            'services.anthropic.fallback_servidor' => false,
            'services.anthropic.imagen.umbral' => 80,
            'services.anthropic.imagen.verificar' => true,
            'services.anthropic.imagen.por_minuto' => 100,
            'services.anthropic.imagen.por_dia' => 1000,
            'services.anthropic.precios' => [
                'entrada' => 4.0,
                'salida' => 20.0,
                'cache_escritura' => 5.0,
                'cache_lectura' => 0.2,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivosTemporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /**
     * Repuesto activo con una foto propia en public/img y su fila en el indice.
     *
     * @return array{0: Repuesto, 1: ImagenIndice}
     */
    private function repuestoIndexado(string $descripcion): array
    {
        $nombre = 'zzprueba_'.uniqid().'.png';
        $ruta = public_path('img').DIRECTORY_SEPARATOR.$nombre;
        $imagen = imagecreatetruecolor(120, 90);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 200, 30, 30));
        imagepng($imagen, $ruta);
        imagedestroy($imagen);
        $this->archivosTemporales[] = $ruta;

        $repuesto = Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba '.$descripcion,
            'unidad_medida' => 'UND',
            'existencia' => 5,
            'stock_minimo' => 1,
            'foto' => $nombre,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);

        $indice = ImagenIndice::create([
            'col_archivo' => $nombre,
            'col_hash' => str_repeat('a', 64),
            'col_codigo' => null,
            'col_descripcion' => $descripcion,
            'col_modelo' => 'claude-opus-5-5',
            'col_indexado_at' => now(),
        ])->refresh();

        return [$repuesto, $indice];
    }

    /**
     * Respuesta de la API tal como llega: un bloque thinking vacio y el texto
     * con el JSON pedido.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function respuestaClaude(array $datos, string $texto = ''): array
    {
        return [
            'id' => 'msg_prueba',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'firma'],
                ['type' => 'text', 'text' => $texto !== '' ? $texto : json_encode($datos)],
            ],
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 200,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 5000,
            ],
        ];
    }

    private function bitacora(): string
    {
        return app(BitacoraCostoClaude::class)->ruta();
    }

    private function tamanoBitacora(): int
    {
        clearstatcache();

        return is_file($this->bitacora()) ? filesize($this->bitacora()) : 0;
    }

    /** Lo que se anexo a la bitacora desde $desde. */
    private function lineasNuevas(int $desde): string
    {
        clearstatcache();

        return (string) file_get_contents($this->bitacora(), false, null, $desde);
    }

    private function buscar(?UploadedFile $archivo = null)
    {
        return $this->postJson(route('catalogo.buscar-imagen'), [
            'imagen' => $archivo ?? UploadedFile::fake()->image('foto.jpg', 640, 480),
        ]);
    }

    public function test_el_catalogo_muestra_los_botones_de_busqueda_por_imagen(): void
    {
        $respuesta = $this->get(route('catalogo.index'));

        $respuesta->assertOk()
            ->assertSee('data-busqueda-imagen', false)
            ->assertSee(route('catalogo.buscar-imagen'), false)
            ->assertSee('data-abrir-imagen="camara"', false)
            ->assertSee('data-abrir-imagen="archivo"', false)
            ->assertSee('capture="environment"', false)
            ->assertSee('data-resultados-catalogo', false);
    }

    public function test_exige_una_imagen(): void
    {
        Http::fake();

        $this->postJson(route('catalogo.buscar-imagen'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        Http::assertNothingSent();
    }

    public function test_rechaza_un_archivo_que_no_es_jpg_ni_png(): void
    {
        Http::fake();

        $this->buscar(UploadedFile::fake()->create('documento.pdf', 20, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        $this->buscar(UploadedFile::fake()->image('animada.gif'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagen');

        Http::assertNothingSent();
    }

    public function test_sin_api_key_responde_no_disponible_sin_llamar_a_la_api(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();

        $this->buscar()
            ->assertStatus(503)
            ->assertJson(['ok' => false])
            ->assertJsonPath('mensaje', 'La busqueda por imagen no esta configurada en este servidor.');

        Http::assertNothingSent();
    }

    public function test_sin_indice_responde_no_disponible(): void
    {
        ImagenIndice::query()->delete();
        Http::fake();

        $this->buscar()
            ->assertStatus(503)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }

    public function test_solo_muestra_los_repuestos_que_alcanzan_el_umbral(): void
    {
        [$parecido, $indiceParecido] = $this->repuestoIndexado('rodamiento de bolas metalico');
        [$distinto, $indiceDistinto] = $this->repuestoIndexado('filtro cilindrico amarillo');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respuestaClaude(['candidatos' => [
                    ['id' => $indiceParecido->id, 'porcentaje' => 85],
                    ['id' => $indiceDistinto->id, 'porcentaje' => 70],
                    ['id' => 999999999, 'porcentaje' => 99], // id inexistente: se ignora
                ]]))
                ->push($this->respuestaClaude(['coincidencias' => [
                    ['candidata' => 1, 'porcentaje' => 92],
                    ['candidata' => 2, 'porcentaje' => 60],
                ]])),
        ]);

        $desde = $this->tamanoBitacora();

        $respuesta = $this->buscar()->assertOk()
            ->assertJson(['ok' => true, 'total' => 1, 'umbral' => 80]);

        $html = $respuesta->json('html');
        $this->assertStringContainsString((string) $parecido->codigo, $html);
        $this->assertStringContainsString('92% coincidencia', $html);
        $this->assertStringNotContainsString((string) $distinto->codigo, $html);
        // La tarjeta conserva el boton de agregar al pedido.
        $this->assertStringContainsString('data-agregar-carrito', $html);
        $this->assertStringContainsString(route('carrito.store', $parecido), $html);

        Http::assertSentCount(2);

        // Paso 1: cabeceras, salida JSON con esquema y el indice en cache.
        Http::assertSent(function (Request $peticion) use ($indiceParecido) {
            $sistema = $peticion['system'] ?? [];

            return $peticion->hasHeader('x-api-key', 'clave-de-prueba')
                && $peticion->hasHeader('anthropic-version', '2023-06-01')
                && $peticion['model'] === 'claude-opus-5-5'
                && ($peticion['output_config']['format']['type'] ?? null) === 'json_schema'
                && ($peticion['output_config']['effort'] ?? null) === 'low'
                && ! isset($peticion['fallbacks'])
                && (end($sistema)['cache_control']['type'] ?? null) === 'ephemeral'
                && str_contains(end($sistema)['text'] ?? '', $indiceParecido->id.' | ');
        });

        // Paso 2: la foto del usuario mas las dos candidatas.
        Http::assertSent(function (Request $peticion) {
            $imagenes = array_filter($peticion['messages'][0]['content'] ?? [], fn ($bloque) => ($bloque['type'] ?? null) === 'image');

            return count($imagenes) === 3;
        });

        $linea = $this->lineasNuevas($desde);
        $this->assertStringContainsString('busqueda_imagen', $linea);
        $this->assertStringContainsString('llamadas: 2', $linea);
        $this->assertStringContainsString('entrada: 2000', $linea);
        $this->assertStringContainsString('cache lectura: 10000', $linea);
        // 2000*4 + 400*20 + 10000*0.2 = 18000 / 1e6
        $this->assertStringContainsString('costo USD: 0.018000', $linea);
        $this->assertStringContainsString('coincidencias: 1', $linea);
        $this->assertStringContainsString('estado: ok', $linea);
        $this->assertStringNotContainsString('clave-de-prueba', $linea);
    }

    public function test_sin_coincidencias_lo_dice_y_no_hace_el_segundo_paso(): void
    {
        $this->repuestoIndexado('valvula de bronce');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuestaClaude(['candidatos' => []])),
        ]);

        $respuesta = $this->buscar()->assertOk()->assertJson(['ok' => true, 'total' => 0]);

        $this->assertStringContainsString('Ningun repuesto coincide', $respuesta->json('html'));
        $this->assertStringContainsString('80%', $respuesta->json('mensaje'));
        Http::assertSentCount(1);
    }

    public function test_un_error_de_la_api_se_informa_y_queda_en_la_bitacora(): void
    {
        $this->repuestoIndexado('correa dentada');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'algo esta mal'],
            ], 400),
        ]);

        $desde = $this->tamanoBitacora();

        $this->buscar()
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', 'El servicio de reconocimiento rechazo la consulta (HTTP 400).');

        $linea = $this->lineasNuevas($desde);
        $this->assertStringContainsString('busqueda_imagen', $linea);
        $this->assertStringContainsString('estado: error', $linea);
    }

    public function test_un_json_invalido_se_informa_y_registra_los_tokens_cobrados(): void
    {
        $this->repuestoIndexado('sensor inductivo');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuestaClaude([], 'esto no es json')),
        ]);

        $desde = $this->tamanoBitacora();

        $this->buscar()
            ->assertStatus(503)
            ->assertJsonPath('ok', false);

        $linea = $this->lineasNuevas($desde);
        $this->assertStringContainsString('llamadas: 1', $linea);
        $this->assertStringContainsString('entrada: 1000', $linea);
        $this->assertStringContainsString('estado: error', $linea);
    }

    public function test_envia_fallbacks_solo_si_esta_habilitado_y_el_modelo_lo_admite(): void
    {
        [, $indice] = $this->repuestoIndexado('tornillo hexagonal');
        config(['services.anthropic.fallback_servidor' => true, 'services.anthropic.imagen.verificar' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuestaClaude(['candidatos' => [
                ['id' => $indice->id, 'porcentaje' => 90],
            ]])),
        ]);

        $this->buscar()->assertOk()->assertJson(['total' => 1]);

        Http::assertSent(fn (Request $peticion) => ($peticion['fallbacks'] ?? null) === 'default'
            && $peticion->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));

        // Con un modelo que no lo admite no se envia aunque el .env lo pida.
        config(['services.anthropic.modelo' => 'claude-haiku-4-5', 'services.anthropic.esfuerzo' => '']);
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuestaClaude(['candidatos' => []])),
        ]);

        $this->buscar()->assertOk();

        Http::assertSent(fn (Request $peticion) => $peticion['model'] === 'claude-haiku-4-5'
            && ! isset($peticion['fallbacks'])
            && ! isset($peticion['output_config']['effort'])
            && ! $peticion->hasHeader('anthropic-beta'));
    }

    public function test_el_limitador_frena_el_exceso_de_consultas(): void
    {
        config(['services.anthropic.imagen.por_minuto' => 2, 'services.anthropic.key' => '']);
        Http::fake();

        $this->buscar()->assertStatus(503);
        $this->buscar()->assertStatus(503);
        $this->buscar()
            ->assertStatus(429)
            ->assertJsonPath('ok', false);
    }

    public function test_la_calculadora_aplica_las_cuatro_tarifas(): void
    {
        $costo = app(CalculadoraCostoClaude::class)->costo(new UsoClaude(
            entrada: 1_000_000,
            salida: 100_000,
            cacheEscritura: 200_000,
            cacheLectura: 1_000_000,
            llamadas: 1,
        ));

        // 4 + 2 + 1 + 0.2
        $this->assertSame(7.2, $costo);
    }

    public function test_la_bitacora_de_pruebas_es_un_archivo_aparte(): void
    {
        $this->assertStringEndsWith('claude-costos-pruebas.txt', $this->bitacora());
    }
}
