<?php

namespace Tests\Feature;

use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Motivo;
use App\Models\Parametro;
use App\Models\Proyecto;
use App\Models\Repuesto;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\UnidadMedida;
use App\Services\BitacoraIntegracionErp;
use App\Services\IntegracionErpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Envio de la solicitud aprobada al servicio web del ERP
 * (IntegracionErpService::integracionERP, V 1.4.39): encabezado campo por
 * campo, consecutivo, y que un fallo del ERP nunca deshaga la aprobacion.
 *
 * Corre contra la base de .env: DatabaseTransactions. La URL, la plantilla y
 * el consecutivo se ajustan dentro de la transaccion de la prueba.
 */
class IntegracionErpTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'https://erp.prueba.local/api/importar';

    /** La plantilla del usuario (img00.png), con su CRLF y espacios sobrantes. */
    private const PLANTILLA = "{\r\n    \"tipo_documento\": \"RQF\",\r\n    \"numero\": \"adicionar_dato\", \r\n"
        ."    \"fecha\": \"adicionar_dato\",\r\n    \"estado\": \"0\",\r\n    \"tercero\": \"\",\r\n"
        ."    \"referencia\": \"\",\r\n    \"numero_OC\": \"adicionar_dato\",\r\n    \"solicitante\": \"adicionar_dato\",\r\n"
        ."    \"cargo\": \"\",\r\n    \"entrega_en_dias\": \"0\",\r\n    \"fecha_entrega\": \"adicionar_dato\",\r\n"
        ."    \"notas\": \"adicionar_dato\",\r\n    \"movimiento\": [\r\n      {\r\n"
        ."        \"item_id\": \"adicionar_dato\",\r\n        \"bodega_id\": \"P2ALM\",\r\n"
        ."        \"motivo_id\": \"adicionar_dato\",\r\n        \"cant\": \"adicionar_dato\", \r\n"
        ."        \"und\": \"adicionar_dato\",\r\n        \"un\": \"001\",\r\n"
        ."        \"centro_operacion\": \"adicionar_dato\",\r\n        \"centro_costo\": \"adicionar_dato\",\r\n"
        ."        \"proyecto\": \"adicionar_dato\",\r\n        \"notas\": \"adicionar_dato\",\r\n"
        ."        \"descripcion\": \"\"\r\n      }\r\n    ]\r\n  }";

    private string $marca;

    private ?Repuesto $repuesto = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Erp'.substr(uniqid(), -7);

        $this->configurar(self::URL, self::PLANTILLA);

        // El consecutivo "arranca en 1": se parte de 0 y se sueltan los numeros
        // ya reservados para que el indice unico no choque (todo se revierte).
        DB::table('tbl_solicitudes')->whereNotNull('erp_numero')->update(['erp_numero' => null]);
        DB::table('tbl_consecutivo')->where('col_nombre', IntegracionErpService::CONSECUTIVO)->update(['col_ultimo' => 0]);
    }

    /* --- Ayudantes ------------------------------------------------------ */

    private function configurar(string $url, string $plantilla): void
    {
        foreach ([Parametro::ERP_IMPORTACION_URL => $url, Parametro::ERP_IMPORTACION_JSON => $plantilla] as $nombre => $valor) {
            DB::table('tbl_parametro')->where('col_nombre', $nombre)->update([
                'col_valor' => $valor,
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ]);
        }
    }

    private function contador(): int
    {
        return (int) DB::table('tbl_consecutivo')->where('col_nombre', IntegracionErpService::CONSECUTIVO)->value('col_ultimo');
    }

    private function solicitante(): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create([
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Aprobador '.$this->marca,
            'col_cedula' => '302'.random_int(100000, 999999),
            'col_correo' => 'erp.'.uniqid().'@sidocsa.com',
            'col_activo' => true,
        ]);

        SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
            'col_password' => Hash::make('Clave2026abc'),
            'col_password_asignada_at' => now(),
        ])->save());

        return $solicitante;
    }

    private function solicitudDe(SolicitanteErp $persona): Solicitud
    {
        $this->repuesto ??= Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba ERP',
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
            'observaciones' => 'Original '.$this->marca,
        ]);
        $solicitud->forceFill(['solicitante_erp_id' => $persona->col_codigo_erp, 'estado' => Solicitud::ESTADO_POR_APROBAR])->save();

        SolicitudItem::create([
            'solicitud_id' => $solicitud->id,
            'repuesto_id' => $this->repuesto->id,
            'codigo' => $this->repuesto->codigo,
            'nombre' => 'Item '.$this->marca,
            'cantidad_solicitada' => 1,
        ]);

        return $solicitud;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function aprobar(SolicitanteErp $persona, Solicitud $solicitud, array $datos = []): TestResponse
    {
        return $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), $datos);
    }

    /** @return array<string, mixed> */
    private function cuerpoEnviado(): array
    {
        $cuerpos = Http::recorded()->map(fn (array $par) => json_decode($par[0]->body(), true));
        $this->assertCount(1, $cuerpos, 'Se esperaba exactamente un envio al ERP.');

        return $cuerpos->first();
    }

    /* --- Encabezado ----------------------------------------------------- */

    public function test_aprobar_envia_el_encabezado_completo_campo_por_campo(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $hoy = now('America/Bogota')->format('Ymd');

        $this->aprobar($persona, $solicitud, ['cod_oc' => ' OC-'.$this->marca.' ', 'observaciones' => 'Urgente '.$this->marca])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito')
            ->assertSessionMissing('aviso');

        Http::assertSent(fn (Request $peticion) => $peticion->url() === self::URL
            && $peticion->method() === 'POST'
            && $peticion->hasHeader('Content-Type', 'application/json'));

        $cuerpo = $this->cuerpoEnviado();
        $plantilla = json_decode(self::PLANTILLA, true);

        // Mismo orden de claves que la plantilla.
        $this->assertSame(array_keys($plantilla), array_keys($cuerpo));

        // Valores por defecto: los de la plantilla, sin tocar.
        $this->assertSame('RQF', $cuerpo['tipo_documento']);
        $this->assertSame('0', $cuerpo['estado']);
        $this->assertSame('0', $cuerpo['entrega_en_dias']);

        // Sin valor.
        $this->assertSame('', $cuerpo['tercero']);
        $this->assertSame('', $cuerpo['referencia']);
        $this->assertSame('', $cuerpo['cargo']);

        // Completados por el codigo.
        $this->assertSame('1', $cuerpo['numero']);
        $this->assertSame($hoy, $cuerpo['fecha']);
        $this->assertSame($hoy, $cuerpo['fecha_entrega']);
        $this->assertSame('OC-'.$this->marca, $cuerpo['numero_OC'], 'cod_oc del formulario, recortado.');
        $this->assertSame($persona->col_codigo_erp, $cuerpo['solicitante'], 'Codigo del ERP (campo oculto cod_solicitante).');
        $this->assertSame('Urgente '.$this->marca, $cuerpo['notas'], 'Las observaciones editadas al aprobar.');

        // Un renglon por item (la solicitud de este caso tiene uno).
        $this->assertCount(1, $cuerpo['movimiento']);
        $this->assertSame(array_keys($plantilla['movimiento'][0]), array_keys($cuerpo['movimiento'][0]));

        $fresca = $solicitud->fresh();
        $this->assertSame(1, $fresca->erp_numero);
        $this->assertNotNull($fresca->erp_enviado_at);
        $this->assertNull($fresca->erp_error);
        $this->assertNull($fresca->notificado_at, 'La integracion no toca notificado_at.');
    }

    public function test_sin_oc_ni_observaciones_viajan_vacios(): void
    {
        Http::fake([self::URL => Http::response('', 201)]);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->aprobar($persona, $solicitud, ['cod_oc' => '', 'observaciones' => ''])->assertSessionHasNoErrors();

        $cuerpo = $this->cuerpoEnviado();
        $this->assertSame('', $cuerpo['numero_OC']);
        $this->assertSame('', $cuerpo['notas']);
    }

    public function test_la_fecha_es_la_de_colombia_en_formato_aaaammdd(): void
    {
        // 03:00 UTC del 20 = 22:00 del 19 en Bogota.
        $this->travelTo(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        Http::fake([self::URL => Http::response('', 200)]);
        $persona = $this->solicitante();

        $this->aprobar($persona, $this->solicitudDe($persona))->assertSessionHasNoErrors();

        $cuerpo = $this->cuerpoEnviado();
        $this->assertSame('20261019', $cuerpo['fecha']);
        $this->assertSame('20261019', $cuerpo['fecha_entrega']);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $cuerpo['fecha']);
    }

    public function test_los_valores_se_escapan_y_el_json_es_valido(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $persona = $this->solicitante();
        $notas = "Llave \"inglesa\", ñandú \\ fin\n}";

        $this->aprobar($persona, $this->solicitudDe($persona), ['observaciones' => $notas])->assertSessionHasNoErrors();

        $cuerpo = $this->cuerpoEnviado();
        $this->assertSame($notas, $cuerpo['notas']);
    }

    /* --- Detalle (movimiento) ------------------------------------------- */

    /** Codigo del largo pedido que no existe en la maestra (tiene datos reales). */
    private function maestra(string $modelo, int $largo = 5): string
    {
        do {
            $codigo = 'Z'.strtoupper(substr(bin2hex(random_bytes(8)), 0, $largo - 1));
        } while ($modelo::query()->whereKey($codigo)->exists());

        $modelo::create([
            (new $modelo)->getKeyName() => $codigo,
            'col_nombre' => 'Maestra '.$this->marca.' '.$codigo,
            'col_activo' => true,
        ]);

        return $codigo;
    }

    /**
     * Solicitud con tres renglones de repuestos distintos (repuestos.codigo
     * es int en el esquema del ERP: el item viaja sin ceros a la izquierda).
     *
     * @return array{0: Solicitud, 1: list<SolicitudItem>, 2: list<Repuesto>}
     */
    private function solicitudConTresRenglones(SolicitanteErp $persona, string $unidad): array
    {
        $solicitud = $this->solicitudDe($persona);
        $solicitud->items()->delete();
        $repuestos = [];
        $items = [];

        foreach ([(string) random_int(950000000, 999999999), (string) random_int(950000000, 999999999), (string) random_int(950000000, 999999999)] as $i => $codigo) {
            $repuestos[$i] = Repuesto::create([
                'codigo' => $codigo,
                'nombre' => 'Repuesto ERP '.$i,
                'unidad_medida' => $unidad,
                'existencia' => 10,
                'stock_minimo' => 1,
                'stock_maximo' => 20,
                'estado' => Repuesto::ESTADO_ACTIVO,
            ]);

            $items[$i] = SolicitudItem::create([
                'solicitud_id' => $solicitud->id,
                'repuesto_id' => $repuestos[$i]->id,
                'codigo' => $codigo,
                'nombre' => 'Item '.$i.' '.$this->marca,
                'cantidad_solicitada' => $i + 1,
            ]);
        }

        return [$solicitud, $items, $repuestos];
    }

    public function test_el_detalle_lleva_un_renglon_por_item_en_orden_y_campo_por_campo(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $persona = $this->solicitante();
        $unidad = $this->maestra(UnidadMedida::class);
        [$solicitud, $items, $repuestos] = $this->solicitudConTresRenglones($persona, $unidad);

        $motivo = $this->maestra(Motivo::class);
        $centro = $this->maestra(CentroOperacion::class, 10);
        $costo = $this->maestra(CentroDeCosto::class, 10);
        $proyecto = $this->maestra(Proyecto::class, 10);

        $this->aprobar($persona, $solicitud, ['items' => [
            $items[0]->id => [
                'cod_motivo' => $motivo,
                'cantidad' => '2,5',
                'cod_centro_operacion' => $centro,
                'cod_centro_de_costo' => $costo,
                'cod_proyecto' => $proyecto,
                'notas_item' => 'Nota "uno"',
            ],
            $items[1]->id => ['cantidad' => '3'],
            // El tercero no se diligencia: todo vacio salvo lo que impone el servidor.
        ]])->assertSessionHasNoErrors()->assertSessionMissing('aviso');

        $movimiento = $this->cuerpoEnviado()['movimiento'];
        $molde = json_decode(self::PLANTILLA, true)['movimiento'][0];

        $this->assertCount(3, $movimiento);
        $this->assertSame(
            array_map(fn (Repuesto $repuesto) => (string) $repuesto->codigo, $repuestos),
            array_column($movimiento, 'item_id'),
            'item_id = codigo del repuesto (columna Id), en el orden del formulario.'
        );

        foreach ($movimiento as $renglon) {
            $this->assertSame(array_keys($molde), array_keys($renglon), 'Mismo orden de claves que el molde.');
            $this->assertSame('P2ALM', $renglon['bodega_id'], 'Valor por defecto de la plantilla.');
            $this->assertSame('001', $renglon['un'], 'Valor por defecto de la plantilla.');
            $this->assertSame('', $renglon['descripcion']);
            $this->assertSame($unidad, $renglon['und'], 'La unidad que impone el servidor desde el repuesto.');
        }

        $this->assertSame([
            'item_id' => (string) $repuestos[0]->codigo,
            'bodega_id' => 'P2ALM',
            'motivo_id' => $motivo,
            'cant' => '2.5',
            'und' => $unidad,
            'un' => '001',
            'centro_operacion' => $centro,
            'centro_costo' => $costo,
            'proyecto' => $proyecto,
            'notas' => 'Nota "uno"',
            'descripcion' => '',
        ], $movimiento[0]);

        $this->assertSame('3', $movimiento[1]['cant'], 'Sin ceros decimales de sobra.');

        foreach (['motivo_id', 'centro_operacion', 'centro_costo', 'proyecto', 'notas'] as $campo) {
            $this->assertSame('', $movimiento[1][$campo], "Vacio => \"\" ({$campo}).");
            $this->assertSame('', $movimiento[2][$campo], "Vacio => \"\" ({$campo}).");
        }

        $this->assertSame('', $movimiento[2]['cant'], 'Sin cantidad aprobada => "".');
    }

    public function test_la_bitacora_guarda_el_json_con_la_hora_de_colombia(): void
    {
        // 03:00:05 UTC del 20 = 22:00:05 del 19 en Bogota.
        $this->travelTo(Carbon::parse('2026-10-20 03:00:05', 'UTC'));
        Http::fake([self::URL => Http::response('', 200)]);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $ruta = app(BitacoraIntegracionErp::class)->ruta();
        $antes = file_exists($ruta) ? filesize($ruta) : 0;

        $this->aprobar($persona, $solicitud)->assertSessionHasNoErrors();

        $json = Http::recorded()->first()[0]->body();
        $nuevo = substr((string) file_get_contents($ruta), $antes);

        $this->assertStringEndsWith('integracion-erp-pruebas.txt', $ruta, 'Bajo PHPUnit no se escribe la bitacora real.');
        $this->assertStringContainsString(
            "2026-10-19 22:00:05 | solicitud: {$solicitud->numero} | consecutivo ERP: 1 | enviado\n{$json}\n",
            $nuevo
        );
        $this->assertStringNotContainsString(self::URL, $nuevo, 'La URL no va a la bitacora.');
    }

    public function test_la_bitacora_registra_tambien_el_envio_fallido(): void
    {
        Http::fake([self::URL => Http::response('', 503)]);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $ruta = app(BitacoraIntegracionErp::class)->ruta();
        $antes = file_exists($ruta) ? filesize($ruta) : 0;

        $this->aprobar($persona, $solicitud)->assertSessionHas('aviso');

        $json = Http::recorded()->first()[0]->body();
        $nuevo = substr((string) file_get_contents($ruta), $antes);

        $this->assertStringContainsString("solicitud: {$solicitud->numero} | consecutivo ERP: 1 | fallo: el servicio web del ERP respondio HTTP 503.\n{$json}\n", $nuevo);
    }

    public function test_sin_url_no_escribe_la_bitacora(): void
    {
        Http::fake();
        $this->configurar('', self::PLANTILLA);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $ruta = app(BitacoraIntegracionErp::class)->ruta();
        $antes = file_exists($ruta) ? (string) file_get_contents($ruta) : '';

        $this->aprobar($persona, $solicitud);

        $despues = file_exists($ruta) ? (string) file_get_contents($ruta) : '';
        $this->assertStringNotContainsString($solicitud->numero, substr($despues, strlen($antes)));
    }

    /* --- Consecutivo ---------------------------------------------------- */

    public function test_el_consecutivo_arranca_en_1_e_incrementa(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $persona = $this->solicitante();
        $primera = $this->solicitudDe($persona);
        $segunda = $this->solicitudDe($persona);

        $this->aprobar($persona, $primera)->assertSessionHasNoErrors();
        $this->aprobar($persona, $segunda)->assertSessionHasNoErrors();

        $numeros = Http::recorded()->map(fn (array $par) => json_decode($par[0]->body(), true)['numero'])->all();
        $this->assertSame(['1', '2'], $numeros);
        $this->assertSame(1, $primera->fresh()->erp_numero);
        $this->assertSame(2, $segunda->fresh()->erp_numero);
        $this->assertSame(2, $this->contador());
    }

    public function test_un_reenvio_reutiliza_el_numero_reservado(): void
    {
        Http::fakeSequence(self::URL)->push('', 500)->push('', 200);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->aprobar($persona, $solicitud);
        $this->assertSame(1, $solicitud->fresh()->erp_numero);

        $this->assertTrue(app(IntegracionErpService::class)->integracionERP($solicitud->fresh()));

        $numeros = Http::recorded()->map(fn (array $par) => json_decode($par[0]->body(), true)['numero'])->all();
        $this->assertSame(['1', '1'], $numeros, 'El ERP recibe el mismo numero: puede rechazar el duplicado.');
        $this->assertSame(1, $this->contador(), 'El reintento no gasta otro numero.');
        $this->assertNull($solicitud->fresh()->erp_error);
    }

    /* --- Fallos: nunca revierten la aprobacion -------------------------- */

    public function test_un_error_http_no_revierte_la_aprobacion_y_avisa(): void
    {
        Http::fake([self::URL => Http::response('<html>error interno</html>', 500)]);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->aprobar($persona, $solicitud, ['cod_oc' => 'OC-'.$this->marca])
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHas('exito')
            ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'HTTP 500'));

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertNotNull($fresca->aprobada_at);
        $this->assertSame('OC-'.$this->marca, $fresca->cod_OC);
        $this->assertStringStartsWith('Integracion ERP: ', $fresca->erp_error);
        $this->assertStringNotContainsString('error interno', $fresca->erp_error, 'El cuerpo de la respuesta no se guarda.');
        $this->assertNull($fresca->erp_enviado_at);
        $this->assertSame(1, $fresca->erp_numero, 'El numero queda reservado para un reenvio.');
    }

    public function test_un_timeout_no_revierte_la_aprobacion(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->aprobar($persona, $solicitud)->assertSessionHas('exito')->assertSessionHas('aviso');

        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertStringContainsString('no se pudo conectar', $fresca->erp_error);
    }

    public function test_sin_url_no_se_llama_ni_se_gasta_numero(): void
    {
        Http::fake();
        $this->configurar('   ', self::PLANTILLA);
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->aprobar($persona, $solicitud)->assertSessionHas('exito')->assertSessionHas('aviso');

        Http::assertNothingSent();
        $fresca = $solicitud->fresh();
        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $fresca->estado);
        $this->assertNull($fresca->erp_numero);
        $this->assertSame(0, $this->contador());
        $this->assertStringContainsString('URL WS', $fresca->erp_error);
    }

    public function test_plantilla_vacia_o_invalida_no_llama_ni_gasta_numero(): void
    {
        Http::fake();
        $persona = $this->solicitante();

        $casos = [
            '',
            '{"numero": "adicionar_dato",}',
            '["no es objeto"]',
            '{"bodega": "adicionar_dato"}',
            '{"numero": "adicionar_dato", "movimiento": "no es arreglo"}',
            '{"numero": "adicionar_dato", "movimiento": []}',
            '{"numero": "adicionar_dato", "movimiento": [{"item_id": "adicionar_dato", "lote": "adicionar_dato"}]}',
        ];

        foreach ($casos as $plantilla) {
            $this->configurar(self::URL, $plantilla);
            $solicitud = $this->solicitudDe($persona);

            $this->aprobar($persona, $solicitud)->assertSessionHas('exito')->assertSessionHas('aviso');

            $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
            $this->assertNull($solicitud->fresh()->erp_numero, "Plantilla: {$plantilla}");
            $this->assertNotNull($solicitud->fresh()->erp_error);
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->contador());
    }

    public function test_denegar_no_envia_al_erp(): void
    {
        Http::fake();
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), ['motivo_denegacion' => 'No'])
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertNull($solicitud->fresh()->erp_numero);
    }
}
