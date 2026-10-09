<?php

namespace Tests\Feature;

use App\Models\ImagenIndice;
use App\Services\BitacoraCostoClaude;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Comando repuestos:indexar-imagenes. La API se simula con Http::fake(): no se
 * gasta dinero. Trabaja sobre las fotos reales de public/img (solo las lee) y
 * las filas que escribe se revierten con DatabaseTransactions.
 */
class IndexarImagenesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'clave-de-prueba',
            'services.anthropic.modelo' => 'claude-opus-5-5',
            'services.anthropic.fallback_servidor' => false,
            'services.anthropic.imagen.lote_indice' => 8,
            'services.anthropic.imagen.lado_indice' => 256,
        ]);
    }

    public function test_simular_no_llama_a_la_api_ni_escribe(): void
    {
        Http::fake();
        $antes = ImagenIndice::query()->count();

        $this->artisan('repuestos:indexar-imagenes', ['--simular' => true, '--forzar' => true, '--limite' => 3])
            ->expectsOutputToContain('Fotos por indexar: 3')
            ->expectsOutputToContain('Costo estimado: USD')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame($antes, ImagenIndice::query()->count());
    }

    public function test_indexa_por_lotes_guarda_la_descripcion_y_registra_el_costo(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'message',
                'role' => 'assistant',
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['imagenes' => [
                        ['numero' => 1, 'descripcion' => 'Rodamiento de bolas metalico con dos sellos negros.'],
                        ['numero' => 2, 'descripcion' => 'Filtro cilindrico con tapa roscada.'],
                    ]]),
                ]],
                'usage' => ['input_tokens' => 900, 'output_tokens' => 150],
            ]),
        ]);

        $bitacora = app(BitacoraCostoClaude::class)->ruta();
        clearstatcache();
        $desde = is_file($bitacora) ? filesize($bitacora) : 0;

        $this->artisan('repuestos:indexar-imagenes', ['--forzar' => true, '--limite' => 2])
            ->expectsOutputToContain('Fotos indexadas: 2')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $peticion) {
            $imagenes = array_filter($peticion['messages'][0]['content'], fn ($bloque) => ($bloque['type'] ?? null) === 'image');

            return count($imagenes) === 2
                && ($peticion['output_config']['format']['type'] ?? null) === 'json_schema';
        });

        $filas = ImagenIndice::query()->whereIn('col_descripcion', [
            'Rodamiento de bolas metalico con dos sellos negros.',
            'Filtro cilindrico con tapa roscada.',
        ])->get();

        $this->assertCount(2, $filas);
        $this->assertSame(64, strlen($filas->first()->col_hash));
        $this->assertSame('claude-opus-5-5', $filas->first()->col_modelo);

        clearstatcache();
        $linea = (string) file_get_contents($bitacora, false, null, $desde);
        $this->assertStringContainsString('indice_imagenes', $linea);
        $this->assertStringContainsString('imagenes: 2', $linea);
        $this->assertStringContainsString('entrada: 900', $linea);
    }

    public function test_sin_api_key_no_envia_nada(): void
    {
        config(['services.anthropic.key' => '']);
        Http::fake();

        $this->artisan('repuestos:indexar-imagenes', ['--forzar' => true, '--limite' => 1])
            ->expectsOutputToContain('Falta ANTHROPIC_API_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_deduce_el_codigo_del_nombre_del_archivo(): void
    {
        $this->assertSame(3729, ImagenIndice::codigoDeArchivo('0003729.jpg'));
        $this->assertSame(15040, ImagenIndice::codigoDeArchivo('0015040_v2.png'));
        $this->assertNull(ImagenIndice::codigoDeArchivo('activo_fijo_1.png'));
    }
}
