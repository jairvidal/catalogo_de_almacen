<?php

namespace Tests\Feature;

use App\Mail\PedidoListoMail;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\User;
use App\Services\AprobacionSolicitudService;
use App\Services\SolicitudService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Solicitante del ERP en el flujo publico: formulario, buscador, consulta y
 * aviso de "pedido listo".
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase. Los nombres llevan una marca unica para no cruzarse con
 * solicitantes reales.
 */
class SolicitanteErpTest extends TestCase
{
    use DatabaseTransactions;

    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Zqx'.substr(uniqid(), -6);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function solicitante(array $datos = []): SolicitanteErp
    {
        return SolicitanteErp::create($datos + [
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Persona '.$this->marca,
            'col_cedula' => '200'.random_int(100000, 999999),
            'col_correo' => 'erp.'.uniqid().'@sidocsa.com',
            'col_area' => 'Mantenimiento',
            'col_telefono' => '4455',
            'col_activo' => true,
        ]);
    }

    private function repuesto(): Repuesto
    {
        return Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba solicitante',
            'unidad_medida' => 'UND',
            'existencia' => 10,
            'stock_minimo' => 1,
            'stock_maximo' => 20,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function enviar(array $datos): TestResponse
    {
        // El nombre completo es obligatorio; quien quiera probar su ausencia
        // lo manda explicito.
        return $this->withSession(['carrito' => [$this->repuesto()->id => 2]])
            ->post(route('solicitudes.store'), $datos + ['nombre_completo' => 'Digitado '.$this->marca]);
    }

    private function administrador(): User
    {
        return User::create([
            'name' => 'Admin solicitante prueba',
            'email' => 'solicitante.admin.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => 'admin',
            'rol_id' => Rol::where('col_clave', 'admin')->value('id'),
            'activo' => true,
        ]);
    }

    /* --- Formulario ------------------------------------------------------ */

    public function test_el_formulario_ya_no_pide_cedula_telefono_ni_correo(): void
    {
        $respuesta = $this->withSession(['carrito' => [$this->repuesto()->id => 1]])
            ->get(route('solicitudes.create'));

        $respuesta->assertOk();
        $respuesta->assertSee('data-combo-solicitante', false);
        $respuesta->assertSee('name="solicitante_erp_id"', false);
        $respuesta->assertSee('name="observaciones"', false);
        $respuesta->assertSee('name="nombre_completo"', false);
        $respuesta->assertSee('Nombres y apellidos');

        // Orden del formulario: Nombre completo, Solicitante, Observaciones.
        $html = $respuesta->getContent();
        $this->assertTrue(
            strpos($html, 'id="nombre_completo"') < strpos($html, 'data-combo-solicitante')
            && strpos($html, 'data-combo-solicitante') < strpos($html, 'id="observaciones"')
        );
        $respuesta->assertDontSee('name="solicitante_area"', false);
        $respuesta->assertDontSee('name="solicitante_cedula"', false);
        $respuesta->assertDontSee('name="solicitante_telefono"', false);
        $respuesta->assertDontSee('name="solicitante_email"', false);
    }

    public function test_crear_solicitud_copia_los_datos_del_solicitante_y_guarda_la_fk(): void
    {
        $persona = $this->solicitante();

        $this->enviar(['solicitante_erp_id' => $persona->id, 'observaciones' => 'OT-123'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();

        $this->assertSame($persona->id, (int) $solicitud->solicitante_erp_id);
        $this->assertSame($persona->col_nombre, $solicitud->solicitante_nombre);
        $this->assertSame($persona->col_cedula, $solicitud->solicitante_cedula);
        $this->assertSame($persona->col_correo, $solicitud->solicitante_email);
        $this->assertSame('Mantenimiento', $solicitud->solicitante_area);
        $this->assertSame('4455', $solicitud->solicitante_telefono);
        $this->assertSame('OT-123', $solicitud->observaciones);
    }

    /**
     * El snapshot es el historico: corregir despues a la persona en el ERP no
     * cambia lo que quedo guardado en la solicitud.
     */
    public function test_corregir_al_solicitante_no_altera_el_snapshot(): void
    {
        $persona = $this->solicitante();
        $this->enviar(['solicitante_erp_id' => $persona->id])->assertRedirect();
        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();

        $persona->update(['col_nombre' => 'Otro nombre '.$this->marca]);

        $this->assertSame('Persona '.$this->marca, $solicitud->fresh()->solicitante_nombre);
    }

    public function test_un_solicitante_inexistente_se_rechaza(): void
    {
        $antes = Solicitud::count();

        $this->enviar(['solicitante_erp_id' => 999999999])
            ->assertSessionHasErrors('solicitante_erp_id');

        $this->assertSame($antes, Solicitud::count());
    }

    public function test_un_solicitante_inactivo_se_rechaza(): void
    {
        $persona = $this->solicitante(['col_activo' => false]);
        $antes = Solicitud::count();

        $this->enviar(['solicitante_erp_id' => $persona->id])
            ->assertSessionHasErrors('solicitante_erp_id');

        $this->assertSame($antes, Solicitud::count());
    }

    public function test_sin_solicitante_se_rechaza(): void
    {
        $this->enviar(['solicitante_erp_id' => ''])->assertSessionHasErrors('solicitante_erp_id');
        $this->enviar(['solicitante_erp_id' => 'abc'])->assertSessionHasErrors('solicitante_erp_id');
    }

    /**
     * La garantia no es el request sino la relectura dentro de la transaccion:
     * el ERP pudo inactivar a la persona entre el formulario y el envio.
     */
    public function test_el_servicio_relee_al_solicitante_dentro_de_la_transaccion(): void
    {
        $persona = $this->solicitante(['col_activo' => false]);
        session(['carrito' => [$this->repuesto()->id => 1]]);

        $this->expectException(ValidationException::class);

        app(SolicitudService::class)->crearDesdeCarrito(['solicitante_erp_id' => $persona->id]);
    }

    /**
     * Al volver con errores (aqui, observaciones demasiado largas) el cuadro
     * combinado conserva al solicitante elegido mostrando su nombre.
     */
    public function test_al_volver_con_errores_se_conserva_el_solicitante_elegido(): void
    {
        $persona = $this->solicitante();

        $this->from(route('solicitudes.create'))
            ->enviar(['solicitante_erp_id' => $persona->id, 'observaciones' => str_repeat('x', 1001)])
            ->assertSessionHasErrors('observaciones');

        $respuesta = $this->withSession(['carrito' => [$this->repuesto()->id => 1]])
            ->withSession(['_old_input' => [
                'solicitante_erp_id' => (string) $persona->id,
                'nombre_completo' => 'Digitado '.$this->marca,
            ]])
            ->get(route('solicitudes.create'));

        $respuesta->assertOk();
        $respuesta->assertSee('value="Digitado '.$this->marca.'"', false);
        $respuesta->assertSee('value="'.$persona->col_nombre.'"', false);
        $respuesta->assertSee('value="'.$persona->id.'"', false);
    }

    /* --- Nombre completo digitado ---------------------------------------- */

    public function test_el_nombre_completo_es_obligatorio(): void
    {
        $persona = $this->solicitante();

        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => '   '])
            ->assertSessionHasErrors('nombre_completo');
    }

    public function test_el_nombre_completo_no_supera_150_caracteres(): void
    {
        $persona = $this->solicitante();

        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => str_repeat('a', 151)])
            ->assertSessionHasErrors('nombre_completo');

        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => str_repeat('a', 150)])
            ->assertSessionHasNoErrors();
    }

    /**
     * Se guarda recortado y APARTE del snapshot: solicitante_nombre sigue
     * siendo el nombre del ERP.
     */
    public function test_el_nombre_completo_se_guarda_sin_tocar_el_nombre_del_erp(): void
    {
        $persona = $this->solicitante();

        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => '  Maria   Lopez '])
            ->assertSessionHasNoErrors();

        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();
        $this->assertSame('Maria Lopez', $solicitud->nombre_completo);
        $this->assertSame($persona->col_nombre, $solicitud->solicitante_nombre);
    }

    public function test_el_nombre_completo_aparece_en_el_detalle_publico_y_del_panel(): void
    {
        $persona = $this->solicitante();
        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => 'Maria '.$this->marca]);
        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();
        // El panel solo ve lo que el solicitante aprobo.
        $this->aprobar($solicitud, $persona);

        $this->get(route('solicitudes.consultar', ['numero' => $solicitud->numero, 'solicitante' => $persona->id]))
            ->assertSee('Nombre completo')
            ->assertSee('Maria '.$this->marca);

        $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('Nombre completo')
            ->assertSee('Maria '.$this->marca);
    }

    /** Una historica sin nombre completo no pinta el renglon vacio. */
    public function test_una_historica_sin_nombre_completo_no_pinta_el_renglon(): void
    {
        $solicitud = Solicitud::create([
            'numero' => 'SOL-TEST-'.substr(uniqid(), -8),
            'solicitante_nombre' => 'Historico '.$this->marca,
            'solicitante_cedula' => '123456',
            'solicitante_email' => 'historico@sidocsa.com',
            'estado' => Solicitud::ESTADO_PENDIENTE,
        ]);

        $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertDontSee('Nombre completo');
    }

    public function test_el_filtro_solicitante_del_panel_busca_en_el_nombre_completo(): void
    {
        $persona = $this->solicitante();
        $this->enviar(['solicitante_erp_id' => $persona->id, 'nombre_completo' => 'Digitado_'.$this->marca]);
        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();

        $this->assertSame(
            [$solicitud->id],
            Solicitud::filtrarPorColumnas(['solicitante' => 'Digitado_'.$this->marca])->pluck('id')->all()
        );

        // El _ va escapado: no casa con otro caracter en esa posicion.
        $this->assertSame([], Solicitud::filtrarPorColumnas(['solicitante' => 'DigitadoX'.$this->marca])->pluck('id')->all());
    }

    /* --- Buscador publico ------------------------------------------------ */

    public function test_la_busqueda_no_expone_cedula_ni_correo(): void
    {
        $persona = $this->solicitante();

        $respuesta = $this->getJson(route('solicitantes.buscar', ['q' => $this->marca]));

        $respuesta->assertOk();
        $respuesta->assertJsonPath('datos.0.id', $persona->id);
        $respuesta->assertJsonPath('datos.0.nombre', $persona->col_nombre);
        $this->assertSame(['id', 'nombre', 'area'], array_keys($respuesta->json('datos.0')));
        $respuesta->assertDontSee($persona->col_cedula);
        $respuesta->assertDontSee($persona->col_correo);
    }

    public function test_la_busqueda_solo_devuelve_activos(): void
    {
        $activo = $this->solicitante();
        $this->solicitante(['col_activo' => false, 'col_nombre' => 'Inactivo '.$this->marca]);

        $ids = collect($this->getJson(route('solicitantes.buscar', ['q' => $this->marca]))->json('datos'))->pluck('id')->all();

        $this->assertSame([$activo->id], $ids);
    }

    public function test_la_busqueda_exige_minimo_dos_caracteres(): void
    {
        $this->solicitante();

        $this->getJson(route('solicitantes.buscar', ['q' => 'Z']))
            ->assertOk()
            ->assertExactJson(['datos' => []]);
    }

    public function test_la_busqueda_encuentra_por_palabras_en_cualquier_orden(): void
    {
        $persona = $this->solicitante(['col_nombre' => 'Juan '.$this->marca.' Perez']);

        $ids = collect($this->getJson(route('solicitantes.buscar', ['q' => 'perez '.$this->marca]))->json('datos'))->pluck('id');

        $this->assertTrue($ids->contains($persona->id));
    }

    /**
     * % y _ son comodines de LIKE: sin el escape de corchetes, "a_b" casaria
     * con "axb" y "%" con cualquier nombre.
     */
    public function test_la_busqueda_escapa_los_comodines_de_like(): void
    {
        $literal = $this->solicitante(['col_nombre' => $this->marca.'_ab']);
        $this->solicitante(['col_nombre' => $this->marca.'xab']);

        $ids = collect($this->getJson(route('solicitantes.buscar', ['q' => $this->marca.'_ab']))->json('datos'))->pluck('id')->all();
        $this->assertSame([$literal->id], $ids);

        $porcentaje = collect($this->getJson(route('solicitantes.buscar', ['q' => $this->marca.'%']))->json('datos'));
        $this->assertCount(0, $porcentaje);
    }

    public function test_la_busqueda_topa_los_resultados(): void
    {
        for ($i = 0; $i < SolicitanteErp::MAXIMO_RESULTADOS + 3; $i++) {
            $this->solicitante(['col_nombre' => "Tope {$this->marca} {$i}"]);
        }

        $this->assertCount(
            SolicitanteErp::MAXIMO_RESULTADOS,
            $this->getJson(route('solicitantes.buscar', ['q' => $this->marca]))->json('datos')
        );
    }

    /* --- Consulta publica ------------------------------------------------ */

    private function crearDe(SolicitanteErp $persona): Solicitud
    {
        $this->enviar(['solicitante_erp_id' => $persona->id])->assertRedirect();

        return Solicitud::orderByDesc('id')->firstOrFail();
    }

    /**
     * La solicitud nace por_aprobar: el almacen solo la despacha despues de
     * que el solicitante la aprueba.
     */
    private function aprobar(Solicitud $solicitud, SolicitanteErp $persona): Solicitud
    {
        return app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona);
    }

    public function test_consultar_con_numero_y_solicitante_correctos_muestra_la_solicitud(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->crearDe($persona);

        $respuesta = $this->get(route('solicitudes.consultar', [
            'numero' => $solicitud->numero,
            'solicitante' => $persona->id,
        ]));

        $respuesta->assertOk();
        $respuesta->assertViewHas('solicitud', fn ($vista) => $vista?->id === $solicitud->id);
        // La vista publica enmascara los datos que vienen del ERP.
        $respuesta->assertDontSee($persona->col_correo);
        $respuesta->assertDontSee($persona->col_cedula);
    }

    public function test_consultar_con_otro_solicitante_no_muestra_nada(): void
    {
        $solicitud = $this->crearDe($this->solicitante());
        $otro = $this->solicitante(['col_nombre' => 'Otra '.$this->marca]);

        $respuesta = $this->get(route('solicitudes.consultar', [
            'numero' => $solicitud->numero,
            'solicitante' => $otro->id,
        ]));

        $respuesta->assertOk();
        $respuesta->assertViewHas('solicitud', null);
        $respuesta->assertViewHas('noEncontrada', true);
    }

    public function test_consultar_con_un_solicitante_manipulado_no_muestra_nada(): void
    {
        $solicitud = $this->crearDe($this->solicitante());

        foreach (['999999999', 'abc', '1 or 1=1'] as $valor) {
            $this->get(route('solicitudes.consultar', ['numero' => $solicitud->numero, 'solicitante' => $valor]))
                ->assertOk()
                ->assertViewHas('noEncontrada', true);
        }
    }

    /**
     * Una solicitud con FK a otra persona no se casa por cedula aunque la
     * cedula coincida: la FK manda.
     */
    public function test_una_solicitud_con_fk_no_se_casa_por_cedula(): void
    {
        $duena = $this->solicitante();
        $solicitud = $this->crearDe($duena);
        $impostor = $this->solicitante(['col_cedula' => $duena->col_cedula]);

        $this->get(route('solicitudes.consultar', ['numero' => $solicitud->numero, 'solicitante' => $impostor->id]))
            ->assertViewHas('noEncontrada', true);
    }

    /* --- Aviso de pedido listo ------------------------------------------- */

    public function test_el_aviso_sale_al_correo_vigente_del_erp(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->aprobar($this->crearDe($persona), $persona);

        // El ERP corrige el correo despues de crear la solicitud.
        $persona->update(['col_correo' => 'corregido.'.uniqid().'@sidocsa.com']);

        app(SolicitudService::class)->marcarListo($solicitud->fresh(), $this->administrador(), []);

        Mail::assertSent(PedidoListoMail::class, fn (PedidoListoMail $correo) => $correo->hasTo($persona->col_correo));
        $this->assertNotNull($solicitud->fresh()->notificado_at);
    }

    public function test_sin_correo_en_el_erp_el_aviso_queda_registrado_como_fallido(): void
    {
        $persona = $this->solicitante(['col_correo' => null]);
        $solicitud = $this->aprobar($this->crearDe($persona), $persona);

        app(SolicitudService::class)->marcarListo($solicitud->fresh(), $this->administrador(), []);

        $fresca = $solicitud->fresh();
        Mail::assertNotSent(PedidoListoMail::class);
        $this->assertSame(Solicitud::ESTADO_LISTO, $fresca->estado);
        $this->assertNull($fresca->notificado_at);
        $this->assertStringContainsString('no tiene correo', (string) $fresca->error_notificacion);
    }
}
