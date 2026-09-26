<?php

namespace Tests\Feature;

use App\Mail\ContrasenaSolicitanteMail;
use App\Models\Funcionalidad;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\User;
use App\Services\ImportadorSolicitantesErp;
use App\Services\PermisoService;
use Database\Seeders\FuncionalidadSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceso del solicitante del ERP a su portal (guard `solicitante`) y la
 * asignacion de su contrasena desde el panel.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase. Cada solicitante de prueba lleva un correo unico.
 */
class AccesoSolicitanteTest extends TestCase
{
    use DatabaseTransactions;

    private const CLAVE = 'Clave2026abc';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(FuncionalidadSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function solicitante(array $datos = [], ?string $clave = self::CLAVE): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create($datos + [
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Acceso '.uniqid(),
            'col_cedula' => '400'.random_int(100000, 999999),
            'col_correo' => 'acceso.'.uniqid().'@sidocsa.com',
            'col_activo' => true,
        ]);

        if ($clave !== null) {
            SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
                'col_password' => Hash::make($clave),
                'col_password_asignada_at' => now(),
            ])->save());
        }

        return $solicitante;
    }

    private function usuario(string $clave): User
    {
        return User::create([
            'name' => 'Usuario acceso prueba',
            'email' => 'acceso.panel.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => $clave,
            'rol_id' => Rol::where('col_clave', $clave)->value('id'),
            'activo' => true,
        ]);
    }

    private function ingresar(string $correo, string $clave): TestResponse
    {
        return $this->from(route('solicitante.login'))
            ->post(route('solicitante.login.attempt'), ['correo' => $correo, 'contrasena' => $clave]);
    }

    /* --- Ingreso -------------------------------------------------------- */

    public function test_ingresa_con_su_correo_y_contrasena(): void
    {
        $persona = $this->solicitante();

        // El correo se compara sin distinguir mayusculas ni espacios.
        $this->ingresar('  '.strtoupper($persona->col_correo).' ', self::CLAVE)
            ->assertRedirect(route('solicitante.solicitudes.index'));

        $this->assertAuthenticatedAs($persona, 'solicitante');
        // El guard del panel no queda autenticado.
        $this->assertGuest('web');
        $this->assertNotNull($persona->fresh()->col_ultimo_ingreso_at);
    }

    public function test_una_contrasena_errada_no_entra_y_el_mensaje_no_enumera(): void
    {
        $persona = $this->solicitante();

        $this->ingresar($persona->col_correo, 'Otra2026xyz')
            ->assertRedirect(route('solicitante.login'))
            ->assertSessionHasErrors(['correo' => 'El correo o la contrasena no son correctos.']);
        $this->assertGuest('solicitante');

        // Un correo que no existe da exactamente el mismo mensaje.
        $this->ingresar('nadie.'.uniqid().'@sidocsa.com', self::CLAVE)
            ->assertSessionHasErrors(['correo' => 'El correo o la contrasena no son correctos.']);
    }

    public function test_un_inactivo_no_entra_aunque_la_contrasena_sea_correcta(): void
    {
        $persona = $this->solicitante(['col_activo' => false]);

        $this->ingresar($persona->col_correo, self::CLAVE)
            ->assertSessionHasErrors(['correo' => 'El correo o la contrasena no son correctos.']);
        $this->assertGuest('solicitante');
    }

    public function test_sin_contrasena_asignada_no_entra(): void
    {
        $persona = $this->solicitante([], null);

        $this->ingresar($persona->col_correo, '')->assertSessionHasErrors('contrasena');
        $this->ingresar($persona->col_correo, self::CLAVE)->assertSessionHasErrors('correo');
        $this->assertGuest('solicitante');
    }

    /**
     * Dos activos con el mismo correo: el usuario no identifica a una sola
     * persona, asi que ninguno entra (ni siquiera el que tiene contrasena).
     */
    public function test_un_correo_compartido_entre_activos_no_entra(): void
    {
        $persona = $this->solicitante();
        $this->solicitante(['col_correo' => $persona->col_correo], null);

        $this->ingresar($persona->col_correo, self::CLAVE)->assertSessionHasErrors('correo');
        $this->assertGuest('solicitante');
    }

    public function test_se_bloquea_tras_varios_intentos_fallidos(): void
    {
        $persona = $this->solicitante();

        for ($i = 0; $i < 5; $i++) {
            $this->ingresar($persona->col_correo, 'Mala'.$i.'2026x');
        }

        // Ni con la correcta: el limite por correo + IP ya se agoto.
        $this->ingresar($persona->col_correo, self::CLAVE)
            ->assertSessionHasErrors('correo');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('correo'));
        $this->assertGuest('solicitante');
    }

    public function test_el_solicitante_no_entra_al_panel_del_almacen(): void
    {
        $persona = $this->solicitante();

        $this->actingAs($persona, 'solicitante')
            ->get(route('admin.solicitudes.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_salir_cierra_la_sesion(): void
    {
        $persona = $this->solicitante();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.logout'))
            ->assertRedirect(route('solicitante.login'));

        $this->assertGuest('solicitante');
    }

    public function test_si_el_erp_lo_inactiva_la_sesion_abierta_se_cierra(): void
    {
        $persona = $this->solicitante();
        $this->actingAs($persona, 'solicitante')->get(route('solicitante.solicitudes.index'))->assertOk();

        $persona->update(['col_activo' => false]);

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.index'))
            ->assertRedirect(route('solicitante.login'))
            ->assertSessionHas('error');
        $this->assertGuest('solicitante');
    }

    /* --- Cambio de contrasena ------------------------------------------ */

    public function test_cambia_su_contrasena_desde_la_pagina_de_ingreso(): void
    {
        $persona = $this->solicitante();

        $this->get(route('solicitante.login'))
            ->assertOk()
            ->assertSee('href="'.route('solicitante.contrasena.edit').'"', false);

        $this->get(route('solicitante.contrasena.edit'))
            ->assertOk()
            ->assertSee('name="contrasena_actual"', false)
            ->assertSee('name="contrasena_confirmation"', false);

        $this->post(route('solicitante.contrasena.update'), [
            'correo' => $persona->col_correo,
            'contrasena_actual' => self::CLAVE,
            'contrasena' => 'NuevaClave2026',
            'contrasena_confirmation' => 'NuevaClave2026',
        ])->assertRedirect(route('solicitante.login'))->assertSessionHas('exito');

        $fresca = $persona->fresh();
        $this->assertTrue(Hash::check('NuevaClave2026', $fresca->col_password));
        $this->assertFalse(Hash::check(self::CLAVE, $fresca->col_password));
        $this->assertNotNull($fresca->col_password_cambiada_at);

        $this->ingresar($persona->col_correo, self::CLAVE)->assertSessionHasErrors('correo');
        $this->ingresar($persona->col_correo, 'NuevaClave2026')->assertRedirect(route('solicitante.solicitudes.index'));
    }

    public function test_el_cambio_exige_la_contrasena_actual_correcta(): void
    {
        $persona = $this->solicitante();
        $hashAntes = $persona->col_password;

        $this->post(route('solicitante.contrasena.update'), [
            'correo' => $persona->col_correo,
            'contrasena_actual' => 'NoEsLaActual1',
            'contrasena' => 'NuevaClave2026',
            'contrasena_confirmation' => 'NuevaClave2026',
        ])->assertSessionHasErrors('correo');

        $this->assertSame($hashAntes, $persona->fresh()->col_password);
    }

    public function test_la_contrasena_nueva_debe_ser_robusta_y_confirmada(): void
    {
        $persona = $this->solicitante();
        $base = ['correo' => $persona->col_correo, 'contrasena_actual' => self::CLAVE];

        foreach (['corta1A', 'todominusculas1', 'SINNUMEROSaqui'] as $debil) {
            $this->post(route('solicitante.contrasena.update'), $base + [
                'contrasena' => $debil,
                'contrasena_confirmation' => $debil,
            ])->assertSessionHasErrors('contrasena');
        }

        $this->post(route('solicitante.contrasena.update'), $base + [
            'contrasena' => 'NuevaClave2026',
            'contrasena_confirmation' => 'OtraClave2026',
        ])->assertSessionHasErrors('contrasena');

        $this->post(route('solicitante.contrasena.update'), $base + [
            'contrasena' => self::CLAVE,
            'contrasena_confirmation' => self::CLAVE,
        ])->assertSessionHasErrors('contrasena');

        $this->assertTrue(Hash::check(self::CLAVE, $persona->fresh()->col_password));
    }

    /* --- Asignacion desde el panel -------------------------------------- */

    /**
     * Lee la contrasena del correo enviado (el unico sitio donde existe).
     */
    private function contrasenaEnviada(SolicitanteErp $persona): ?string
    {
        $contrasena = null;

        Mail::assertSent(ContrasenaSolicitanteMail::class, function (ContrasenaSolicitanteMail $correo) use ($persona, &$contrasena) {
            preg_match('/data-contrasena[^>]*>([^<]+)</', $correo->render(), $partes);
            $contrasena = $partes[1] ?? null;

            return $correo->hasTo($persona->col_correo);
        });

        return $contrasena;
    }

    public function test_asignar_contrasena_la_envia_al_correo_y_no_la_expone(): void
    {
        $persona = $this->solicitante([], null);
        $admin = $this->usuario(User::ROL_ADMIN);

        $registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$registros) {
            $registros[] = $evento->message.' '.json_encode($evento->context);
        });

        $html = $this->actingAs($admin)
            ->from(route('admin.solicitantes.index'))
            ->followingRedirects()
            ->post(route('admin.solicitantes.contrasena', $persona))
            ->assertOk()
            ->assertSee('Se genero una contrasena nueva')
            ->getContent();

        $contrasena = $this->contrasenaEnviada($persona);
        $fresca = $persona->fresh();

        $this->assertNotNull($contrasena);
        $this->assertSame(12, strlen($contrasena));
        $this->assertMatchesRegularExpression('/[a-z]/', $contrasena);
        $this->assertMatchesRegularExpression('/[A-Z]/', $contrasena);
        $this->assertMatchesRegularExpression('/[0-9]/', $contrasena);

        // Solo el hash en la base.
        $this->assertNotSame($contrasena, $fresca->col_password);
        $this->assertTrue(Hash::check($contrasena, $fresca->col_password));
        $this->assertNotNull($fresca->col_password_asignada_at);

        // Ni en la pantalla ni en el log.
        $this->assertStringNotContainsString($contrasena, $html);
        $this->assertStringNotContainsString($fresca->col_password, $html);
        foreach ($registros as $registro) {
            $this->assertStringNotContainsString($contrasena, $registro);
            $this->assertStringNotContainsString($fresca->col_password, $registro);
        }

        // Y con ella entra al portal.
        $this->ingresar($persona->col_correo, $contrasena)->assertRedirect(route('solicitante.solicitudes.index'));
    }

    public function test_restablecer_anula_la_contrasena_anterior(): void
    {
        $persona = $this->solicitante();

        $this->actingAs($this->usuario(User::ROL_ADMIN))
            ->post(route('admin.solicitantes.contrasena', $persona))
            ->assertSessionHas('exito');

        $nueva = $this->contrasenaEnviada($persona);
        $fresca = $persona->fresh();

        $this->assertFalse(Hash::check(self::CLAVE, $fresca->col_password));
        $this->assertTrue(Hash::check($nueva, $fresca->col_password));
    }

    public function test_si_el_correo_no_sale_la_asignacion_se_revierte(): void
    {
        $persona = $this->solicitante();
        $hashAntes = $persona->col_password;

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido de prueba'));

        $this->actingAs($this->usuario(User::ROL_ADMIN))
            ->post(route('admin.solicitantes.contrasena', $persona))
            ->assertSessionHas('error');

        $this->assertStringContainsString('la contrasena anterior sigue vigente', session('error'));

        $fresca = $persona->fresh();
        $this->assertSame($hashAntes, $fresca->col_password);
        $this->assertTrue(Hash::check(self::CLAVE, $fresca->col_password));
    }

    public function test_si_el_correo_no_sale_un_solicitante_nuevo_sigue_sin_acceso(): void
    {
        $persona = $this->solicitante([], null);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido de prueba'));

        $this->actingAs($this->usuario(User::ROL_ADMIN))
            ->post(route('admin.solicitantes.contrasena', $persona))
            ->assertSessionHas('error');

        $fresca = $persona->fresh();
        $this->assertNull($fresca->col_password);
        $this->assertNull($fresca->col_password_asignada_at);
    }

    public function test_no_se_asigna_a_inactivos_sin_correo_ni_con_correo_compartido(): void
    {
        $admin = $this->usuario(User::ROL_ADMIN);
        $compartido = 'compartido.'.uniqid().'@sidocsa.com';

        $casos = [
            $this->solicitante(['col_activo' => false], null),
            $this->solicitante(['col_correo' => null], null),
            $this->solicitante(['col_correo' => $compartido], null),
        ];
        $this->solicitante(['col_correo' => $compartido], null);

        foreach ($casos as $persona) {
            $this->actingAs($admin)
                ->post(route('admin.solicitantes.contrasena', $persona))
                ->assertSessionHas('error');

            $this->assertNull($persona->fresh()->col_password);
        }

        Mail::assertNothingSent();
    }

    public function test_el_listado_muestra_los_solicitantes_y_el_boton_al_final_de_la_fila(): void
    {
        $conCorreo = $this->solicitante([], null);
        $sinCorreo = $this->solicitante(['col_correo' => null], null);

        $respuesta = $this->actingAs($this->usuario(User::ROL_ADMIN))
            ->get(route('admin.solicitantes.index', ['q' => $conCorreo->col_codigo_erp]))
            ->assertOk();

        $respuesta->assertSee($conCorreo->col_nombre);
        $respuesta->assertSee($conCorreo->col_cedula);
        $respuesta->assertSee($conCorreo->col_correo);
        $respuesta->assertSee('action="'.route('admin.solicitantes.contrasena', $conCorreo).'"', false);
        $respuesta->assertSee('Asignar contrasena');
        // El hash no llega a la vista.
        $respuesta->assertDontSee('$2y$', false);

        $this->actingAs($this->usuario(User::ROL_ADMIN))
            ->get(route('admin.solicitantes.index', ['q' => $sinCorreo->col_codigo_erp]))
            ->assertOk()
            ->assertSee('no tiene correo registrado')
            ->assertDontSee('action="'.route('admin.solicitantes.contrasena', $sinCorreo).'"', false);
    }

    public function test_el_almacenista_sin_permiso_recibe_403(): void
    {
        $persona = $this->solicitante([], null);
        $almacenista = $this->usuario(User::ROL_ALMACENISTA);

        $this->assertFalse($almacenista->puede(Funcionalidad::SOLICITANTES, Funcionalidad::ACCION_EDITAR));

        $this->actingAs($almacenista)->get(route('admin.solicitantes.index'))->assertForbidden();
        $this->actingAs($almacenista)->post(route('admin.solicitantes.contrasena', $persona))->assertForbidden();

        $this->assertNull($persona->fresh()->col_password);
        Mail::assertNothingSent();
    }

    public function test_solo_ver_pinta_el_boton_deshabilitado_y_el_post_da_403(): void
    {
        $rol = Rol::create([
            'col_clave' => 'perm_'.substr(uniqid(), -8),
            'col_nombre' => 'Solo ver solicitantes',
            'col_gestiona_catalogo' => false,
            'col_activo' => true,
        ]);
        $id = (int) DB::selectOne('select id from tbl_funcionalidad where col_clave = ?', [Funcionalidad::SOLICITANTES])->id;
        app(PermisoService::class)->guardar($rol, [$id => ['ver' => '1']]);

        $usuario = User::create([
            'name' => 'Solo ver',
            'email' => 'solo.ver.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
        $persona = $this->solicitante([], null);

        $this->actingAs($usuario)
            ->get(route('admin.solicitantes.index', ['q' => $persona->col_codigo_erp]))
            ->assertOk()
            ->assertSee('data-sin-permiso="editar"', false)
            ->assertDontSee('action="'.route('admin.solicitantes.contrasena', $persona).'"', false)
            // La entrada del menu si aparece: puede ver.
            ->assertSee('href="'.route('admin.solicitantes.index').'"', false);

        $this->actingAs($usuario)->post(route('admin.solicitantes.contrasena', $persona))->assertForbidden();
    }

    /* --- Reimportar no toca las credenciales --------------------------- */

    public function test_reimportar_solicitantes_no_borra_la_contrasena(): void
    {
        $persona = $this->solicitante();
        SolicitanteErp::withoutTimestamps(fn () => $persona->forceFill(['col_remember_token' => 'token-de-prueba'])->save());
        $hash = $persona->fresh()->col_password;

        $resultado = app(ImportadorSolicitantesErp::class)->importar([
            2 => [
                'codigo_erp' => $persona->col_codigo_erp,
                'nombre' => 'Nombre corregido por el ERP',
                'correo' => $persona->col_correo,
                'activo' => '1',
            ],
        ]);

        $this->assertSame(1, $resultado->actualizados);

        $fresca = $persona->fresh();
        $this->assertSame('Nombre corregido por el ERP', $fresca->col_nombre);
        $this->assertSame($hash, $fresca->col_password);
        $this->assertSame('token-de-prueba', $fresca->col_remember_token);
        $this->assertNotNull($fresca->col_password_asignada_at);
        $this->assertTrue(Hash::check(self::CLAVE, $fresca->col_password));
    }
}
