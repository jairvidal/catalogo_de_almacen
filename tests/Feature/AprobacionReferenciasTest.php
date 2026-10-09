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
 * APROBAR: cod_referencia, cod_OC, dias_entrega y los codigos de las
 * maestras del ERP (centro de operacion, centro de costo, proyecto).
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
            'cod_referencia' => '  REF-'.$this->marca.'  ',
            'cod_oc' => 'OC-'.$this->marca,
            'dias_entrega' => ' 07 ',
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

        foreach (['cod_referencia', 'cod_oc', 'dias_entrega', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto'] as $campo) {
            $respuesta->assertSee('name="'.$campo.'" form="form-aprobar"', false);
        }

        $respuesta->assertSee('inputmode="numeric"', false);
        $respuesta->assertSee('data-combo-maestra', false);
        $respuesta->assertSee('value="'.$activa.'"', false);
        $respuesta->assertSee($activa.' - Maestra '.$this->marca.' '.$activa);
        $respuesta->assertDontSee('value="'.$inactiva.'"', false);
        // Enter en un cuadro no aprueba: boton por defecto deshabilitado.
        $respuesta->assertSee('<button type="submit" disabled hidden', false);
    }

    /* --- Aprobar -------------------------------------------------------- */

    public function test_aprobar_guarda_los_seis_campos(): void
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
        $this->assertSame('REF-'.$this->marca, $fresca->cod_referencia, 'Se recorta.');
        $this->assertSame('OC-'.$this->marca, $fresca->cod_OC, 'cod_oc del formulario va a cod_OC.');
        $this->assertSame(7, $fresca->dias_entrega);
        $this->assertSame($datos['cod_centro_operacion'], $fresca->cod_centro_operacion);
        $this->assertSame($datos['cod_centro_de_costo'], $fresca->cod_centro_de_costo);
        $this->assertSame($datos['cod_proyecto'], $fresca->cod_proyecto);
    }

    public function test_aprobar_sin_diligenciar_deja_las_referencias_en_null(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'cod_referencia' => '   ',
                'dias_entrega' => '',
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
            'dias no numerico' => ['dias_entrega' => '5 dias'],
            'dias decimal' => ['dias_entrega' => '2.5'],
            'dias negativo' => ['dias_entrega' => '-1'],
            'dias excesivo' => ['dias_entrega' => '3651'],
            'referencia larga' => ['cod_referencia' => str_repeat('x', 51)],
            'oc larga' => ['cod_oc' => str_repeat('x', 51)],
            'arreglo' => ['cod_referencia' => ['x']],
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
                'cod_referencia' => 'REF-'.$this->marca,
                'dias_entrega' => 'abc',
            ]);

        $this->actingAs($persona, 'solicitante')
            ->get($ruta)
            ->assertOk()
            ->assertSee('value="REF-'.$this->marca.'"', false)
            ->assertSee('id="dias_entrega-error"', false)
            ->assertSee('Los dias de entrega deben ser un numero entero');
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
            'cod_referencia' => 'ORIGINAL',
        ]);

        $this->actingAs($persona, 'solicitante')
            ->from(route('solicitante.solicitudes.show', $solicitud->id))
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $this->datosValidos())
            ->assertSessionHas('error');

        $referencias = $this->referenciasDe($solicitud);
        $this->assertSame('ORIGINAL', $referencias['cod_referencia']);
        $this->assertNull($referencias['cod_OC']);
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
        $solicitud = $this->solicitudDe($persona);
        $datos = $this->datosValidos();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $datos);

        $nombreCentro = 'Maestra '.$this->marca.' '.$datos['cod_centro_operacion'];

        $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
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
            ->assertDontSee('Centros y proyecto');
    }
}
