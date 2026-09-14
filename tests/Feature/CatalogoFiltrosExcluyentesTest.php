<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Repuesto;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * En el catalogo publico "Categoria" (categoria_id -> id_categoria) y "Tipo de
 * repuesto" (categoria -> desc_cat_1) son excluyentes: mientras uno tiene valor
 * el otro se pinta deshabilitado. Si llegan los dos por URL manda la categoria.
 *
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 */
class CatalogoFiltrosExcluyentesTest extends TestCase
{
    use DatabaseTransactions;

    private function nuevaCategoria(): Categoria
    {
        $categoria = new Categoria([
            'col_nombre' => 'Categoria de prueba '.substr(uniqid(), -8),
            'col_color_hex' => '#123456',
            'col_descripcion' => 'Creada por las pruebas automaticas.',
            'col_activo' => true,
        ]);

        // col_slug no es fillable: lo pone CategoriaService al crear.
        $categoria->col_slug = 'prueba-'.substr(uniqid(), -8);
        $categoria->save();

        return $categoria;
    }

    /**
     * Codigo de 9 digitos: repuestos.codigo es entero con indice unico desde que
     * la tabla la trae el ERP, y el catalogo real no llega a ese rango.
     */
    private function nuevoRepuesto(?Categoria $categoria, ?string $grupo): Repuesto
    {
        return Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba',
            'id_categoria' => $categoria?->id,
            'desc_cat_1' => $grupo,
            'unidad_medida' => 'UND',
            'existencia' => 5,
            'stock_minimo' => 1,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    private function grupoDePrueba(): string
    {
        return 'GRUPO PRUEBA '.strtoupper(substr(uniqid(), -8));
    }

    /** Etiqueta de apertura del select con ese id, tal como sale en el HTML. */
    private function etiquetaSelect(TestResponse $respuesta, string $id): string
    {
        $encontrado = preg_match('/<select\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>/s', $respuesta->getContent(), $coincidencia);

        $this->assertSame(1, $encontrado, "No se encontro el select #{$id} en el catalogo.");

        return $coincidencia[0];
    }

    private function assertDeshabilitado(TestResponse $respuesta, string $id): void
    {
        $this->assertMatchesRegularExpression('/\sdisabled\b/', $this->etiquetaSelect($respuesta, $id), "#{$id} deberia estar deshabilitado.");
    }

    private function assertHabilitado(TestResponse $respuesta, string $id): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sdisabled\b/', $this->etiquetaSelect($respuesta, $id), "#{$id} deberia estar habilitado.");
    }

    /* --- Render inicial -------------------------------------------------- */

    public function test_sin_filtros_los_dos_campos_estan_habilitados(): void
    {
        $respuesta = $this->get(route('catalogo.index'))->assertOk();

        $this->assertHabilitado($respuesta, 'categoria_id');
        $this->assertHabilitado($respuesta, 'categoria');

        // Las ayudas del bloqueo existen pero van ocultas.
        $this->assertMatchesRegularExpression('/id="categoria_id_ayuda"[^>]*\shidden/', $respuesta->getContent());
        $this->assertMatchesRegularExpression('/id="categoria_ayuda"[^>]*\shidden/', $respuesta->getContent());
    }

    public function test_con_categoria_el_tipo_de_repuesto_se_pinta_deshabilitado(): void
    {
        $categoria = $this->nuevaCategoria();
        $this->nuevoRepuesto($categoria, null);

        $respuesta = $this->get(route('catalogo.index', ['categoria_id' => $categoria->id]))->assertOk();

        $this->assertHabilitado($respuesta, 'categoria_id');
        $this->assertDeshabilitado($respuesta, 'categoria');
        $this->assertDoesNotMatchRegularExpression('/id="categoria_ayuda"[^>]*\shidden/', $respuesta->getContent());
    }

    public function test_con_tipo_de_repuesto_la_categoria_se_pinta_deshabilitada(): void
    {
        $grupo = $this->grupoDePrueba();
        $this->nuevoRepuesto(null, $grupo);

        $respuesta = $this->get(route('catalogo.index', ['categoria' => $grupo]))->assertOk();

        $this->assertDeshabilitado($respuesta, 'categoria_id');
        $this->assertHabilitado($respuesta, 'categoria');
        $this->assertDoesNotMatchRegularExpression('/id="categoria_id_ayuda"[^>]*\shidden/', $respuesta->getContent());
    }

