<?php

namespace Tests\Feature;

use App\Models\Funcionalidad;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\FuncionalidadSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menu lateral del panel en grupos plegables (V 1.4.42, admin/partials/menu).
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions.
 */
class MenuPanelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FuncionalidadSeeder::class);
    }

    private function usuario(Rol $rol): User
    {
        return User::create([
            'name' => 'Usuario de prueba',
            'email' => 'menu.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => $rol->col_clave,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->usuario(Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail());
    }

    /**
     * Perfil operativo con exactamente estas funcionalidades en "ver".
     *
     * @param  list<string>  $claves
     */
    private function perfilQueVe(array $claves): User
    {
        $rol = Rol::create([
            'col_clave' => 'menu_'.substr(uniqid(), -8),
            'col_nombre' => 'Perfil de prueba',
            'col_gestiona_catalogo' => false,
            'col_activo' => true,
        ]);

        $permisos = [];
        foreach ($claves as $clave) {
            $id = (int) DB::selectOne('select id from tbl_funcionalidad where col_clave = ?', [$clave])->id;
            $permisos[$id]['ver'] = '1';
        }

        $this->actingAs($this->admin())
            ->put(route('admin.permisos.update', $rol), ['permisos' => $permisos])
            ->assertRedirect();

        return $this->usuario($rol);
    }

    private function grupoAbierto(string $html, string $id): bool
    {
        return (bool) preg_match('/<div class="collapse show" id="'.preg_quote($id, '/').'">/', $html);
    }

    public function test_el_administrador_ve_los_cuatro_grupos_en_las_dos_instancias_del_menu(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.repuestos.index'))
            ->assertOk()
            ->assertSee('Solicitudes')
            ->assertSee('Configuracion')
            ->assertSee('Ordenes de salida')
            ->assertSee('Registro de entradas')
            ->getContent();

        // Ids distintos en el lateral y en el offcanvas: repetidos, una
        // flecha abriria el grupo de la otra instancia.
        foreach (['solicitudes', 'configuracion', 'ordenes-salida', 'registro-entradas'] as $grupo) {
            $this->assertStringContainsString('id="lateral-grupo-'.$grupo.'"', $html);
            $this->assertStringContainsString('id="movil-grupo-'.$grupo.'"', $html);
        }
    }

    public function test_arranca_abierto_solo_el_grupo_de_la_pantalla_actual(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->getContent();

        $this->assertTrue($this->grupoAbierto($html, 'lateral-grupo-configuracion'));
        $this->assertTrue($this->grupoAbierto($html, 'movil-grupo-configuracion'));
        $this->assertFalse($this->grupoAbierto($html, 'lateral-grupo-solicitudes'));
        $this->assertFalse($this->grupoAbierto($html, 'lateral-grupo-ordenes-salida'));
        $this->assertMatchesRegularExpression(
            '/aria-controls="lateral-grupo-configuracion"\s+aria-expanded="true"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/aria-controls="lateral-grupo-solicitudes"\s+aria-expanded="false"/',
            $html
        );
        // El enlace activo conserva su marca.
        $this->assertMatchesRegularExpression(
            '/class="nav-link active"\s+href="'.preg_quote(route('admin.parametros.index'), '/').'"/',
            $html
        );
    }

    public function test_el_encabezado_solicitudes_lleva_a_la_bandeja_y_la_flecha_pliega(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.solicitudes.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a class="menu-grupo-titulo active"\s+href="'.preg_quote(route('admin.solicitudes.index'), '/').'"\s+aria-current="page"/',
            $html
        );
        $this->assertStringContainsString('class="menu-grupo-plegar ', $html);
        $this->assertTrue($this->grupoAbierto($html, 'lateral-grupo-solicitudes'));
    }

    public function test_los_modulos_pendientes_no_son_navegables(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.solicitudes.index'))
            ->assertOk()
            ->assertSee('Proximamente')
            ->getContent();

        preg_match_all('/<a [^>]*menu-entrada-pendiente[^>]*>/', $html, $pendientes);

        // Listados, Reportes, Registro y Reportes, en cada instancia del menu.
        $this->assertCount(8, $pendientes[0]);

        foreach ($pendientes[0] as $etiqueta) {
            $this->assertStringNotContainsString('href=', $etiqueta);
            $this->assertStringContainsString('aria-disabled="true"', $etiqueta);
        }
    }

    public function test_un_grupo_sin_entradas_visibles_no_se_pinta(): void
    {
        $usuario = $this->perfilQueVe([Funcionalidad::SOLICITUDES]);

        $this->actingAs($usuario)
            ->get(route('admin.solicitudes.index'))
            ->assertOk()
            ->assertDontSee('lateral-grupo-configuracion')
            ->assertDontSee('href="'.route('admin.roles.index').'"', false)
            ->assertSee('href="'.route('admin.solicitudes.listos').'"', false);
    }

    public function test_sin_ver_solicitudes_el_grupo_queda_solo_plegable(): void
    {
        $usuario = $this->perfilQueVe([Funcionalidad::REPUESTOS]);

        $html = $this->actingAs($usuario)
            ->get(route('admin.repuestos.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.solicitudes.listos').'"', false)
            ->getContent();

        // El encabezado deja de ser enlace a la bandeja y pasa a boton.
        $this->assertDoesNotMatchRegularExpression('/<a class="menu-grupo-titulo/', $html);
        $this->assertTrue($this->grupoAbierto($html, 'lateral-grupo-solicitudes'));
        $this->assertStringContainsString('href="'.route('admin.repuestos.index').'"', $html);
    }
}
