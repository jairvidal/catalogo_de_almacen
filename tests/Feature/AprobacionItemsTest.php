<?php

namespace Tests\Feature;

use App\Exceptions\DecisionSolicitudException;
use App\Models\Bodega;
use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Motivo;
use App\Models\Proyecto;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\SolicitanteErp;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\AprobacionSolicitudService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Columnas de referencia POR ITEM (tbl_solicitud_detalle) que el solicitante
 * diligencia en la tabla REPUESTOS al APROBAR.
 *
 * Corre contra la base de .env: DatabaseTransactions. Las maestras pueden
 * tener filas reales (tbl_bodega y tbl_unidad_medida las crearon por fuera),
 * asi que los codigos de prueba se generan y no se asumen libres.
 */
class AprobacionItemsTest extends TestCase
{
    use DatabaseTransactions;

    private string $marca;

    private ?Repuesto $repuesto = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Itm'.substr(uniqid(), -7);
    }

    /* --- Ayudantes ------------------------------------------------------ */

    private function solicitante(): SolicitanteErp
    {
        $solicitante = SolicitanteErp::create([
            'col_codigo_erp' => 'TEST-'.uniqid(),
            'col_nombre' => 'Aprobador '.$this->marca,
            'col_cedula' => '302'.random_int(100000, 999999),
            'col_correo' => 'items.'.uniqid().'@sidocsa.com',
            'col_activo' => true,
        ]);

        SolicitanteErp::withoutTimestamps(fn () => $solicitante->forceFill([
            'col_password' => Hash::make('Clave2026abc'),
            'col_password_asignada_at' => now(),
        ])->save());

        return $solicitante;
    }

    /**
     * Solicitud con DOS items (cantidades 3 y 1).
     *
     * @param  array<string, mixed>  $extra
     */
    private function solicitudDe(SolicitanteErp $persona, string $estado = Solicitud::ESTADO_POR_APROBAR, array $extra = []): Solicitud
    {
        $this->repuesto ??= Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba items',
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

        foreach ([3, 1] as $i => $cantidad) {
            SolicitudItem::create([
                'solicitud_id' => $solicitud->id,
                'repuesto_id' => $this->repuesto->id,
                'codigo' => 'C'.$i.$this->marca,
                'nombre' => 'Item '.$i.' '.$this->marca,
                'cantidad_solicitada' => $cantidad,
            ]);
        }

        return $solicitud->load('items');
    }

    /** Codigo del largo pedido que no existe en la maestra. */
    private function codigoLibre(string $modelo, int $largo = 5): string
    {
        do {
            $codigo = 'Z'.strtoupper(substr(bin2hex(random_bytes(8)), 0, $largo - 1));
        } while ($modelo::query()->whereKey($codigo)->exists());

        return $codigo;
    }

    private function maestra(string $modelo, bool $activa = true, int $largo = 5): string
    {
        $codigo = $this->codigoLibre($modelo, $largo);

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
    private function datosItemValidos(): array
    {
        return [
            'cod_motivo' => $this->maestra(Motivo::class),
            'cod_bodega' => $this->maestra(Bodega::class),
            'cod_unidad_medida' => $this->maestra(UnidadMedida::class),
            'cod_centro_operacion' => $this->maestra(CentroOperacion::class),
            'cod_centro_de_costo' => $this->maestra(CentroDeCosto::class, true, 10),
            'cod_proyecto' => $this->maestra(Proyecto::class, true, 10),
            'cantidad' => ' 2,5 ',
            'notas_item' => '  Nota '.$this->marca.'  ',
            'descripcion_item' => 'Descripcion '.$this->marca,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnasDe(SolicitudItem $item): array
    {
        $fresco = $item->fresh();

        return collect(array_keys(SolicitudItem::REFERENCIAS_APROBACION))
            ->mapWithKeys(fn ($columna) => [$columna => $fresco->getAttribute($columna)])
            ->all();
    }

    private function sinColumnas(): array
    {
        return array_fill_keys(array_keys(SolicitudItem::REFERENCIAS_APROBACION), null);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin items prueba',
            'email' => 'items.admin.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => Rol::where('col_clave', User::ROL_ADMIN)->value('id'),
            'activo' => true,
        ]);
    }

    /* --- Formulario ----------------------------------------------------- */

    public function test_el_formulario_pinta_los_campos_por_item_con_prellenado(): void
    {
        $persona = $this->solicitante();
        $centro = $this->maestra(CentroOperacion::class);
        $largo = $this->maestra(CentroOperacion::class, true, 10); // largo maximo de la clave
        $bodega = $this->maestra(Bodega::class);
        $bodegaInactiva = $this->maestra(Bodega::class, false);
        $solicitud = $this->solicitudDe($persona, extra: ['cod_centro_operacion' => $centro]);
        [$primero, $segundo] = $solicitud->items->all();

        $html = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->getContent();

        foreach (['cod_motivo', 'cod_bodega', 'cod_unidad_medida', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto', 'cantidad', 'notas_item', 'descripcion_item'] as $campo) {
            $this->assertStringContainsString('name="items['.$primero->id.']['.$campo.']" form="form-aprobar"', $html, $campo);
        }

        // Encabezados de la imagen, en orden: todas las referencias en el
        // mismo renglon del item, sin fila "Mas datos".
        $this->assertMatchesRegularExpression('/Cant\. solicitada<\/th>\s*<th[^>]*>Motivo<\/th>\s*<th[^>]*>Bodega<\/th>\s*<th[^>]*>Und\. medida<\/th>\s*<th[^>]*>Centro de op\.<\/th>\s*<th[^>]*>Centro de costo<\/th>\s*<th[^>]*>Cant\. aprobada<\/th>\s*<th[^>]*>Unidad de negocio<\/th>\s*<th[^>]*>Proyecto<\/th>\s*<th[^>]*>Notas<\/th>\s*<th[^>]*>Descripcion<\/th>/', $html);
        $this->assertStringNotContainsString('item-mas-datos', $html);
        $this->assertStringNotContainsString('fila-item-extra', $html);
        $this->assertSame(2, substr_count($html, '<tr class="fila-item">'));

        // Cada campo con su etiqueta para lectores de pantalla.
        foreach (['Cantidad aprobada', 'Unidad de negocio', 'Proyecto', 'Notas', 'Descripcion'] as $etiqueta) {
            $this->assertStringContainsString('class="visually-hidden">'.$etiqueta.' del item '.$primero->codigo.'</label>', $html, $etiqueta);
        }
        $this->assertStringContainsString('data-hereda="cod_proyecto"', $html);

        // Cantidad prellenada con la solicitada; unidad de negocio de solo lectura y sin name.
        $this->assertStringContainsString('name="items['.$primero->id.'][cantidad]" form="form-aprobar"', $html);
        $this->assertMatchesRegularExpression('/id="item-'.$primero->id.'-cantidad"[^>]*value="3"/', $html);
        $this->assertMatchesRegularExpression('/id="item-'.$segundo->id.'-cantidad"[^>]*value="1"/', $html);
        $this->assertMatchesRegularExpression('/id="item-'.$primero->id.'-cod_unidad_negocio"[^>]*readonly[^>]*value="001"/', $html);
        $this->assertStringNotContainsString('[cod_unidad_negocio]', $html);

        // Centro de operacion de la cabecera prellena los dos items: 1 (cabecera) + 2.
        $this->assertSame(3, substr_count($html, '<option value="'.$centro.'" selected'));
        // Desde la FK el detalle mide lo que la clave: el codigo de 10 se
        // ofrece en la cabecera y en los dos items (ya no se recorta la lista).
        $this->assertSame(3, substr_count($html, 'value="'.$largo.'"'));
        // Opciones activas repetidas por renglon; la inactiva, en ninguno.
        $this->assertSame(2, substr_count($html, 'value="'.$bodega.'"'));
        $this->assertStringNotContainsString('value="'.$bodegaInactiva.'"', $html);
        $this->assertStringContainsString('data-hereda="cod_centro_operacion"', $html);
    }

    /* --- Aprobar -------------------------------------------------------- */

    public function test_aprobar_guarda_los_campos_por_item(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();
        $datos = $this->datosItemValidos();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [
                    $primero->id => $datos + ['cod_unidad_negocio' => '999'],
                    $segundo->id => ['cantidad' => '4', 'cod_bodega' => strtolower($datos['cod_bodega'])],
                ],
            ])
            ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);

        $uno = $this->columnasDe($primero);
        $this->assertSame($datos['cod_motivo'], $uno['cod_motivo']);
        $this->assertSame($datos['cod_bodega'], $uno['cod_bodega']);
        $this->assertSame($datos['cod_unidad_medida'], $uno['cod_unidad_medida']);
        $this->assertSame($datos['cod_centro_operacion'], $uno['cod_centro_operacion']);
        $this->assertSame($datos['cod_centro_de_costo'], $uno['cod_centro_de_costo']);
        $this->assertSame($datos['cod_proyecto'], $uno['cod_proyecto']);
        $this->assertSame('2.500', $uno['cantidad'], 'La coma decimal se acepta.');
        $this->assertSame(SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO, $uno['cod_unidad_negocio'], 'Se ignora el 999.');
        $this->assertSame('Nota '.$this->marca, $uno['notas_item'], 'Se recorta.');
        $this->assertSame('Descripcion '.$this->marca, $uno['descripcion_item']);

        $dos = $this->columnasDe($segundo);
        $this->assertSame('4.000', $dos['cantidad']);
        $this->assertSame($datos['cod_bodega'], $dos['cod_bodega'], 'Se guarda la clave tal como esta en la maestra.');
        $this->assertNull($dos['cod_motivo']);
        $this->assertSame('001', $dos['cod_unidad_negocio']);

        // cantidad_solicitada y cantidad_entregada no se tocan.
        $this->assertSame(3, $primero->fresh()->cantidad_solicitada);
        $this->assertNull($primero->fresh()->cantidad_entregada);
    }

    public function test_aprobar_sin_items_deja_todo_en_null_salvo_la_unidad_de_negocio(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [$solicitud->items[0]->id => ['cantidad' => '  ', 'notas_item' => '']],
            ])
            ->assertSessionHasNoErrors();

        foreach ($solicitud->items as $item) {
            $this->assertSame(array_merge($this->sinColumnas(), ['cod_unidad_negocio' => '001']), $this->columnasDe($item));
        }
    }

    public function test_un_id_de_detalle_ajeno_se_ignora_y_no_se_toca(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $otra = $this->solicitudDe($this->solicitante());
        $ajeno = $otra->items[0];

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [
                    $ajeno->id => ['cantidad' => '9', 'notas_item' => 'intruso'],
                    'abc' => ['cantidad' => '9'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
        $this->assertSame($this->sinColumnas(), $this->columnasDe($ajeno));
        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $otra->fresh()->estado);
    }

    public function test_valores_invalidos_por_item_se_rechazan_y_no_aprueban(): void
    {
        $persona = $this->solicitante();
        $unidadInactiva = $this->maestra(UnidadMedida::class, false);
        $casos = [
            'bodega inexistente' => ['cod_bodega', $this->codigoLibre(Bodega::class)],
            'unidad inactiva' => ['cod_unidad_medida', $unidadInactiva],
            'centro mas largo que la clave' => ['cod_centro_operacion', 'ABCDEFGHIJK'],
            'bodega mas larga que la clave' => ['cod_bodega', 'ABCDEFGHIJK'],
            'motivo largo' => ['cod_motivo', 'ABCDEF'],
            'proyecto inexistente' => ['cod_proyecto', $this->codigoLibre(Proyecto::class, 10)],
            'bodega arreglo' => ['cod_bodega', ['x']],
            'cantidad cero' => ['cantidad', '0'],
            'cantidad cero decimal' => ['cantidad', '0,000'],
            'cantidad negativa' => ['cantidad', '-1'],
            'cantidad texto' => ['cantidad', 'dos'],
            'cantidad 4 decimales' => ['cantidad', '1,2345'],
            'cantidad con miles' => ['cantidad', '1.234,5'],
            'cantidad enorme' => ['cantidad', '1234567890'],
            'notas largas' => ['notas_item', str_repeat('x', 501)],
            'descripcion larga' => ['descripcion_item', str_repeat('x', 501)],
        ];

        foreach ($casos as $caso => [$campo, $valor]) {
            $solicitud = $this->solicitudDe($persona);
            $item = $solicitud->items[0];

            $this->actingAs($persona, 'solicitante')
                ->from(route('solicitante.solicitudes.show', $solicitud->id))
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                    'cod_referencia' => 'REF',
                    'items' => [$item->id => [$campo => $valor, 'notas_item' => $campo === 'notas_item' ? $valor : 'ok']],
                ])
                ->assertRedirect(route('solicitante.solicitudes.show', $solicitud->id))
                ->assertSessionHasErrors("items.{$item->id}.{$campo}");

            $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado, "Caso {$caso}: no debe aprobar.");
            $this->assertNull($solicitud->fresh()->cod_referencia, "Caso {$caso}: no escribe la cabecera.");
            $this->assertSame($this->sinColumnas(), $this->columnasDe($item), "Caso {$caso}: no escribe el item.");
        }

        Mail::assertNothingSent();
    }

    public function test_el_error_vuelve_con_los_valores_del_item_y_el_mensaje_bajo_el_campo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $item = $solicitud->items[0];
        $ruta = route('solicitante.solicitudes.show', $solicitud->id);

        $this->actingAs($persona, 'solicitante')
            ->from($ruta)
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [$item->id => ['cantidad' => 'abc', 'notas_item' => 'Nota '.$this->marca]],
            ]);

        $html = $this->actingAs($persona, 'solicitante')->get($ruta)->assertOk()->getContent();

        $this->assertStringContainsString('Nota '.$this->marca.'</textarea>', $html);
        $this->assertStringContainsString('id="item-'.$item->id.'-cantidad-ayuda" class="invalid-feedback d-block"', $html);
        $this->assertStringContainsString('La cantidad del item 1 debe ser un numero mayor que cero', $html);
        // El campo con error esta en el renglon del item, a la vista: ya no
        // hay un bloque "Mas datos" plegable que haya que abrir.
        $this->assertStringNotContainsString('item-mas-datos', $html);
        $this->assertMatchesRegularExpression('/id="item-'.$item->id.'-cantidad"[^>]*class="[^"]*is-invalid/s', $html);
    }

    public function test_denegar_no_escribe_los_campos_por_item(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $item = $solicitud->items[0];

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.denegar', $solicitud->id), [
                'motivo_denegacion' => 'No',
                'items' => [$item->id => $this->datosItemValidos()],
            ])
            ->assertSessionHas('exito');

        $this->assertSame(Solicitud::ESTADO_RECHAZADA, $solicitud->fresh()->estado);
        $this->assertSame($this->sinColumnas(), $this->columnasDe($item));
    }

    public function test_no_se_aceptan_si_ya_no_esta_por_aprobar(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona, Solicitud::ESTADO_PENDIENTE, ['aprobada_at' => now()]);
        $item = $solicitud->items[0];

        $this->actingAs($persona, 'solicitante')
            ->from(route('solicitante.solicitudes.show', $solicitud->id))
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [$item->id => ['cantidad' => '2']],
            ])
            ->assertSessionHas('error');

        $this->assertSame($this->sinColumnas(), $this->columnasDe($item));
    }

    public function test_sobre_una_solicitud_ajena_es_404_y_no_escribe(): void
    {
        $ajena = $this->solicitudDe($this->solicitante());
        $item = $ajena->items[0];

        $this->actingAs($this->solicitante(), 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $ajena->id), [
                'items' => [$item->id => ['cantidad' => '2']],
            ])
            ->assertNotFound();

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $ajena->fresh()->estado);
        $this->assertSame($this->sinColumnas(), $this->columnasDe($item));
    }

    /* --- Servicio ------------------------------------------------------- */

    public function test_el_servicio_relee_la_maestra_del_item_y_revierte_todo(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();
        $bodega = $this->maestra(Bodega::class);
        $motivo = $this->maestra(Motivo::class);

        // Paso la validacion y luego la maestra se inactivo.
        Motivo::whereKey($motivo)->update(['col_activo' => false]);

        try {
            app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, ['cod_referencia' => 'REF'], [
                $primero->id => ['cod_bodega' => $bodega],
                $segundo->id => ['cod_motivo' => $motivo],
            ]);
            $this->fail('Debio lanzar DecisionSolicitudException.');
        } catch (DecisionSolicitudException $e) {
            $this->assertStringContainsString($motivo, $e->getMessage());
            $this->assertStringContainsString($segundo->codigo, $e->getMessage());
        }

        $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);
        $this->assertNull($solicitud->fresh()->cod_referencia);
        $this->assertSame($this->sinColumnas(), $this->columnasDe($primero), 'Tampoco queda el item valido.');
    }

    public function test_el_servicio_exige_el_largo_y_la_forma_de_la_cantidad(): void
    {
        $persona = $this->solicitante();
        // 11 caracteres: no cabe en la columna ni en la clave varchar(10).
        foreach ([['cod_centro_operacion' => 'ABCDEFGHIJK'], ['cantidad' => '0'], ['cantidad' => '1.2345'], ['notas_item' => str_repeat('x', 501)]] as $campos) {
            $solicitud = $this->solicitudDe($persona);

            try {
                app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [], [$solicitud->items[0]->id => $campos]);
                $this->fail('Debio lanzar DecisionSolicitudException para '.json_encode($campos));
            } catch (DecisionSolicitudException) {
                $this->assertSame(Solicitud::ESTADO_POR_APROBAR, $solicitud->fresh()->estado);
            }
        }
    }

    /* --- Solo lectura --------------------------------------------------- */

    public function test_despues_de_aprobar_se_ven_en_solo_lectura_en_el_portal_y_el_panel_y_no_en_la_confirmacion(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();
        $datos = $this->datosItemValidos();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [$primero->id => $datos],
            ])
            ->assertSessionHasNoErrors();

        $textoBodega = $datos['cod_bodega'].' - Maestra '.$this->marca.' '.$datos['cod_bodega'];

        $portal = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee($textoBodega)
            ->assertSee('Nota '.$this->marca)
            ->assertSee('2,5')
            ->assertDontSee('name="items[', false)
            ->assertDontSee('item-mas-datos', false)
            ->getContent();

        // El segundo item solo tiene la unidad de negocio: no se pintan renglones vacios.
        $this->assertSame(1, substr_count($portal, '<dt>Bodega</dt>'));
        $this->assertSame(2, substr_count($portal, '<dt>Unidad de negocio</dt>'));

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee($textoBodega)
            ->assertSee('Descripcion '.$this->marca)
            ->assertSee($datos['cod_proyecto'].' - Maestra '.$this->marca);

        // Vista publica (mismo partial que /consultar): sin referencias por item.
        $this->withSession(['solicitud_recien_creada' => $solicitud->numero])
            ->get(route('solicitudes.confirmacion', $solicitud->numero))
            ->assertOk()
            ->assertDontSee($textoBodega)
            ->assertDontSee('Nota '.$this->marca)
            ->assertDontSee('referencias-item', false);
    }
}