    /* --- Cada filtro por separado sigue igual ----------------------------- */

    public function test_el_tipo_de_repuesto_solo_sigue_filtrando_por_grupo(): void
    {
        $grupo = $this->grupoDePrueba();
        $delGrupo = $this->nuevoRepuesto(null, $grupo);
        $otro = $this->nuevoRepuesto(null, $this->grupoDePrueba());

        $this->get(route('catalogo.index', ['categoria' => $grupo]))
            ->assertOk()
            ->assertSee($delGrupo->codigo)
            ->assertDontSee($otro->codigo);
    }

    public function test_una_categoria_invalida_no_anula_el_tipo_de_repuesto(): void
    {
        $grupo = $this->grupoDePrueba();
        $delGrupo = $this->nuevoRepuesto(null, $grupo);
        $otro = $this->nuevoRepuesto(null, $this->grupoDePrueba());

        // categoria_id vacio o no numerico no es un filtro: el tipo sigue mandando.
        $respuesta = $this->get(route('catalogo.index', ['categoria_id' => 'abc', 'categoria' => $grupo]))
            ->assertOk()
            ->assertSee($delGrupo->codigo)
            ->assertDontSee($otro->codigo);

        $this->assertDeshabilitado($respuesta, 'categoria_id');
        $this->assertHabilitado($respuesta, 'categoria');
    }

    /* --- Los dos parametros a la vez (URL manipulada) -------------------- */

    public function test_con_los_dos_parametros_manda_la_categoria_e_ignora_el_tipo(): void
    {
        $categoria = $this->nuevaCategoria();
        $grupo = $this->grupoDePrueba();

        // De la categoria pero de OTRO grupo: si el tipo se aplicara, no saldria.
        $deLaCategoria = $this->nuevoRepuesto($categoria, $this->grupoDePrueba());
        // Del grupo pero sin la categoria: si el tipo mandara, saldria.
        $delGrupo = $this->nuevoRepuesto(null, $grupo);

        $respuesta = $this->get(route('catalogo.index', [
            'categoria_id' => $categoria->id,
            'categoria' => $grupo,
        ]))->assertOk();

        $respuesta->assertSee($deLaCategoria->codigo)
            ->assertDontSee($delGrupo->codigo);

        $this->assertHabilitado($respuesta, 'categoria_id');
        $this->assertDeshabilitado($respuesta, 'categoria');

        // El tipo ignorado no queda seleccionado en el select.
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="'.preg_quote($grupo, '/').'"\s+selected/',
            $respuesta->getContent()
        );

        $this->assertSame('', $respuesta->viewData('tipoActivo'));
    }

    public function test_con_los_dos_parametros_la_paginacion_no_arrastra_el_tipo(): void
    {
        $categoria = $this->nuevaCategoria();
        $grupo = $this->grupoDePrueba();
        $this->nuevoRepuesto($categoria, null);

        $respuesta = $this->get(route('catalogo.index', [
            'q' => 'prueba',
            'categoria_id' => $categoria->id,
            'categoria' => $grupo,
            'disponibles' => 1,
        ]))->assertOk();

        $enlace = $respuesta->viewData('repuestos')->url(2);

        $this->assertStringContainsString('categoria_id='.$categoria->id, $enlace);
        $this->assertStringContainsString('q=prueba', $enlace);
        $this->assertStringContainsString('disponibles=1', $enlace);
        $this->assertStringNotContainsString('categoria=', str_replace('categoria_id=', '', $enlace));
    }

    public function test_con_un_solo_parametro_la_paginacion_conserva_la_consulta(): void
    {
        $grupo = $this->grupoDePrueba();
        $this->nuevoRepuesto(null, $grupo);

        $respuesta = $this->get(route('catalogo.index', ['categoria' => $grupo, 'q' => 'prueba']))->assertOk();

        $enlace = $respuesta->viewData('repuestos')->url(2);

        $this->assertStringContainsString('categoria='.rawurlencode($grupo), $enlace);
        $this->assertStringContainsString('q=prueba', $enlace);
    }
}
