<?php

namespace Tests\Feature;

use App\Models\Funcionalidad;
use App\Models\Parametro;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\User;
use App\Services\IntegracionErpService;
use Database\Seeders\FuncionalidadSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Boton "Reenviar al ERP" del panel (V 1.4.41): regla
 * Solicitud::puedeReenviarseAlErp(), IntegracionErpService::reenviar(),
 * permiso solicitudes,editar y lo que pinta admin/solicitudes/show.
 *
 * Corre contra la base de .env: DatabaseTransactions. Todo envio va contra
 * Http::fake().
 */
class ReenvioErpTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'https://erp.prueba.local/api/reenvio';

    private const PLANTILLA = '{"tipo_documento": "RQF", "numero": "adicionar_dato", "notas": "adicionar_dato"}';

    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(FuncionalidadSeeder::class);
        $this->marca = 'Ree'.substr(uniqid(), -7);

        foreach ([Parametro::ERP_IMPORTACION_URL => self::URL, Parametro::ERP_IMPORTACION_JSON => self::PLANTILLA] as $nombre => $valor) {
            DB::table('tbl_parametro')->where('col_nombre', $nombre)->update([
                'col_valor' => $valor,
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ]);
        }

        DB::table('tbl_solicitudes')->whereNotNull('erp_numero')->update(['erp_numero' => null]);
        DB::table('tbl_consecutivo')->where('col_nombre', IntegracionErpService::CONSECUTIVO)->update(['col_ultimo' => 0]);
    }

    /* --- Ayudantes ------------------------------------------------------ */

    private function usuario(Rol $rol): User
    {
        return User::create([
            'name' => 'Usuario reenvio '.$this->marca,
            'email' => 'reenvio.'.uniqid().'@sidocsa.com',
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

    /** Perfil con la matriz guardada: solicitudes solo para ver. */
    private function soloVer(): User
    {
        $rol = Rol::create([
            'col_clave' => 'reenv_'.substr(uniqid(), -8),
            'col_nombre' => 'Perfil solo ver',
            'col_gestiona_catalogo' => false,
            'col_activo' => true,
        ]);

        $funcionalidad = (int) DB::table('tbl_funcionalidad')->where('col_clave', Funcionalidad::SOLICITUDES)->value('id');
        DB::insert(
            'insert into tbl_rol_funcionalidad (col_rol_id, col_funcionalidad_id, col_ver, col_editar, col_eliminar) values (?, ?, 1, 0, 0)',
            [$rol->id, $funcionalidad]
        );

        return $this->usuario($rol);
    }

    private function solicitante(): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create([
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Aprobador '.$this->marca,
            'col_cedula' => '303'.random_int(100000, 999999),
            'col_correo' => 'reenvio.'.uniqid().'@sidocsa.com',
            'col_activo' => true,
        ]);

        SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
            'col_password' => Hash::make('Clave2026abc'),
            'col_password_asignada_at' => now(),
        ])->save());

        return $solicitante;
    }

    /**
     * Solicitud con un item, en el estado y con las marcas que se pidan.
     *
     * @param  array<string, mixed>  $columnas
     */
    private function solicitud(array $columnas = []): Solicitud
    {
        $persona = $this->solicitante();
        $repuesto = Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto reenvio',
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
            'observaciones' => 'Obs '.$this->marca,
        ]);
        $solicitud->forceFill($columnas + [
            'solicitante_erp_id' => $persona->col_codigo_erp,
            'estado' => Solicitud::ESTADO_PENDIENTE,
            'aprobada_at' => now(),
        ])->save();

        SolicitudItem::create([
            'solicitud_id' => $solicitud->id,
            'repuesto_id' => $repuesto->id,
            'codigo' => $repuesto->codigo,
            'nombre' => 'Item '.$this->marca,
            'cantidad_solicitada' => 1,
        ]);

        return $solicitud;
    }

    private function reenviar(User $usuario, Solicitud $solicitud): TestResponse
    {
        return $this->actingAs($usuario, 'web')
            ->from(route('admin.solicitudes.show', $solicitud))
            ->post(route('admin.solicitudes.reenviar-erp', $solicitud));
    }

    /* --- Regla del modelo ------------------------------------------------ */

    public function test_la_regla_del_modelo(): void
    {
        $base = fn (array $columnas) => (new Solicitud)->forceFill($columnas + [
            'estado' => Solicitud::ESTADO_PENDIENTE,
            'aprobada_at' => now(),
        ]);

        $this->assertTrue($base([])->puedeReenviarseAlErp());
        $this->assertTrue($base(['estado' => Solicitud::ESTADO_LISTO])->puedeReenviarseAlErp());
        $this->assertFalse($base(['erp_enviado_at' => now()])->puedeReenviarseAlErp(), 'Ya enviada.');
        $this->assertFalse($base(['aprobada_at' => null])->puedeReenviarseAlErp(), 'Historica sin aprobacion.');
        $this->assertFalse($base(['aprobada_at' => null, 'estado' => Solicitud::ESTADO_POR_APROBAR])->puedeReenviarseAlErp());
        $this->assertFalse($base(['aprobada_at' => null, 'denegada_at' => now(), 'estado' => Solicitud::ESTADO_RECHAZADA])->puedeReenviarseAlErp(), 'Denegada.');
        $this->assertFalse($base(['estado' => Solicitud::ESTADO_RECHAZADA])->puedeReenviarseAlErp(), 'Rechazada por el almacen.');
    }

    /* --- Reenvio ---------------------------------------------------------- */

    public function test_el_reenvio_exitoso_reutiliza_el_numero_y_marca_enviada(): void
    {
        Http::fakeSequence(self::URL)->push('', 500)->push('', 200);
        $solicitud = $this->solicitud();

        // Primer intento (el de la aprobacion) falla y deja el numero reservado.
        $this->assertFalse(app(IntegracionErpService::class)->integracionERP($solicitud));
        $this->assertSame(1, $solicitud->fresh()->erp_numero);
        $this->assertNotNull($solicitud->fresh()->erp_error);

        $this->reenviar($this->admin(), $solicitud)
            ->assertRedirect(route('admin.solicitudes.show', $solicitud))
            ->assertSessionHas('exito', "Solicitud {$solicitud->numero} enviada al ERP (documento 1).");

        $numeros = Http::recorded()->map(fn (array $par) => json_decode($par[0]->body(), true)['numero'])->all();
        $this->assertSame(['1', '1'], $numeros, 'El reenvio manda el mismo numero.');
        $this->assertSame(1, (int) DB::table('tbl_consecutivo')->where('col_nombre', IntegracionErpService::CONSECUTIVO)->value('col_ultimo'));

        $fresca = $solicitud->fresh();
        $this->assertNotNull($fresca->erp_enviado_at);
        $this->assertNull($fresca->erp_error);
    }

    public function test_el_reenvio_fallido_deja_el_motivo_sin_prefijo(): void
    {
        Http::fake([self::URL => Http::response('<html>caido</html>', 503)]);
        $solicitud = $this->solicitud();

        $this->reenviar($this->admin(), $solicitud)
            ->assertSessionHas('error', "No se pudo enviar la solicitud {$solicitud->numero} al ERP: el servicio web del ERP respondio HTTP 503.");

        $fresca = $solicitud->fresh();
        $this->assertNull($fresca->erp_enviado_at);
        $this->assertSame(IntegracionErpService::PREFIJO_ERROR.'el servicio web del ERP respondio HTTP 503.', $fresca->erp_error);
        $this->assertSame(1, $fresca->erp_numero);
    }

    public function test_no_se_reenvia_si_ya_fue_enviada(): void
    {
        Http::fake();
        $solicitud = $this->solicitud(['erp_enviado_at' => now(), 'erp_numero' => 7]);

        $this->reenviar($this->admin(), $solicitud)
            ->assertSessionHas('error', "La solicitud {$solicitud->numero} ya fue enviada al ERP (documento 7).");

        Http::assertNothingSent();
    }

    public function test_no_se_reenvia_una_historica_sin_aprobacion(): void
    {
        Http::fake();
        $solicitud = $this->solicitud(['aprobada_at' => null]);

        $this->reenviar($this->admin(), $solicitud)->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertNull($solicitud->fresh()->erp_numero);
    }

    public function test_no_se_reenvia_una_rechazada_por_el_almacen(): void
    {
        Http::fake();
        $solicitud = $this->solicitud(['estado' => Solicitud::ESTADO_RECHAZADA]);

        $this->reenviar($this->admin(), $solicitud)->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_una_denegada_responde_404(): void
    {
        Http::fake();
        $solicitud = $this->solicitud([
            'estado' => Solicitud::ESTADO_RECHAZADA,
            'aprobada_at' => null,
            'denegada_at' => now(),
        ]);

        $this->reenviar($this->admin(), $solicitud)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_sin_permiso_de_editar_recibe_403(): void
    {
        Http::fake();
        $solicitud = $this->solicitud();

        $this->reenviar($this->soloVer(), $solicitud)->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($solicitud->fresh()->erp_numero);
    }

    public function test_con_un_envio_en_curso_no_se_manda_otro(): void
    {
        Http::fake();
        $solicitud = $this->solicitud();
        $candado = Cache::lock(IntegracionErpService::CANDADO.$solicitud->id, 60);
        $this->assertTrue($candado->get());

        try {
            $this->reenviar($this->admin(), $solicitud)
                ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'en curso'));

            // La aprobacion tampoco manda un segundo documento mientras tanto.
            $this->assertFalse(app(IntegracionErpService::class)->integracionERP($solicitud));
        } finally {
            $candado->release();
        }

        Http::assertNothingSent();
        $this->assertNull($solicitud->fresh()->erp_numero);
        $this->assertNull($solicitud->fresh()->erp_error, 'Omitir por candado no es un fallo del ERP.');
    }

    /* --- Vista ------------------------------------------------------------ */

    public function test_el_boton_aparece_si_no_fue_enviada(): void
    {
        $solicitud = $this->solicitud(['erp_error' => IntegracionErpService::PREFIJO_ERROR.'motivo de prueba '.$this->marca]);

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('data-estado-erp="no-enviada"', false)
            ->assertSee(route('admin.solicitudes.reenviar-erp', $solicitud), false)
            ->assertSee('motivo de prueba '.$this->marca)
            ->assertDontSee(IntegracionErpService::PREFIJO_ERROR.'motivo de prueba', false);
    }

    public function test_el_boton_no_aparece_si_ya_fue_enviada(): void
    {
        $solicitud = $this->solicitud(['erp_enviado_at' => now(), 'erp_numero' => 42]);

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('data-estado-erp="enviada"', false)
            ->assertSee('42')
            ->assertDontSee(route('admin.solicitudes.reenviar-erp', $solicitud), false);
    }

    public function test_sin_aprobacion_no_se_pinta_la_integracion(): void
    {
        $solicitud = $this->solicitud(['aprobada_at' => null]);

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertDontSee('data-estado-erp', false)
            ->assertDontSee(route('admin.solicitudes.reenviar-erp', $solicitud), false);
    }

    public function test_rechazada_por_el_almacen_no_ofrece_el_boton(): void
    {
        $solicitud = $this->solicitud(['estado' => Solicitud::ESTADO_RECHAZADA]);

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('data-estado-erp="no-enviada"', false)
            ->assertDontSee(route('admin.solicitudes.reenviar-erp', $solicitud), false);
    }

    public function test_sin_permiso_el_boton_sale_deshabilitado(): void
    {
        $solicitud = $this->solicitud();

        $this->actingAs($this->soloVer(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('Reenviar al ERP')
            ->assertSee('data-sin-permiso="editar"', false)
            ->assertDontSee(route('admin.solicitudes.reenviar-erp', $solicitud), false);
    }

    public function test_la_bandeja_marca_las_no_enviadas(): void
    {
        $solicitud = $this->solicitud();

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.index', ['numero' => $solicitud->numero]))
            ->assertOk()
            ->assertSee('No enviada al ERP');
    }
}
