<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Funcionalidad;
use App\Models\Rol;
use App\Models\Solicitud;
use App\Models\User;
use Database\Seeders\FuncionalidadSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Modulo Funciones por perfil y su aplicacion en las rutas del panel.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase. El seeder se corre dentro de la transaccion para que las
 * pruebas no dependan de que alguien lo haya corrido antes.
 */
class PermisoAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FuncionalidadSeeder::class);
    }

    private function usuario(Rol $rol, ?string $rolTexto = null): User
    {
        return User::create([
            'name' => 'Usuario de prueba',
            'email' => 'permiso.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => $rolTexto ?? $rol->col_clave,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function rolDelSistema(string $clave): Rol
    {
        return Rol::where('col_clave', $clave)->firstOrFail();
    }

    private function admin(): User
    {
        return $this->usuario($this->rolDelSistema(User::ROL_ADMIN));
    }

    private function almacenista(): User
    {
        return $this->usuario($this->rolDelSistema(User::ROL_ALMACENISTA));
    }

    /**
     * Rol nuevo del panel. La clave importa desde que el modulo de permisos
     * quedo reservado a Funcionalidad::ROLES_ADMINISTRADORES: por defecto se
     * genera una clave que NO esta en esa lista.
     */
    private function nuevoRol(bool $gestionaCatalogo = false, ?string $clave = null): Rol
    {
        return Rol::create([
            'col_clave' => $clave ?? 'perm_'.substr(uniqid(), -8),
            'col_nombre' => 'Perfil de prueba',
            'col_gestiona_catalogo' => $gestionaCatalogo,
            'col_activo' => true,
        ]);
    }

    private function funcionalidadId(string $clave): int
    {
        return (int) DB::selectOne('select id from tbl_funcionalidad where col_clave = ?', [$clave])->id;
    }

    /**
     * @return array{ver: bool, editar: bool, eliminar: bool}|null
     */
    private function filaGuardada(Rol $rol, string $clave): ?array
    {
        $fila = DB::selectOne(
            'select rf.col_ver, rf.col_editar, rf.col_eliminar
               from tbl_rol_funcionalidad rf
              inner join tbl_funcionalidad f on f.id = rf.col_funcionalidad_id
              where rf.col_rol_id = ? and f.col_clave = ?',
            [$rol->id, $clave]
        );

        return $fila ? [
            'ver' => (bool) (int) $fila->col_ver,
            'editar' => (bool) (int) $fila->col_editar,
            'eliminar' => (bool) (int) $fila->col_eliminar,
        ] : null;
    }

    /**
     * Guarda la matriz de un rol como lo haria el admin desde la pantalla.
     *
     * @param  array<string, list<string>>  $marcas  clave de funcionalidad => acciones marcadas
     */
    private function guardarMatriz(Rol $rol, array $marcas): void
    {
        $permisos = [];

        foreach ($marcas as $clave => $acciones) {
            foreach ($acciones as $accion) {
                $permisos[$this->funcionalidadId($clave)][$accion] = '1';
            }
        }

        $this->actingAs($this->admin())
            ->put(route('admin.permisos.update', $rol), ['permisos' => $permisos])
            ->assertRedirect(route('admin.permisos.index', ['rol_id' => $rol->id]))
            ->assertSessionHas('exito');
    }

    public function test_el_admin_ve_la_matriz_del_perfil_elegido(): void
    {
        $rol = $this->nuevoRol();

        $this->actingAs($this->admin())
            ->get(route('admin.permisos.index', ['rol_id' => $rol->id]))
            ->assertOk()
            ->assertSee('Funciones por perfil')
            ->assertSee('Funcionalidades del sistema')
            ->assertSee('Perfil de prueba')
            ->assertSee('Catalogo e inventario')
            ->assertSee('name="permisos['.$this->funcionalidadId(Funcionalidad::REPUESTOS).'][editar]"', false);
    }

    public function test_guardar_la_matriz_escribe_una_fila_por_funcionalidad(): void
    {
        $rol = $this->nuevoRol();

        $this->guardarMatriz($rol, [
            Funcionalidad::REPUESTOS => ['ver', 'editar'],
            Funcionalidad::CATEGORIAS => ['ver'],
        ]);

        $this->assertSame(['ver' => true, 'editar' => true, 'eliminar' => false], $this->filaGuardada($rol, Funcionalidad::REPUESTOS));
        $this->assertSame(['ver' => true, 'editar' => false, 'eliminar' => false], $this->filaGuardada($rol, Funcionalidad::CATEGORIAS));
        // Las no marcadas tambien quedan guardadas, en false: eso es lo que
        // apaga el permiso heredado.
        $this->assertSame(['ver' => false, 'editar' => false, 'eliminar' => false], $this->filaGuardada($rol, Funcionalidad::SOLICITUDES));

        // Volver a guardar actualiza, no duplica.
        $this->guardarMatriz($rol, [Funcionalidad::REPUESTOS => ['ver']]);

        $this->assertSame(['ver' => true, 'editar' => false, 'eliminar' => false], $this->filaGuardada($rol, Funcionalidad::REPUESTOS));
        $this->assertSame(1, (int) DB::selectOne(
            'select count(*) as total from tbl_rol_funcionalidad where col_rol_id = ? and col_funcionalidad_id = ?',
            [$rol->id, $this->funcionalidadId(Funcionalidad::REPUESTOS)]
        )->total);
    }

    public function test_editar_o_eliminar_implican_ver(): void
    {
        $rol = $this->nuevoRol();

        $this->guardarMatriz($rol, [
            Funcionalidad::REPUESTOS => ['editar'],
            Funcionalidad::CATEGORIAS => ['eliminar'],
        ]);

        $this->assertSame(['ver' => true, 'editar' => true, 'eliminar' => false], $this->filaGuardada($rol, Funcionalidad::REPUESTOS));
        $this->assertSame(['ver' => true, 'editar' => false, 'eliminar' => true], $this->filaGuardada($rol, Funcionalidad::CATEGORIAS));
    }

    public function test_la_base_rechaza_editar_sin_ver(): void
    {
        $rol = $this->nuevoRol();

        $this->expectException(QueryException::class);

        DB::insert(
            'insert into tbl_rol_funcionalidad (col_rol_id, col_funcionalidad_id, col_ver, col_editar, col_eliminar) values (?, ?, 0, 1, 0)',
            [$rol->id, $this->funcionalidadId(Funcionalidad::REPUESTOS)]
        );
    }

    public function test_el_admin_del_sistema_no_pierde_permisos_aunque_se_le_limpie_la_matriz(): void
    {
        $rolAdmin = $this->rolDelSistema(User::ROL_ADMIN);

        // Formulario manipulado: todo desmarcado para el perfil administrador.
        $this->actingAs($this->admin())
            ->put(route('admin.permisos.update', $rolAdmin), ['permisos' => []])
            ->assertSessionHas('exito');

        foreach ([Funcionalidad::PERMISOS, Funcionalidad::ROLES, Funcionalidad::REPUESTOS] as $clave) {
            $this->assertSame(['ver' => true, 'editar' => true, 'eliminar' => true], $this->filaGuardada($rolAdmin, $clave));
        }

        // Y aunque alguien lo borre a mano en la base, sigue entrando.
        DB::update('update tbl_rol_funcionalidad set col_ver = 0, col_editar = 0, col_eliminar = 0 where col_rol_id = ?', [$rolAdmin->id]);

        $admin = $this->admin();

        $this->assertTrue($admin->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_EDITAR));
        $this->actingAs($admin)->get(route('admin.permisos.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk();
    }

    public function test_la_pantalla_bloquea_las_casillas_del_admin(): void
    {
        $rolAdmin = $this->rolDelSistema(User::ROL_ADMIN);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.permisos.index', ['rol_id' => $rolAdmin->id]))
            ->assertOk()
            ->assertSee('conserva')
            ->getContent();

        $casillas = $this->casillas($html);

        $this->assertNotEmpty($casillas);

        foreach ($casillas as $casilla) {
            $this->assertMatchesRegularExpression('/\schecked\b/', $casilla);
            $this->assertMatchesRegularExpression('/\sdisabled\b/', $casilla);
        }

        $this->assertMatchesRegularExpression('/<button type="submit" class="btn btn-marca"[^>]*\sdisabled/', $html);
    }

    /**
     * Etiquetas <input> de las casillas de la matriz, por nombre de campo.
     *
     * @return array<string, string>
     */
    private function casillas(string $html): array
    {
        preg_match_all('/<input[^>]*data-accion-permiso[^>]*>/s', $html, $coincidencias);

        $casillas = [];

        foreach ($coincidencias[0] as $etiqueta) {
            preg_match('/name="([^"]+)"/', $etiqueta, $nombre);
            $casillas[$nombre[1]] = $etiqueta;
        }

        return $casillas;
    }

    public function test_el_almacenista_no_entra_ni_guarda_permisos(): void
    {
        $almacenista = $this->almacenista();
        $rol = $this->nuevoRol();

        $this->actingAs($almacenista)
            ->get(route('admin.permisos.index'))
            ->assertForbidden();

        $this->actingAs($almacenista)
            ->put(route('admin.permisos.update', $rol), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::REPUESTOS) => ['ver' => '1']],
            ])
            ->assertForbidden();

        $this->assertNull($this->filaGuardada($rol, Funcionalidad::REPUESTOS));
    }

    public function test_ver_el_modulo_de_permisos_no_alcanza_para_guardar(): void
    {
        // Clave de la lista blanca: sin ella el modulo esta reservado y el
        // perfil no llegaria ni a ver la matriz.
        $supervisor = $this->nuevoRol(clave: 'administrador');
        $this->guardarMatriz($supervisor, [Funcionalidad::PERMISOS => ['ver']]);

        $usuario = $this->usuario($supervisor, User::ROL_ALMACENISTA);
        $otro = $this->nuevoRol();

        $this->actingAs($usuario)
            ->get(route('admin.permisos.index', ['rol_id' => $otro->id]))
            ->assertOk()
            ->assertSee('no modificarla');

        $this->actingAs($usuario)
            ->put(route('admin.permisos.update', $otro), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::REPUESTOS) => ['ver' => '1']],
            ])
            ->assertForbidden();
    }

    public function test_el_middleware_bloquea_las_acciones_no_marcadas(): void
    {
        $rol = $this->nuevoRol();
        $this->guardarMatriz($rol, [Funcionalidad::CATEGORIAS => ['ver']]);

        $usuario = $this->usuario($rol, User::ROL_ALMACENISTA);
        $categoria = Categoria::query()->firstOrFail();

        $this->actingAs($usuario)->get(route('admin.categorias.index'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.categorias.create'))->assertForbidden();
        $this->actingAs($usuario)->get(route('admin.categorias.edit', $categoria))->assertForbidden();
        $this->actingAs($usuario)->delete(route('admin.categorias.destroy', $categoria))->assertForbidden();
        // Solicitudes quedo desmarcada al guardar: el permiso heredado ya no aplica.
        $this->actingAs($usuario)->get(route('admin.solicitudes.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('admin.repuestos.index'))->assertForbidden();
    }

    public function test_la_matriz_concede_lo_que_es_admin_no_concedia(): void
    {
        // Sin "gestiona catalogo" el rol no entraba a categorias; con la matriz si.
        $rol = $this->nuevoRol(gestionaCatalogo: false);
        $this->guardarMatriz($rol, [Funcionalidad::CATEGORIAS => ['ver', 'editar']]);

        $usuario = $this->usuario($rol, User::ROL_ALMACENISTA);

        $this->actingAs($usuario)->get(route('admin.categorias.create'))->assertOk();

        $this->actingAs($usuario)
            ->post(route('admin.categorias.store'), [
                'col_nombre' => 'Categoria por permiso '.substr(uniqid(), -5),
                'col_color_hex' => '#123456',
                'col_activo' => '1',
            ])
            ->assertRedirect(route('admin.categorias.index'));
    }

    public function test_un_boton_sin_permiso_sale_gris_y_deshabilitado(): void
    {
        $rol = $this->nuevoRol();
        $this->guardarMatriz($rol, [Funcionalidad::CATEGORIAS => ['ver']]);

        $respuesta = $this->actingAs($this->usuario($rol, User::ROL_ALMACENISTA))
            ->get(route('admin.categorias.index'))
            ->assertOk();

        $respuesta->assertSee('accion-sin-permiso', false);
        $respuesta->assertSee('data-sin-permiso="editar"', false);
        $respuesta->assertSee('data-sin-permiso="eliminar"', false);
        // Ni el enlace de alta ni el formulario de anular llegan al HTML.
        $respuesta->assertDontSee('href="'.route('admin.categorias.create').'"', false);
        $respuesta->assertDontSee('name="_method" value="DELETE"', false);

        // El menu solo lista lo que el perfil puede ver.
        $respuesta->assertDontSee('href="'.route('admin.repuestos.index').'"', false);
    }

    public function test_el_detalle_de_una_solicitud_respeta_editar_y_eliminar(): void
    {
        $solicitud = Solicitud::create([
            'numero' => 'SOL-PRUEBA-'.substr(uniqid(), -8),
            'solicitante_nombre' => 'Solicitante de prueba',
            'solicitante_cedula' => '123456',
            'solicitante_email' => 'solicitante@example.com',
            'estado' => Solicitud::ESTADO_PENDIENTE,
        ]);

        $soloVer = $this->nuevoRol();
        $this->guardarMatriz($soloVer, [Funcionalidad::SOLICITUDES => ['ver']]);
        $usuario = $this->usuario($soloVer, User::ROL_ALMACENISTA);

        $respuesta = $this->actingAs($usuario)
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk();

        $respuesta->assertSee('data-sin-permiso="editar"', false);
        $respuesta->assertSee('data-sin-permiso="eliminar"', false);
        $respuesta->assertDontSee('action="'.route('admin.solicitudes.tomar', $solicitud).'"', false);
        $respuesta->assertDontSee('id="modalRechazar"', false);

        $this->actingAs($usuario)->post(route('admin.solicitudes.tomar', $solicitud))->assertForbidden();
        $this->actingAs($usuario)
            ->post(route('admin.solicitudes.rechazar', $solicitud), ['nota_almacen' => 'No'])
            ->assertForbidden();

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->refresh()->estado);

        // El almacenista sembrado conserva todas las acciones.
        $this->actingAs($this->almacenista())
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertDontSee('data-sin-permiso=', false)
            ->assertSee('id="modalRechazar"', false);
    }

    public function test_los_listados_del_panel_se_pintan_con_y_sin_permisos(): void
    {
        // Con clave de la lista blanca, porque el recorrido incluye la
        // pantalla de permisos, que esta reservada a esos perfiles.
        $soloVer = $this->nuevoRol(clave: 'administrador');
        $this->guardarMatriz($soloVer, [
            Funcionalidad::SOLICITUDES => ['ver'],
            Funcionalidad::REPUESTOS => ['ver'],
            Funcionalidad::CATEGORIAS => ['ver'],
            Funcionalidad::ROLES => ['ver'],
            Funcionalidad::PERMISOS => ['ver'],
            Funcionalidad::PARAMETROS => ['ver'],
        ]);
        $limitado = $this->usuario($soloVer, User::ROL_ALMACENISTA);
        $admin = $this->admin();

        $rutas = ['admin.solicitudes.index', 'admin.repuestos.index', 'admin.categorias.index',
            'admin.roles.index', 'admin.permisos.index', 'admin.parametros.index'];

        foreach ($rutas as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
            $this->actingAs($limitado)->get(route($ruta))->assertOk();
        }

        $this->actingAs($limitado)
            ->get(route('admin.repuestos.index'))
            ->assertSee('data-sin-permiso="editar"', false)
            ->assertDontSee('href="'.route('admin.repuestos.create').'"', false);
    }

    public function test_con_permiso_los_botones_salen_activos(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->get(route('admin.categorias.index'))
            ->assertOk();

        $respuesta->assertDontSee('data-sin-permiso=', false);
        $respuesta->assertSee('href="'.route('admin.categorias.create').'"', false);
    }

    public function test_una_funcionalidad_nueva_aparece_desmarcada_y_no_concede_nada(): void
    {
        $rol = $this->nuevoRol(gestionaCatalogo: true);
        $this->guardarMatriz($rol, [Funcionalidad::REPUESTOS => ['ver']]);

        DB::insert(
            'insert into tbl_funcionalidad (col_clave, col_nombre, col_seccion, col_icono, col_orden, col_activo) values (?, ?, ?, ?, ?, 1)',
            ['modulo_nuevo_prueba', 'Modulo nuevo de prueba', 'Pruebas', 'star', 999]
        );
        $nuevaId = $this->funcionalidadId('modulo_nuevo_prueba');

        $html = $this->actingAs($this->admin())
            ->get(route('admin.permisos.index', ['rol_id' => $rol->id]))
            ->assertOk()
            ->assertSee('Modulo nuevo de prueba')
            ->getContent();

        $casillas = $this->casillas($html);

        foreach (array_keys(Funcionalidad::ACCIONES) as $accion) {
            $this->assertArrayHasKey("permisos[{$nuevaId}][{$accion}]", $casillas);
            $this->assertDoesNotMatchRegularExpression('/\schecked\b/', $casillas["permisos[{$nuevaId}][{$accion}]"]);
        }

        // La que si se guardo sigue marcada: la pantalla lee la matriz real.
        $this->assertMatchesRegularExpression(
            '/\schecked\b/',
            $casillas['permisos['.$this->funcionalidadId(Funcionalidad::REPUESTOS).'][ver]']
        );

        $usuario = $this->usuario($rol, User::ROL_ALMACENISTA);

        $this->assertFalse($usuario->puede('modulo_nuevo_prueba', Funcionalidad::ACCION_VER));
        $this->assertTrue($usuario->puede(Funcionalidad::REPUESTOS, Funcionalidad::ACCION_VER));
    }

    public function test_un_rol_sin_matriz_guardada_conserva_el_permiso_heredado(): void
    {
        $gestiona = $this->usuario($this->nuevoRol(gestionaCatalogo: true), User::ROL_ALMACENISTA);
        $despacha = $this->usuario($this->nuevoRol(gestionaCatalogo: false), User::ROL_ALMACENISTA);

        $this->actingAs($gestiona)->get(route('admin.repuestos.index'))->assertOk();
        $this->actingAs($gestiona)->get(route('admin.roles.index'))->assertOk();
        // Permisos ya no se hereda: esta reservado a los perfiles
        // administradores y este rol no lleva una de esas claves.
        $this->actingAs($gestiona)->get(route('admin.permisos.index'))->assertForbidden();

        $this->actingAs($despacha)->get(route('admin.solicitudes.index'))->assertOk();
        $this->actingAs($despacha)->get(route('admin.repuestos.index'))->assertForbidden();

        // Una funcionalidad que no estaba antes no se hereda.
        $this->assertFalse($gestiona->puede('modulo_que_no_existe', Funcionalidad::ACCION_VER));
    }

    public function test_el_almacenista_sembrado_sigue_despachando_solicitudes(): void
    {
        $almacenista = $this->almacenista();

        $this->actingAs($almacenista)->get(route('admin.solicitudes.index'))->assertOk();
        $this->actingAs($almacenista)->get(route('admin.repuestos.index'))->assertForbidden();
        $this->assertTrue($almacenista->puede(Funcionalidad::SOLICITUDES, Funcionalidad::ACCION_ELIMINAR));
    }

    public function test_no_se_guardan_permisos_de_un_rol_anulado(): void
    {
        $rol = $this->nuevoRol();
        $rol->update(['col_activo' => false]);

        $this->actingAs($this->admin())
            ->put(route('admin.permisos.update', $rol), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::REPUESTOS) => ['ver' => '1']],
            ])
            ->assertSessionHasErrors('rol_id');

        $this->assertNull($this->filaGuardada($rol, Funcionalidad::REPUESTOS));
    }

    public function test_se_rechazan_funcionalidades_y_acciones_desconocidas(): void
    {
        $rol = $this->nuevoRol();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.permisos.update', $rol), ['permisos' => [999999999 => ['ver' => '1']]])
            ->assertSessionHasErrors('permisos');

        $this->actingAs($admin)
            ->put(route('admin.permisos.update', $rol), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::REPUESTOS) => ['borrar_todo' => '1']],
            ])
            ->assertSessionHasErrors('permisos.'.$this->funcionalidadId(Funcionalidad::REPUESTOS));

        $this->assertNull($this->filaGuardada($rol, Funcionalidad::REPUESTOS));
    }

    /* ------------------------------------------------------------------ */
    /* Modulo de permisos reservado a los perfiles administradores */
    /* ------------------------------------------------------------------ */

    public function test_un_perfil_no_autorizado_no_entra_al_modulo_aunque_la_matriz_lo_conceda(): void
    {
        $rol = $this->nuevoRol(gestionaCatalogo: true);
        $this->guardarMatriz($rol, [
            Funcionalidad::PERMISOS => ['ver', 'editar', 'eliminar'],
            Funcionalidad::REPUESTOS => ['ver'],
        ]);

        // El servicio no escribe la concesion reservada, llegue marcada o no.
        $this->assertSame(
            ['ver' => false, 'editar' => false, 'eliminar' => false],
            $this->filaGuardada($rol, Funcionalidad::PERMISOS)
        );

        // Y aunque alguien la escriba a mano en la base, puede() la niega: la
        // lista blanca se evalua antes que la matriz.
        DB::update(
            'update tbl_rol_funcionalidad set col_ver = 1, col_editar = 1, col_eliminar = 1
              where col_rol_id = ? and col_funcionalidad_id = ?',
            [$rol->id, $this->funcionalidadId(Funcionalidad::PERMISOS)]
        );

        $usuario = $this->usuario($rol, User::ROL_ALMACENISTA);

        $this->assertFalse($usuario->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_VER));
        $this->assertFalse($usuario->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_EDITAR));
        $this->assertFalse($usuario->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_ELIMINAR));

        $this->actingAs($usuario)->get(route('admin.permisos.index'))->assertForbidden();
        $this->actingAs($usuario)
            ->put(route('admin.permisos.update', $rol), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::CATEGORIAS) => ['ver' => '1']],
            ])
            ->assertForbidden();

        // El menu tampoco ofrece la entrada, y el resto de la matriz vive.
        $this->actingAs($usuario)
            ->get(route('admin.repuestos.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.permisos.index').'"', false)
            ->assertDontSee('Funciones por perfil');
    }

    public function test_un_perfil_de_la_lista_blanca_entra_y_guarda(): void
    {
        $administrador = $this->nuevoRol(clave: 'administrador');
        $this->guardarMatriz($administrador, [Funcionalidad::PERMISOS => ['ver', 'editar']]);

        // A este si se le guarda la concesion: esta en la lista blanca.
        $this->assertSame(
            ['ver' => true, 'editar' => true, 'eliminar' => false],
            $this->filaGuardada($administrador, Funcionalidad::PERMISOS)
        );

        $usuario = $this->usuario($administrador, User::ROL_ALMACENISTA);
        $otro = $this->nuevoRol();

        $this->actingAs($usuario)
            ->get(route('admin.permisos.index', ['rol_id' => $otro->id]))
            ->assertOk()
            ->assertSee('Funcionalidades del sistema');

        $this->actingAs($usuario)
            ->put(route('admin.permisos.update', $otro), [
                'permisos' => [$this->funcionalidadId(Funcionalidad::CATEGORIAS) => ['ver' => '1']],
            ])
            ->assertRedirect(route('admin.permisos.index', ['rol_id' => $otro->id]))
            ->assertSessionHas('exito');

        $this->assertSame(
            ['ver' => true, 'editar' => false, 'eliminar' => false],
            $this->filaGuardada($otro, Funcionalidad::CATEGORIAS)
        );
    }

    public function test_un_perfil_no_autorizado_conserva_las_demas_funcionalidades_por_matriz(): void
    {
        $rol = $this->nuevoRol();
        $this->guardarMatriz($rol, [
            Funcionalidad::CATEGORIAS => ['ver', 'editar'],
            Funcionalidad::SOLICITUDES => ['ver'],
            Funcionalidad::PERMISOS => ['ver', 'editar'],
        ]);

        $usuario = $this->usuario($rol, User::ROL_ALMACENISTA);

        $this->actingAs($usuario)->get(route('admin.categorias.index'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.categorias.create'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.solicitudes.index'))->assertOk();
        $this->actingAs($usuario)->get(route('admin.permisos.index'))->assertForbidden();

        $this->assertTrue($usuario->puede(Funcionalidad::CATEGORIAS, Funcionalidad::ACCION_EDITAR));
        $this->assertFalse($usuario->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_VER));
    }

    public function test_la_pantalla_bloquea_las_casillas_reservadas_de_un_perfil_no_autorizado(): void
    {
        $rol = $this->nuevoRol(gestionaCatalogo: true);
        $permisosId = $this->funcionalidadId(Funcionalidad::PERMISOS);
        $repuestosId = $this->funcionalidadId(Funcionalidad::REPUESTOS);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.permisos.index', ['rol_id' => $rol->id]))
            ->assertOk()
            ->assertSee('data-permiso-reservado="'.Funcionalidad::PERMISOS.'"', false)
            ->getContent();

        $casillas = $this->casillas($html);

        foreach (array_keys(Funcionalidad::ACCIONES) as $accion) {
            $reservada = $casillas["permisos[{$permisosId}][{$accion}]"];

            $this->assertMatchesRegularExpression('/\sdisabled\b/', $reservada);
            $this->assertDoesNotMatchRegularExpression('/\schecked\b/', $reservada);

            // Las demas del mismo rol siguen editables y con su heredado.
            $libre = $casillas["permisos[{$repuestosId}][{$accion}]"];

            $this->assertDoesNotMatchRegularExpression('/\sdisabled\b/', $libre);
            $this->assertMatchesRegularExpression('/\schecked\b/', $libre);
        }
    }

    public function test_el_perfil_administrador_del_sistema_no_ve_bloqueada_la_reservada(): void
    {
        $rolAdmin = $this->rolDelSistema(User::ROL_ADMIN);

        $this->actingAs($this->admin())
            ->get(route('admin.permisos.index', ['rol_id' => $rolAdmin->id]))
            ->assertOk()
            ->assertDontSee('data-permiso-reservado=', false);

        $this->assertTrue($this->admin()->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_EDITAR));
    }

    public function test_una_accion_desconocida_en_el_codigo_falla_en_voz_alta(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->admin()->puede(Funcionalidad::REPUESTOS, 'borrar');
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('admin.permisos.index'))->assertRedirect(route('admin.login'));
    }
}
