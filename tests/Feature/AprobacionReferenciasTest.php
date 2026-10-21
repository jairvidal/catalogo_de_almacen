<?php

namespace Tests\Feature;

use App\Exceptions\DecisionSolicitudException;
use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Proyecto;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\User;
use App\Services\AprobacionSolicitudService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Columnas de referencia que el solicitante diligencia en el encabezado al
 * APROBAR: cod_OC y los codigos de las maestras del ERP (centro de
 * operacion, centro de costo, proyecto). cod_referencia y dias_entrega se
 * retiraron en V 1.4.25: ya no se aceptan, pero lo que ya tengan se muestra.
 *
 * Corre contra la base de .env: DatabaseTransactions. Las maestras ya tienen
 * filas reales, asi que los codigos de prueba se generan y no se asumen libres.
 */
class AprobacionReferenciasTest extends TestCase
{
    use DatabaseTransactions;

    private string $marca;

    private ?Repuesto $repuesto = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Ref'.substr(uniqid(), -7);
    }

    /* --- Ayudantes ------------------------------------------------------ */

    private function solicitante(): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create([
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Aprobador '.$this->marca,
            'col_cedula' => '301'.random_int(100000, 999999),
            'col_correo' => 'referencias.'.uniqid().'@sidocsa.com',
            'col_activo' => true,
        ]);

        SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
            'col_password' => Hash::make('Clave2026abc'),
            'col_password_asignada_at' => now(),
        ])->save());

        return $solicitante;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function solicitudDe(SolicitanteErp $persona, string $estado = Solicitud::ESTADO_POR_APROBAR, array $extra = []): Solicitud
    {
        $this->repuesto ??= Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba referencias',
            'unidad_medida' => 'UND',
            'existencia' => 10,
            'stock_minimo' => 1,
            'stock_maximo' => 20,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);

        $solicitud = new Solicitud([
            'numero' => 'TST-'.$this->marca.'-'.substr(uniqid(), -5),
            'solicitante_nombre' => $persona->col_nombre,
            'solicitante_cedula' => $persona->col_cedula,
            'solicitante_email' => $persona->col_correo,
        ]);
        $solicitud->forceFill(['solicitante_erp_id' => $persona->col_codigo_erp, 'estado' => $estado] + $extra)->save();

        SolicitudItem::create([
            'solicitud_id' => $solicitud->id,
            'repuesto_id' => $this->repuesto->id,
            'codigo' => $this->repuesto->codigo,
            'nombre' => 'Item '.$this->marca,
            'cantidad_solicitada' => 1,
        ]);

        return $solicitud;
    }

    /** Codigo varchar(10) que no existe en la maestra (tiene datos reales). */
    private function codigoLibre(string $modelo): string
    {
        do {
            $codigo = 'Z'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
        } while ($modelo::query()->whereKey($codigo)->exists());

        return $codigo;
    }

    /**
     * @param  class-string<CentroOperacion|CentroDeCosto|Proyecto>  $modelo
     */
    private function maestra(string $modelo, bool $activa = true): string
    {
        $codigo = $this->codigoLibre($modelo);

        $modelo::create([
            (new $modelo)->getKeyName() => $codigo,
            'col_nombre' => 'Maestra '.$this->marca.' '.$codigo,
            'col_activo' => $activa,
        ]);

        return $codigo;
    }

    /**
     * @return array<string, string>
     */
    private function datosValidos(): array
    {
        return [
            'cod_oc' => '  OC-'.$this->marca.'  ',
            'cod_centro_operacion' => $this->maestra(CentroOperacion::class),
            'cod_centro_de_costo' => $this->maestra(CentroDeCosto::class),
            'cod_proyecto' => $this->maestra(Proyecto::class),
        ];
    }

    private function referenciasDe(Solicitud $solicitud): array
    {
        $fresca = $solicitud->fresh();

        return collect(array_keys(Solicitud::REFERENCIAS_APROBACION))
            ->mapWithKeys(fn ($columna) => [$columna => $fresca->getAttribute($columna)])
            ->all();
    }

    private function sinReferencias(): array
    {
        return array_fill_keys(array_keys(Solicitud::REFERENCIAS_APROBACION), null);
    }

    /* --- Formulario ----------------------------------------------------- */

    public function test_el_detalle_por_aprobar_pinta_los_cuadros_y_las_listas_solo_con_activas(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $activa = $this->maestra(CentroOperacion::class);
        $inactiva = $this->maestra(CentroOperacion::class, false);

        $respuesta = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk();

        foreach (['cod_oc', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto'] as $campo) {
            $respuesta->assertSee('name="'.$campo.'" form="form-aprobar"', false);
        }

        // Retirados en V 1.4.25.
        $respuesta->assertDontSee('name="cod_referencia"', false);
        $respuesta->assertDontSee('name="dias_entrega"', false);
        $respuesta->assertDontSee('Cod. referencia');
        $respuesta->assertDontSee('Dias de entrega');
        $respuesta->assertSee('data-combo-maestra', false);
        $respuesta->assertSee('value="'.$activa.'"', false);
        $respuesta->assertSee($activa.' - Maestra '.$this->marca.' '.$activa);
        $respuesta->assertDontSee('value="'.$inactiva.'"', false);
        // Enter en un cuadro no aprueba: boton por defecto deshabilitado.
        $respuesta->assertSee('<button type="submit" disabled hidden', false);
    }

    public function test_el_detalle_por_aprobar_lleva_oculto_el_codigo_erp_del_solicitante(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee('<input type="hidden" id="cod_solicitante" value="'.e($persona->col_codigo_erp).'">', false);

        // Ya decidida no hay formulario: el campo no se pinta.
        $solicitud->forceFill(['estado' => Solicitud::ESTADO_PENDIENTE, 'aprobada_at' => now()])->save();

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertDontSee('id="cod_solicitante"', false);
    }

    /* --- Observaciones (V 1.4.36) -------------------------------------- */

    public function test_el_portal_por_aprobar_pinta_observaciones_editables_y_sin_el_aviso_de_espera(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $solicitud->forceFill(['observaciones' => 'Texto original '.$this->marca])->save();

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee('<textarea id="observaciones" name="observaciones" form="form-aprobar"', false)
            ->assertSee('Texto original '.$this->marca)
            ->assertDontSee('Esta solicitud espera la aprobacion de', false)
            ->assertDontSee('<strong>Observaciones:</strong>', false);
    }

    public function test_aprobar_guarda_las_observaciones_editadas(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $solicitud->forceFill(['observaciones' => 'Original'])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['observaciones' => '  Ajustada  '])
            ->assertRedirect();

        $this->assertSame('Ajustada', $solicitud->fresh()->observaciones);
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
    }

    public function test_aprobar_con_observaciones_vacias_las_borra_y_sin_el_campo_las_conserva(): void
    {
        $persona = $this->solicitante();
        $vaciada = $this->solicitudDe($persona);
        $vaciada->forceFill(['observaciones' => 'Original'])->save();
        $sinCampo = $this->solicitudDe($persona);
        $sinCampo->forceFill(['observaciones' => 'Se queda'])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $vaciada->id), ['observaciones' => '   ']);
        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $sinCampo->id), []);

        $this->assertNull($vaciada->fresh()->observaciones);
        $this->assertSame('Se queda', $sinCampo->fresh()->observaciones);
    }

    public function test_observaciones_de_mas_de_1000_no_aprueban_y_denegar_no_las_escribe(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $solicitud->forceFill(['observaciones' => 'Original'])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['observaciones' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('observaciones');
        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), ['observaciones' => 'Cambiada']);

        $this->assertSame('Original', $solicitud->fresh()->observaciones);
    }

    /* --- Aprobar -------------------------------------------------------- */

    public function test_la_lista_blanca_ya_no_incluye_referencia_ni_dias_pero_la_lectura_si(): void
    {
        $this->assertSame(
            ['cod_OC', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto'],
            array_keys(Solicitud::REFERENCIAS_APROBACION)
        );

        // Todo lo que se diligencia tambien se muestra, con la misma etiqueta.
        foreach (Solicitud::REFERENCIAS_APROBACION as $columna => $etiqueta) {
            $this->assertSame($etiqueta, Solicitud::REFERENCIAS_PARA_MOSTRAR[$columna] ?? null, $columna);
        }

        $this->assertArrayHasKey('cod_referencia', Solicitud::REFERENCIAS_PARA_MOSTRAR);
        $this->assertArrayHasKey('dias_entrega', Solicitud::REFERENCIAS_PARA_MOSTRAR);
    }

    public function test_aprobar_guarda_los_cuatro_campos(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $datos = $this->datosValidos();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $datos)
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertSame('OC-'.$this->marca, $fresca->cod_OC, 'Se recorta y cod_oc del formulario va a cod_OC.');
        $this->assertSame($datos['cod_centro_operacion'], $fresca->cod_centro_operacion);
        $this->assertSame($datos['cod_centro_de_costo'], $fresca->cod_centro_de_costo);
        $this->assertSame($datos['cod_proyecto'], $fresca->cod_proyecto);
    }

    public function test_un_request_armado_a_mano_no_escribe_referencia_ni_dias(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        // Tampoco los valida: un valor que antes se rechazaba ya no frena.
        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'cod_referencia' => 'REF-'.$this->marca,
                'dias_entrega' => 'abc',
                'cod_oc' => 'OC-'.$this->marca,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertSame('OC-'.$this->marca, $fresca->cod_OC);
        $this->assertNull($fresca->cod_referencia);
        $this->assertNull($fresca->dias_entrega);
    }

    public function test_el_servicio_tampoco_escribe_referencia_ni_dias_y_conserva_lo_anterior(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_POR_APROBAR, [
            'cod_referencia' => 'HISTORICA',
            'dias_entrega' => 5,
        ]);

        app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [
            'cod_referencia' => 'NUEVA',
            'dias_entrega' => 9,
        ]);

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertSame('HISTORICA', $fresca->cod_referencia);
        $this->assertSame(5, $fresca->dias_entrega);
    }

    public function test_aprobar_sin_diligenciar_deja_las_referencias_en_null(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'cod_oc' => '   ',
                'cod_centro_operacion' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
        $this->assertSame($this->sinReferencias(), $this->referenciasDe($solicitud));
    }

    public function test_valores_invalidos_se_rechazan_y_no_aprueban(): void
    {
        $persona = $this->solicitante();
        $inactivo = $this->maestra(CentroDeCosto::class, false);

        $casos = [
            'codigo inexistente' => ['cod_centro_operacion' => $this->codigoLibre(CentroOperacion::class)],
            'codigo inactivo' => ['cod_centro_de_costo' => $inactivo],
            'proyecto inexistente' => ['cod_proyecto' => $this->codigoLibre(Proyecto::class)],
            'oc larga' => ['cod_oc' => str_repeat('x', 51)],
            'arreglo' => ['cod_oc' => ['x']],
        ];

        foreach ($casos as $caso => $datos) {
            $solicitud = $this->solicitudDe($persona);
            $campo = array_key_first($datos);

            $this->actingAs($persona, 'solicitante')
                ->from(route('solicitante.solicitudes.show', $solicitud->id))
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $datos + ['cod_oc' => 'OC-'.$this->marca])
                ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
                ->assertSessionHasErrors($campo);

            $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado, "Caso {$caso}: no debe aprobar.");
            $this->assertSame($this->sinReferencias(), $this->referenciasDe($solicitud), "Caso {$caso}: no escribe nada.");
        }

        Mail::assertNothingSent();
    }

    public function test_el_error_de_validacion_vuelve_con_los_valores_y_el_mensaje_bajo_el_campo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $ruta = route('solicitante.solicitudes.show', $solicitud->id);

        $this->actingAs($persona, 'solicitante')
            ->from($ruta)
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'cod_oc' => 'OC-'.$this->marca,
                'cod_proyecto' => $this->codigoLibre(Proyecto::class),
            ]);

        $this->actingAs($persona, 'solicitante')
            ->get($ruta)
            ->assertOk()
            ->assertSee('value="OC-'.$this->marca.'"', false)
            ->assertSee('El proyecto elegido no esta disponible');
    }

    public function test_denegar_no_escribe_las_referencias(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), $this->datosValidos() + ['motivo_denegacion' => 'No'])
            ->assertSessionHas('exito');

        $this->assertSame(Solicitud::ESTADO_RECHAZADA, $solicitud->fresh()->estado);
        $this->assertSame($this->sinReferencias(), $this->referenciasDe($solicitud));
    }

    public function test_no_se_aceptan_si_ya_no_esta_por_aprobar(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_PENDIENTE, [
            'aprobada_at' => now(),
            'cod_OC' => 'ORIGINAL',
        ]);

        $this->actingAs($persona, 'solicitante')
            ->from(route('solicitante.solicitudes.show', $solicitud->id))
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $this->datosValidos())
            ->assertSessionHas('error');

        $referencias = $this->referenciasDe($solicitud);
        $this->assertSame('ORIGINAL', $referencias['cod_OC']);
        $this->assertNull($referencias['cod_centro_operacion']);
    }

    public function test_sobre_una_solicitud_ajena_es_404_y_no_escribe(): void
    {
        $ajena = $this->solicitudDe($this->solicitante());

        $this->actingAs($this->solicitante(), 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $ajena->id), $this->datosValidos())
            ->assertNotFound();

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $ajena->fresh()->estado);
        $this->assertSame($this->sinReferencias(), $this->referenciasDe($ajena));
    }

    /* --- Servicio ------------------------------------------------------- */

    public function test_el_servicio_relee_la_maestra_y_revierte_si_se_inactivo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $codigo = $this->maestra(Proyecto::class);

        // Paso la validacion y luego la maestra se inactivo.
        Proyecto::whereKey($codigo)->update(['col_activo' => false]);

        try {
            app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [
                'cod_referencia' => 'REF',
                'cod_proyecto' => $codigo,
            ]);
            $this->fail('Debio lanzar DecisionSolicitudException.');
        } catch (DecisionSolicitudException $e) {
            $this->assertStringContainsString($codigo, $e->getMessage());
        }

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);
        $this->assertSame($this->sinReferencias(), $this->referenciasDe($solicitud));
    }

    public function test_el_servicio_ignora_columnas_fuera_de_la_lista_blanca(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [
            'cod_OC' => 'OC-1',
            'enviado' => 'no debe escribirse',
            'estado' => Solicitud::ESTADO_ENTREGADA,
        ]);

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertSame('OC-1', $fresca->cod_OC);
        $this->assertNull($fresca->enviado);
    }

    /* --- Solo lectura --------------------------------------------------- */

    public function test_despues_de_aprobar_se_muestran_en_solo_lectura_en_el_portal_y_el_panel(): void
    {
        $persona = $this->solicitante();
        // Valores historicos de las dos columnas retiradas del formulario:
        // aprobar no los toca y la lectura los sigue mostrando.
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_POR_APROBAR, [
            'cod_referencia' => 'REF-'.$this->marca,
            'dias_entrega' => 12,
        ]);
        $datos = $this->datosValidos();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $datos);

        $nombreCentro = 'Maestra '.$this->marca.' '.$datos['cod_centro_operacion'];

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee('Cod. referencia')
            ->assertSee('REF-'.$this->marca)
            ->assertSee('OC-'.$this->marca)
            ->assertSee($datos['cod_centro_operacion'].' - '.$nombreCentro)
            ->assertDontSee('name="cod_referencia"', false)
            ->assertDontSee('data-combo-maestra', false);

        $admin = User::create([
            'name' => 'Admin referencias prueba',
            'email' => 'referencias.admin.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => Rol::where('col_clave', User::ROL_ADMIN)->value('id'),
            'activo' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('Cod. OC')
            ->assertSee('OC-'.$this->marca)
            ->assertSee('Dias de entrega')
            ->assertSee('REF-'.$this->marca)
            ->assertSee($datos['cod_proyecto'].' - Maestra '.$this->marca);
    }

    public function test_sin_referencias_no_se_pintan_renglones_vacios(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_PENDIENTE, ['aprobada_at' => now()]);

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertDontSee('Cod. referencia')
            ->assertDontSee('Dias de entrega')
            ->assertDontSee('Centros y proyecto');
    }
}
