<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 */
class RolAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'admin.prueba.'.uniqid().'@sidocsa.com',
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
            'email' => 'almacenista.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function nuevoRol(array $atributos = []): Rol
    {
        return Rol::create(array_merge([
            'col_clave' => 'prueba_'.substr(uniqid(), -8),
            'col_nombre' => 'Rol de prueba',
            'col_descripcion' => 'Creado por las pruebas automaticas.',
            'col_gestiona_catalogo' => false,
            'col_activo' => true,
        ], $atributos));
    }

    public function test_el_admin_crea_un_rol(): void
    {
        $respuesta = $this->actingAs($this->admin())->post(route('admin.roles.store'), [
            'col_clave' => 'supervisor_test',
            'col_nombre' => 'Supervisor',
            'col_descripcion' => 'Revisa el despacho.',
            'col_gestiona_catalogo' => '1',
            'col_activo' => '1',
        ]);

        $respuesta->assertRedirect(route('admin.roles.index'));
        $respuesta->assertSessionHas('exito');

        $rol = Rol::where('col_clave', 'supervisor_test')->first();

        $this->assertNotNull($rol);
        $this->assertTrue($rol->col_gestiona_catalogo);
        $this->assertTrue($rol->col_activo);
        // col_sistema nunca llega del formulario.
        $this->assertFalse($rol->col_sistema);
    }

    public function test_el_almacenista_no_entra_al_modulo_de_roles(): void
    {
        $this->actingAs($this->almacenista())
            ->get(route('admin.roles.index'))
            ->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('admin.login'));
    }

    public function test_la_clave_no_se_repite(): void
    {
        $existente = $this->nuevoRol();

        $this->actingAs($this->admin())
            ->post(route('admin.roles.store'), [
                'col_clave' => $existente->col_clave,
                'col_nombre' => 'Otro nombre',
            ])
            ->assertSessionHasErrors('col_clave');
    }

    public function test_modificar_un_rol_del_sistema_no_cambia_su_clave_ni_su_permiso(): void
    {
        $sistema = Rol::where('col_clave', User::ROL_ALMACENISTA)->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('admin.roles.update', $sistema), [
                'col_clave' => 'clave_nueva',
                'col_nombre' => 'Almacenista renombrado',
                'col_gestiona_catalogo' => '1',
                'col_activo' => '0',
            ])
            ->assertRedirect(route('admin.roles.index'));

        $sistema->refresh();

        $this->assertSame(User::ROL_ALMACENISTA, $sistema->col_clave);
        $this->assertSame('Almacenista renombrado', $sistema->col_nombre);
        $this->assertFalse($sistema->col_gestiona_catalogo);
        $this->assertTrue($sistema->col_activo);
    }

    public function test_no_se_anula_un_rol_del_sistema(): void
    {
        $sistema = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        $this->actingAs($this->admin())
            ->delete(route('admin.roles.destroy', $sistema))
            ->assertSessionHas('error');

        $this->assertTrue($sistema->refresh()->col_activo);
    }

    public function test_no_se_anula_un_rol_con_usuarios_activos(): void
    {
        $rol = $this->nuevoRol();

        User::create([
            'name' => 'Usuario del rol',
            'email' => 'usuario.rol.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.roles.destroy', $rol))
            ->assertSessionHas('error');

        $this->assertTrue($rol->refresh()->col_activo);
    }

    public function test_se_anula_un_rol_sin_usuarios_activos(): void
    {
        $rol = $this->nuevoRol();

        $this->actingAs($this->admin())
            ->delete(route('admin.roles.destroy', $rol))
            ->assertSessionHas('exito');

        $this->assertFalse($rol->refresh()->col_activo);
    }

    public function test_el_permiso_lo_manda_el_rol_asignado(): void
    {
        $rol = $this->nuevoRol(['col_gestiona_catalogo' => true]);

        $usuario = User::create([
            'name' => 'Supervisor de prueba',
            'email' => 'supervisor.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            // El texto historico dice almacenista, pero el rol asignado manda.
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);

        $this->assertTrue($usuario->puedeGestionarCatalogo());

        $this->actingAs($usuario)
            ->get(route('admin.roles.index'))
            ->assertOk();
    }
}
