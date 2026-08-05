<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 */
class CategoriaAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'admin.cat.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function almacenista(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ALMACENISTA)->firstOrFail();

        return User::create([
            'name' => 'Almacenista de prueba',
            'email' => 'almacenista.cat.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function nuevaCategoria(array $atributos = []): Categoria
    {
        $categoria = new Categoria(array_merge([
            'col_nombre' => 'Categoria de prueba '.substr(uniqid(), -8),
            'col_color_hex' => '#123456',
            'col_descripcion' => 'Creada por las pruebas automaticas.',
            'col_activo' => true,
        ], $atributos));

        // col_slug no es fillable: lo pone CategoriaService al crear.
        $categoria->col_slug = 'prueba-'.substr(uniqid(), -8);
        $categoria->save();

        return $categoria;
    }

    private function nuevoRepuesto(Categoria $categoria, bool $activo = true): Repuesto
    {
        return Repuesto::create([
            'codigo' => 'TEST'.substr(uniqid(), -10),
            'nombre' => 'Repuesto de prueba',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'UND',
            'cantidad_disponible' => 5,
            'stock_minimo' => 1,
            'activo' => $activo,
        ]);
    }

    /* --- Acceso ---------------------------------------------------------- */

    public function test_el_almacenista_no_entra_al_modulo_de_categorias(): void
    {
        $this->actingAs($this->almacenista())
            ->get(route('admin.categorias.index'))
            ->assertForbidden();
    }

    public function test_el_almacenista_no_crea_categorias(): void
    {
        $this->actingAs($this->almacenista())
            ->post(route('admin.categorias.store'), [
                'col_nombre' => 'Intento no autorizado',
                'col_color_hex' => '#123456',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tbl_categoria', ['col_nombre' => 'Intento no autorizado']);
    }

    public function test_el_almacenista_no_anula_categorias(): void
    {
        $categoria = $this->nuevaCategoria();

        $this->actingAs($this->almacenista())
            ->delete(route('admin.categorias.destroy', $categoria))
            ->assertForbidden();

        $this->assertTrue($categoria->refresh()->col_activo);
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('admin.categorias.index'))->assertRedirect(route('admin.login'));
    }

    public function test_el_admin_ve_el_listado(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.categorias.index'))
            ->assertOk();
    }

    public function test_el_admin_ve_los_formularios_de_alta_y_edicion(): void
    {
        $categoria = $this->nuevaCategoria();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.categorias.create'))->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.categorias.edit', $categoria))
            ->assertOk()
            ->assertSee($categoria->col_slug);
    }

    /* --- Alta y edicion -------------------------------------------------- */

    public function test_el_admin_crea_una_categoria_y_el_slug_sale_del_nombre(): void
    {
        $nombre = 'Filtros de aire '.substr(uniqid(), -6);

        $respuesta = $this->actingAs($this->admin())->post(route('admin.categorias.store'), [
            'col_nombre' => $nombre,
            'col_color_hex' => '#d81818',
            'col_descripcion' => 'Marco rojo.',
            'col_activo' => '1',
        ]);

        $respuesta->assertRedirect(route('admin.categorias.index'));
        $respuesta->assertSessionHas('exito');

        $categoria = Categoria::where('col_nombre', $nombre)->first();

        $this->assertNotNull($categoria);
        $this->assertSame(Str::slug($nombre), $categoria->col_slug);
        // El hex se normaliza a mayusculas para que no haya dos formas del mismo color.
        $this->assertSame('#D81818', $categoria->col_color_hex);
        $this->assertTrue($categoria->col_activo);
    }

    public function test_el_color_debe_ser_hexadecimal(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categorias.store'), [
                'col_nombre' => 'Color invalido '.substr(uniqid(), -6),
                'col_color_hex' => 'rojo',
            ])
            ->assertSessionHasErrors('col_color_hex');
    }

    public function test_el_nombre_no_se_repite(): void
    {
        $existente = $this->nuevaCategoria();

        $this->actingAs($this->admin())
            ->post(route('admin.categorias.store'), [
                'col_nombre' => $existente->col_nombre,
                'col_color_hex' => '#123456',
            ])
            ->assertSessionHasErrors('col_nombre');
    }

    public function test_renombrar_no_cambia_el_slug(): void
    {
        $categoria = $this->nuevaCategoria();
        $slugOriginal = $categoria->col_slug;

        $this->actingAs($this->admin())
            ->put(route('admin.categorias.update', $categoria), [
                'col_nombre' => 'Nombre nuevo '.substr(uniqid(), -6),
                'col_color_hex' => '#00A848',
                'col_activo' => '1',
            ])
            ->assertRedirect(route('admin.categorias.index'));

        $categoria->refresh();

        // El slug amarra la deteccion por color con el registro: es inmutable.
        $this->assertSame($slugOriginal, $categoria->col_slug);
        $this->assertSame('#00A848', $categoria->col_color_hex);
    }

    /* --- Anular ---------------------------------------------------------- */

    public function test_no_se_anula_una_categoria_con_repuestos_activos(): void
    {
        $categoria = $this->nuevaCategoria();
        $this->nuevoRepuesto($categoria);

        $this->actingAs($this->admin())
            ->delete(route('admin.categorias.destroy', $categoria))
            ->assertSessionHas('error');

        $this->assertTrue($categoria->refresh()->col_activo);
    }

    public function test_se_anula_una_categoria_con_repuestos_solo_inactivos(): void
    {
        $categoria = $this->nuevaCategoria();
        $this->nuevoRepuesto($categoria, activo: false);

        $this->actingAs($this->admin())
            ->delete(route('admin.categorias.destroy', $categoria))
            ->assertSessionHas('exito');

        $this->assertFalse($categoria->refresh()->col_activo);
    }

    public function test_se_anula_una_categoria_sin_repuestos(): void
    {
        $categoria = $this->nuevaCategoria();

        $this->actingAs($this->admin())
            ->delete(route('admin.categorias.destroy', $categoria))
            ->assertSessionHas('exito');

        $this->assertFalse($categoria->refresh()->col_activo);
        // Anular no borra: los repuestos historicos apuntan al registro.
        $this->assertDatabaseHas('tbl_categoria', ['id' => $categoria->id]);
    }

    /* --- Catalogo publico ------------------------------------------------ */

    public function test_el_catalogo_publico_filtra_por_categoria(): void
    {
        $categoria = $this->nuevaCategoria();
        $delFiltro = $this->nuevoRepuesto($categoria);
        $otro = Repuesto::create([
            'codigo' => 'TEST'.substr(uniqid(), -10),
            'nombre' => 'Repuesto de otra categoria',
            'unidad_medida' => 'UND',
            'cantidad_disponible' => 5,
            'stock_minimo' => 1,
            'activo' => true,
        ]);

        $this->get(route('catalogo.index', ['categoria_id' => $categoria->id]))
            ->assertOk()
            ->assertSee($delFiltro->codigo)
            ->assertDontSee($otro->codigo);
    }

    public function test_el_detalle_publico_muestra_la_categoria(): void
    {
        $categoria = $this->nuevaCategoria(['col_color_hex' => '#00A848']);
        $repuesto = $this->nuevoRepuesto($categoria);

        $this->get(route('catalogo.show', $repuesto))
            ->assertOk()
            ->assertSee($categoria->col_nombre)
            // El hex va en linea porque es dato de la categoria, no de la paleta.
            ->assertSee('#00A848', escape: false);
    }

    public function test_la_relacion_del_repuesto_no_la_opaca_la_columna_de_texto(): void
    {
        $categoria = $this->nuevaCategoria();
        $repuesto = $this->nuevoRepuesto($categoria);
        $repuesto->update(['categoria' => 'Texto historico']);

        $repuesto->refresh();

        // categoria sigue siendo el texto; el modelo llega por categoriaAsignada.
        $this->assertSame('Texto historico', $repuesto->categoria);
        $this->assertInstanceOf(Categoria::class, $repuesto->categoriaAsignada);
        $this->assertSame($categoria->id, $repuesto->categoriaAsignada->id);
    }
}
