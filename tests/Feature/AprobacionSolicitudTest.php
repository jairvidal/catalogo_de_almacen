<?php

namespace Tests\Feature;

use App\Mail\NuevaSolicitudMail;
use App\Mail\SolicitudAprobadaMail;
use App\Mail\SolicitudDenegadaMail;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\User;
use App\Services\SolicitudService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Flujo de aprobacion: la solicitud nace por_aprobar, el solicitante del ERP
 * la aprueba o la deniega desde su portal, y el almacen solo ve y despacha lo
 * aprobado.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase. Numeros de solicitud con marca no numerica para no entrar
 * en el MAX() del consecutivo.
 */
class AprobacionSolicitudTest extends TestCase
{
    use DatabaseTransactions;

    private string $marca;

    private ?Repuesto $repuesto = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Apr'.substr(uniqid(), -7);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function solicitante(array $datos = []): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create($datos + [
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Aprobador '.$this->marca,
            'col_cedula' => '300'.random_int(100000, 999999),
            'col_correo' => 'aprobador.'.uniqid().'@sidocsa.com',
            'col_area' => 'Mantenimiento',
            'col_activo' => true,
        ]);

        SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
            'col_password' => Hash::make('Clave2026abc'),
            'col_password_asignada_at' => now(),
        ])->save());

        return $solicitante;
    }

    private function repuesto(): Repuesto
    {
        return $this->repuesto ??= Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba aprobacion',
            'unidad_medida' => 'UND',
            'existencia' => 10,
            'stock_minimo' => 1,
            'stock_maximo' => 20,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * Solicitud de $persona con un item, sin pasar por el formulario.
     *
     * @param  array<string, mixed>  $extra  columnas no fillable (fechas de decision)
     */
    private function solicitudDe(SolicitanteErp $persona, string $estado = Solicitud::ESTADO_POR_APROBAR, array $extra = []): Solicitud
    {
        $solicitud = new Solicitud([
            'numero' => 'TST-'.$this->marca.'-'.substr(uniqid(), -5),
            'nombre_completo' => 'Digitado '.$this->marca,
            'solicitante_nombre' => $persona->col_nombre,
            'solicitante_cedula' => $persona->col_cedula,
            'solicitante_email' => $persona->col_correo,
            'observaciones' => 'OT-'.$this->marca,
        ]);
        $solicitud->forceFill(['solicitante_erp_id' => $persona->id, 'estado' => $estado] + $extra)->save();

        SolicitudItem::create([
            'solicitud_id' => $solicitud->id,
            'repuesto_id' => $this->repuesto()->id,
            'codigo' => $this->repuesto()->codigo,
            'nombre' => 'Item '.$this->marca,
            'cantidad_solicitada' => 2,
        ]);

        return $solicitud;
    }

    private function administrador(): User
    {
        return User::create([
            'name' => 'Admin aprobacion prueba',
            'email' => 'aprobacion.admin.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => Rol::where('col_clave', User::ROL_ADMIN)->value('id'),
            'activo' => true,
        ]);
    }

    /* --- Creacion ------------------------------------------------------- */

    public function test_la_solicitud_nueva_nace_por_aprobar_y_no_avisa_al_almacen(): void
    {
        config(['almacen.notificacion_email' => 'almacen.prueba@sidocsa.com']);
        $persona = $this->solicitante();

        $this->withSession(['carrito' => [$this->repuesto()->id => 1]])
            ->post(route('solicitudes.store'), [
                'nombre_completo' => 'Digitado '.$this->marca,
                'solicitante_erp_id' => $persona->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $solicitud = Solicitud::orderByDesc('id')->firstOrFail();

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->estado);
        $this->assertNull($solicitud->aprobada_at);
        Mail::assertNotSent(NuevaSolicitudMail::class);
    }

    /* --- Portal: listado y detalle ------------------------------------- */

    public function test_el_listado_muestra_solo_las_propias_con_las_por_aprobar_primero(): void
    {
        $persona = $this->solicitante();
        $otro = $this->solicitante();

        $entregada = $this->solicitudDe($persona, Solicitud::ESTADO_ENTREGADA);
        $porAprobar = $this->solicitudDe($persona);
        $ajena = $this->solicitudDe($otro);

        // Historica sin FK: se casa por la cedula del ERP, como en /consultar.
        $historica = Solicitud::create([
            'numero' => 'TST-'.$this->marca.'-H',
            'solicitante_nombre' => $persona->col_nombre,
            'solicitante_cedula' => $persona->col_cedula,
            'estado' => Solicitud::ESTADO_ENTREGADA,
        ]);

        $respuesta = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.index'))
            ->assertOk();

        $ids = $respuesta->viewData('solicitudes')->pluck('id')->all();

        $this->assertSame($porAprobar->id, $ids[0], 'La por aprobar va primero.');
        $this->assertEqualsCanonicalizing([$porAprobar->id, $entregada->id, $historica->id], $ids);
        $this->assertNotContains($ajena->id, $ids);
        $respuesta->assertViewHas('porAprobar', 1);
        $respuesta->assertSee('fila-por-aprobar', false);
        $respuesta->assertDontSee($ajena->numero);
    }

    public function test_el_filtro_por_aprobar_deja_solo_esas(): void
    {
        $persona = $this->solicitante();
        $this->solicitudDe($persona, Solicitud::ESTADO_ENTREGADA);
        $porAprobar = $this->solicitudDe($persona);

        $ids = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.index', ['filtro' => 'por_aprobar']))
            ->assertOk()
            ->viewData('solicitudes')->pluck('id')->all();

        $this->assertSame([$porAprobar->id], $ids);
    }

    public function test_el_detalle_muestra_la_solicitud_y_los_botones_si_esta_por_aprobar(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $respuesta = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk();

        $respuesta->assertSee($solicitud->numero);
        $respuesta->assertSee('Digitado '.$this->marca);
        $respuesta->assertSee('OT-'.$this->marca);
        $respuesta->assertSee('Item '.$this->marca);
        $respuesta->assertSee('action="'.route('solicitante.solicitudes.aprobar', $solicitud->id).'"', false);
        $respuesta->assertSee('action="'.route('solicitante.solicitudes.denegar', $solicitud->id).'"', false);
        // La cedula no se muestra completa.
        $respuesta->assertDontSee($persona->col_cedula);
    }

    public function test_el_detalle_sin_decision_pendiente_no_pinta_los_botones(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_PENDIENTE, ['aprobada_at' => now()]);

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertDontSee('id="form-aprobar"', false);
    }

    public function test_el_detalle_de_una_solicitud_ajena_es_404(): void
    {
        $ajena = $this->solicitudDe($this->solicitante());

        $this->actingAs($this->solicitante(), 'solicitante')
            ->get(route('solicitante.solicitudes.show', $ajena->id))
            ->assertNotFound();
    }

    public function test_el_invitado_va_al_login_del_portal(): void
    {
        $this->get(route('solicitante.solicitudes.index'))->assertRedirect(route('solicitante.login'));
    }

    /* --- Aprobar / denegar ---------------------------------------------- */

    public function test_aprobar_pasa_a_pendiente_y_envia_un_solo_correo(): void
    {
        config(['almacen.notificacion_email' => 'almacen.prueba@sidocsa.com']);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id))
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHas('exito');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertNotNull($fresca->aprobada_at);
        $this->assertNull($fresca->denegada_at);
        $this->assertNull($fresca->error_notificacion);

        Mail::assertSent(SolicitudAprobadaMail::class, 1);
        Mail::assertSent(SolicitudAprobadaMail::class, fn (SolicitudAprobadaMail $correo) => $correo->hasTo($persona->col_correo)
            && count($correo->to) === 1);
        // El aviso al almacen sale ahora, al aprobar.
        Mail::assertSent(NuevaSolicitudMail::class, fn (NuevaSolicitudMail $correo) => $correo->hasTo('almacen.prueba@sidocsa.com'));
    }

    public function test_el_correo_de_aprobado_menciona_a_quien_hizo_la_solicitud(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')->post(route('solicitante.solicitudes.aprobar', $solicitud->id));

        Mail::assertSent(SolicitudAprobadaMail::class, function (SolicitudAprobadaMail $correo) {
            $html = $correo->render();

            return str_contains($html, 'Digitado '.$this->marca) && str_contains($html, 'Aprobador '.$this->marca);
        });
    }

    public function test_denegar_pasa_a_rechazada_con_motivo_y_envia_el_correo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), ['motivo_denegacion' => 'Ya hay en la obra'])
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHas('exito');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_RECHAZADA, $fresca->estado);
        $this->assertNotNull($fresca->denegada_at);
        $this->assertNull($fresca->aprobada_at);
        $this->assertSame('Ya hay en la obra', $fresca->motivo_denegacion);
        $this->assertSame('Denegada', $fresca->estado_label);

        Mail::assertSent(SolicitudDenegadaMail::class, fn (SolicitudDenegadaMail $correo) => $correo->hasTo($persona->col_correo)
            && str_contains($correo->render(), 'Ya hay en la obra'));
        Mail::assertNotSent(NuevaSolicitudMail::class);
    }

    public function test_no_se_puede_aprobar_dos_veces(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')->post(route('solicitante.solicitudes.aprobar', $solicitud->id));

        $this->actingAs($persona, 'solicitante')
            ->from(route('solicitante.solicitudes.show', $solicitud->id))
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id))
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHas('error');

        // Y tampoco denegar despues de aprobar.
        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id))
            ->assertSessionHas('error');

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
        $this->assertNull($solicitud->fresh()->denegada_at);
        Mail::assertSent(SolicitudAprobadaMail::class, 1);
        Mail::assertNotSent(SolicitudDenegadaMail::class);
    }

    public function test_no_se_aprueba_una_que_no_esta_por_aprobar(): void
    {
        $persona = $this->solicitante();

        foreach ([Solicitud::ESTADO_PENDIENTE, Solicitud::ESTADO_EN_PROCESO, Solicitud::ESTADO_ENTREGADA] as $estado) {
            $solicitud = $this->solicitudDe($persona, $estado);

            $this->actingAs($persona, 'solicitante')
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id))
                ->assertSessionHas('error');

            $this->assertSame($estado, $solicitud->fresh()->estado);
            $this->assertNull($solicitud->fresh()->aprobada_at);
        }

        Mail::assertNothingSent();
    }

    public function test_no_se_decide_sobre_una_solicitud_ajena(): void
    {
        $ajena = $this->solicitudDe($this->solicitante());
        $intruso = $this->solicitante();

        $this->actingAs($intruso, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $ajena->id))
            ->assertNotFound();
        $this->actingAs($intruso, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $ajena->id))
            ->assertNotFound();

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $ajena->fresh()->estado);
        Mail::assertNothingSent();
    }

    public function test_un_fallo_de_smtp_no_tumba_la_aprobacion(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido de prueba'));

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id))
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHas('error');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertStringContainsString('SMTP caido de prueba', (string) $fresca->error_notificacion);
    }

    public function test_sin_correo_la_decision_se_guarda_y_el_fallo_queda_registrado(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $persona->update(['col_correo' => null]);
        $solicitud->forceFill(['solicitante_email' => null])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id));

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_RECHAZADA, $fresca->estado);
        $this->assertStringContainsString('no tiene correo', (string) $fresca->error_notificacion);
        Mail::assertNothingSent();
    }

    public function test_el_motivo_de_denegacion_no_supera_1000_caracteres(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), ['motivo_denegacion' => str_repeat('x', 1001)])
            ->assertSessionHasErrors('motivo_denegacion');

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);
    }

    /* --- El almacen no ve ni despacha lo que no se aprobo --------------- */

    public function test_la_bandeja_del_almacen_no_muestra_por_aprobar_ni_denegadas(): void
    {
        $persona = $this->solicitante();
        $porAprobar = $this->solicitudDe($persona);
        $denegada = $this->solicitudDe($persona, Solicitud::ESTADO_RECHAZADA, ['denegada_at' => now()]);
        $aprobada = $this->solicitudDe($persona, Solicitud::ESTADO_PENDIENTE, ['aprobada_at' => now()]);

        $respuesta = $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.index', ['solicitante' => 'Aprobador '.$this->marca]))
            ->assertOk();

        $ids = $respuesta->viewData('solicitudes')->pluck('id')->all();
        $this->assertSame([$aprobada->id], $ids);
        $this->assertNotContains($porAprobar->id, $ids);
        $this->assertNotContains($denegada->id, $ids);

        // El select de estado no ofrece "Por aprobar" y el parametro se ignora.
        $respuesta->assertDontSee('value="por_aprobar"', false);
        $this->actingAs($this->administrador())
            ->get(route('admin.solicitudes.index', ['estado' => 'por_aprobar', 'solicitante' => 'Aprobador '.$this->marca]))
            ->assertViewHas('estadoActivo', '')
            ->assertViewHas('solicitudes', fn ($pagina) => $pagina->pluck('id')->all() === [$aprobada->id]);
    }

    public function test_el_almacen_no_abre_ni_despacha_una_solicitud_por_aprobar(): void
    {
        $solicitud = $this->solicitudDe($this->solicitante());
        $admin = $this->administrador();
        $existenciaAntes = (float) $this->repuesto()->fresh()->existencia;

        $this->actingAs($admin)->get(route('admin.solicitudes.show', $solicitud))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.solicitudes.tomar', $solicitud))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.solicitudes.listo', $solicitud))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.solicitudes.entregar', $solicitud))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.solicitudes.rechazar', $solicitud), ['nota_almacen' => 'x'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.solicitudes.reenviar', $solicitud))->assertNotFound();

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);
        $this->assertEquals($existenciaAntes, (float) $this->repuesto()->fresh()->existencia);
    }

    public function test_el_servicio_tampoco_despacha_lo_que_no_se_aprobo(): void
    {
        $solicitud = $this->solicitudDe($this->solicitante());

        $this->expectException(\LogicException::class);

        app(SolicitudService::class)->marcarListo($solicitud, $this->administrador(), []);
    }

    public function test_despues_de_aprobar_el_almacen_la_atiende(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $this->actingAs($persona, 'solicitante')->post(route('solicitante.solicitudes.aprobar', $solicitud->id));

        $admin = $this->administrador();

        // Guard explicito: actingAs(..., 'solicitante') dejo ese guard por defecto.
        $this->actingAs($admin, 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('Aprobada por el solicitante');

        $this->actingAs($admin, 'web')->post(route('admin.solicitudes.tomar', $solicitud))->assertSessionHas('exito');
        $this->assertSame(Solicitud::ESTADO_EN_PROCESO, $solicitud->fresh()->estado);
    }

    /* --- Vistas publicas ------------------------------------------------ */

    /**
     * La confirmacion usa el mismo partial de detalle que /consultar y el
     * portal. (/consultar no sirve aqui: solo busca numeros de seis digitos y
     * los de prueba llevan una marca no numerica a proposito.)
     */
    public function test_la_confirmacion_publica_dice_que_espera_la_aprobacion(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->withSession(['solicitud_recien_creada' => $solicitud->numero])
            ->get(route('solicitudes.confirmacion', $solicitud->numero))
            ->assertOk()
            ->assertSee('Por aprobar')
            ->assertSee('Ahora espera la aprobacion de', false)
            ->assertSee('espera la aprobacion de', false)
            ->assertDontSee('El almacen ya la recibio');
    }

    public function test_el_detalle_muestra_la_denegacion_y_su_motivo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_RECHAZADA, [
            'denegada_at' => now(),
            'motivo_denegacion' => 'Motivo '.$this->marca,
        ]);

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee('Denegada')
            ->assertSee('Motivo '.$this->marca)
            ->assertDontSee('Listo para reclamar')
            ->assertDontSee('id="form-aprobar"', false);
    }
}
