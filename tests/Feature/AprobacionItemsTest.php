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

    /** @var array<int, Repuesto> uno por renglon de solicitudDe() */
    private array $repuestos = [];

    /** Prefijo del id de los campos de un renglon: item-{repuestos.codigo}. */
    private function prefijo(SolicitudItem $item): string
    {
        return 'item-'.Repuesto::whereKey($item->repuesto_id)->value('codigo');
    }

    /** @return array<int, int> */
    private function idsRepuestos(): array
    {
        return array_map(fn (Repuesto $r) => $r->id, $this->repuestos);
    }

    /** Unidad de medida (activa en tbl_unidad_medida) del repuesto de prueba. */
    private string $unidad;

    /** Bodega (activa en tbl_bodega) del repuesto de prueba (V 1.4.31). */
    private string $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->marca = 'Itm'.substr(uniqid(), -7);
        $this->unidad = $this->maestra(UnidadMedida::class);
        $this->bodega = $this->maestra(Bodega::class);
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
        // Un repuesto distinto por renglon, como en una solicitud real (el
        // carrito no repite repuestos): los id de los campos usan su codigo.
        if ($this->repuestos === []) {
            foreach ([0, 1] as $i) {
                $repuesto = Repuesto::create([
                    'codigo' => (string) random_int(950000000, 999999999),
                    'nombre' => 'Repuesto prueba items '.$i,
                    'unidad_medida' => $this->unidad,
                    'existencia' => 10,
                    'stock_minimo' => 1,
                    'stock_maximo' => 20,
                    'estado' => Repuesto::ESTADO_ACTIVO,
                ]);

                // cod_bodega no es fillable en Repuesto (la columna la trae el ERP).
                Repuesto::whereKey($repuesto->id)->update(['cod_bodega' => $this->bodega]);
                $this->repuestos[] = $repuesto;
            }

            $this->repuesto = $this->repuestos[0];
        }

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
                'repuesto_id' => $this->repuestos[$i]->id,
                'codigo' => $this->repuestos[$i]->codigo,
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

        // Todas las columnas de referencia, tambien las que ya no se diligencian
        // (cod_unidad_negocio, descripcion_item): asi se comprueba que no se tocan.
        return collect(array_keys(SolicitudItem::REFERENCIAS_PARA_MOSTRAR))
            ->mapWithKeys(fn ($columna) => [$columna => $fresco->getAttribute($columna)])
            ->all();
    }

    private function sinColumnas(): array
    {
        return array_fill_keys(array_keys(SolicitudItem::REFERENCIAS_PARA_MOSTRAR), null);
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
        $motivo = $this->maestra(Motivo::class);
        $motivoInactivo = $this->maestra(Motivo::class, false);
        $solicitud = $this->solicitudDe($persona, extra: ['cod_centro_operacion' => $centro]);
        [$primero, $segundo] = $solicitud->items->all();

        $html = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->getContent();

        foreach (['cod_motivo', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto', 'cantidad', 'notas_item'] as $campo) {
            $this->assertStringContainsString('name="items['.$primero->id.']['.$campo.']" form="form-aprobar"', $html, $campo);
        }

        // Encabezados de la imagen, en orden: Und. medida (la del repuesto)
        // antes de Cant. solicitada y las demas referencias en el mismo
        // renglon del item, sin fila "Mas datos". Desde V 1.4.28 sin Unidad de
        // negocio ni Descripcion (img222.png): Notas es la ultima. Desde
        // V 1.4.29 Cant. aprobada va justo despues de Cant. solicitada y desde
        // V 1.4.32 Bodega justo despues de Cant. aprobada.
        $this->assertMatchesRegularExpression('/Repuesto<\/th>\s*<th[^>]*>Und\. medida<\/th>\s*<th[^>]*>Cant\. solicitada<\/th>\s*<th[^>]*>Cant\. aprobada<\/th>\s*<th[^>]*>Bodega<\/th>\s*<th[^>]*>Motivo<\/th>\s*<th[^>]*>Centro de op\.<\/th>\s*<th[^>]*>Centro de costo<\/th>\s*<th[^>]*>Proyecto<\/th>\s*<th[^>]*>Notas<\/th>\s*<\/tr>/', $html);
        $this->assertSame(1, substr_count($html, '>Cant. aprobada</th>'));

        // Y las celdas del renglon siguen el mismo orden que los encabezados:
        // la cantidad solicitada, el cuadro de la aprobada, la bodega y luego el motivo.
        $this->assertMatchesRegularExpression(
            '/<span class="fw-semibold">3<\/span>\s*<\/td>\s*<td class="columna-cantidad">\s*<label for="'.$this->prefijo($primero).'-cantidad".*?<span id="'.$this->prefijo($primero).'-cod_bodega".*?id="'.$this->prefijo($primero).'-cod_motivo"/s',
            $html
        );
        $this->assertStringNotContainsString('item-mas-datos', $html);
        $this->assertStringNotContainsString('fila-item-extra', $html);
        $this->assertSame(2, substr_count($html, '<tr class="fila-item">'));

        // Cada campo con su etiqueta para lectores de pantalla.
        foreach (['Cantidad aprobada', 'Proyecto', 'Notas'] as $etiqueta) {
            $this->assertStringContainsString('class="visually-hidden">'.$etiqueta.' del item '.$primero->codigo.'</label>', $html, $etiqueta);
        }
        $this->assertStringContainsString('data-hereda="cod_proyecto"', $html);

        // Cantidad prellenada con la solicitada.
        $this->assertStringContainsString('name="items['.$primero->id.'][cantidad]" form="form-aprobar"', $html);
        $this->assertMatchesRegularExpression('/id="'.$this->prefijo($primero).'-cantidad"[^>]*value="3"/', $html);
        $this->assertMatchesRegularExpression('/id="'.$this->prefijo($segundo).'-cantidad"[^>]*value="1"/', $html);

        // Unidad de negocio y Descripcion ya no se pintan: ni encabezado, ni
        // cuadro, ni etiqueta oculta, ni campo que viaje.
        foreach (['cod_unidad_negocio', 'descripcion_item'] as $columna) {
            $this->assertStringNotContainsString('-'.$columna.'"', $html, $columna);
            $this->assertStringNotContainsString('['.$columna.']', $html, $columna);
        }
        $this->assertStringNotContainsString('>Unidad de negocio</th>', $html);
        $this->assertStringNotContainsString('>Descripcion</th>', $html);
        $this->assertStringNotContainsString('Unidad de negocio del item', $html);
        $this->assertStringNotContainsString('Descripcion del item', $html);
        $this->assertStringNotContainsString('columna-unidad-negocio', $html);

        // Und. medida: la del repuesto, como etiqueta (no es un campo), sin name y sin lista.
        $this->assertMatchesRegularExpression('/<span id="'.$this->prefijo($primero).'-cod_unidad_medida"[^>]*>'.$this->unidad.'<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="'.$this->prefijo($primero).'-cod_unidad_medida"/', $html);
        $this->assertStringNotContainsString('[cod_unidad_medida]', $html);
        $this->assertStringNotContainsString('<option value="'.$this->unidad.'"', $html);

        // Bodega (V 1.4.31, img666.png): la del repuesto, como etiqueta, sin
        // name y sin lista; desde V 1.4.32 entre Cant. aprobada y Motivo.
        foreach ([$primero, $segundo] as $item) {
            $this->assertMatchesRegularExpression('/<span id="'.$this->prefijo($item).'-cod_bodega" class="fw-bold">'.$this->bodega.'<\/span>/', $html);
            $this->assertMatchesRegularExpression(
                '/id="'.$this->prefijo($item).'-cantidad".*?<span id="'.$this->prefijo($item).'-cod_bodega"[^>]*>.*?id="'.$this->prefijo($item).'-cod_motivo"/s',
                $html
            );
        }
        $this->assertDoesNotMatchRegularExpression('/<(input|select)[^>]*id="'.$this->prefijo($primero).'-cod_bodega"/', $html);
        $this->assertStringNotContainsString('[cod_bodega]', $html);
        $this->assertStringNotContainsString('value="'.$this->bodega.'"', $html);

        // Centro de operacion de la cabecera prellena los dos items: 1 (cabecera) + 2.
        $this->assertSame(3, substr_count($html, '<option value="'.$centro.'" selected'));
        // Desde la FK el detalle mide lo que la clave: el codigo de 10 se
        // ofrece en la cabecera y en los dos items (ya no se recorta la lista).
        $this->assertSame(3, substr_count($html, 'value="'.$largo.'"'));
        // Opciones activas repetidas por renglon; la inactiva, en ninguno.
        $this->assertSame(2, substr_count($html, 'value="'.$motivo.'"'));
        $this->assertStringNotContainsString('value="'.$motivoInactivo.'"', $html);

        // El total sigue bajo Cant. solicitada: Foto, Id, Repuesto y Und. medida antes.
        $this->assertMatchesRegularExpression('/<td colspan="4" class="text-end fw-semibold">Total unidades<\/td>\s*<td class="text-end fw-bold">4<\/td>\s*<td colspan="7"><\/td>/', $html);
        $this->assertStringContainsString('data-hereda="cod_centro_operacion"', $html);
    }

    /* --- Aprobar -------------------------------------------------------- */

    public function test_los_id_de_los_campos_llevan_el_codigo_del_repuesto_de_cada_renglon(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items;
        [$repuestoA, $repuestoB] = $this->repuestos;

        $html = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->getContent();

        $this->assertNotSame($repuestoA->codigo, $repuestoB->codigo);

        foreach ([[$primero, $repuestoA], [$segundo, $repuestoB]] as [$item, $repuesto]) {
            foreach (array_keys(SolicitudItem::REFERENCIAS_APROBACION) as $columna) {
                $this->assertStringContainsString('id="item-'.$repuesto->codigo.'-'.$columna.'"', $html, $columna);
            }
            // La columna Id muestra el codigo del repuesto, no la clave primaria.
            $this->assertStringContainsString('<td class="text-secondary small">'.$repuesto->codigo.'</td>', $html);
            $this->assertStringNotContainsString('<td class="text-secondary small">'.$repuesto->id.'</td>', $html);
            // El name sigue por el id del detalle (lista blanca del servidor).
            $this->assertStringContainsString('name="items['.$item->id.'][cantidad]"', $html);
            $this->assertStringNotContainsString('id="item-'.$item->id.'-cantidad"', $html);
        }
    }

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
        $this->assertSame($this->bodega, $uno['cod_bodega'], 'La del repuesto: se ignora la enviada.');
        $this->assertSame($this->unidad, $uno['cod_unidad_medida'], 'La del repuesto: se ignora la enviada.');
        $this->assertNotSame($datos['cod_unidad_medida'], $uno['cod_unidad_medida']);
        $this->assertSame($datos['cod_centro_operacion'], $uno['cod_centro_operacion']);
        $this->assertSame($datos['cod_centro_de_costo'], $uno['cod_centro_de_costo']);
        $this->assertSame($datos['cod_proyecto'], $uno['cod_proyecto']);
        $this->assertSame('2.500', $uno['cantidad'], 'La coma decimal se acepta.');
        $this->assertSame(SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO, $uno['cod_unidad_negocio'], 'Se ignora el 999.');
        $this->assertSame('Nota '.$this->marca, $uno['notas_item'], 'Se recorta.');
        $this->assertNull($uno['descripcion_item'], 'Desde V 1.4.28 la descripcion enviada se ignora.');

        $dos = $this->columnasDe($segundo);
        $this->assertSame('4.000', $dos['cantidad']);
        $this->assertSame($this->bodega, $dos['cod_bodega'], 'La del repuesto, no la enviada en minusculas.');
        $this->assertNull($dos['cod_motivo']);
        $this->assertSame('001', $dos['cod_unidad_negocio']);
        $this->assertSame($this->unidad, $dos['cod_unidad_medida']);

        // cantidad_solicitada y cantidad_entregada no se tocan.
        $this->assertSame(3, $primero->fresh()->cantidad_solicitada);
        $this->assertNull($primero->fresh()->cantidad_entregada);
    }

    public function test_aprobar_sin_items_deja_todo_en_null_salvo_las_impuestas(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [$solicitud->items[0]->id => ['cantidad' => '  ', 'notas_item' => '']],
            ])
            ->assertSessionHasNoErrors();

        foreach ($solicitud->items as $item) {
            $this->assertSame(
                array_merge($this->sinColumnas(), ['cod_unidad_medida' => $this->unidad, 'cod_bodega' => $this->bodega, 'cod_unidad_negocio' => '001']),
                $this->columnasDe($item)
            );
        }
    }

    /* --- Unidad de medida (del repuesto, V 1.4.26) ------------------------ */

    public function test_la_unidad_de_medida_enviada_se_ignora_aunque_sea_invalida(): void
    {
        $persona = $this->solicitante();
        $otraActiva = $this->maestra(UnidadMedida::class);

        foreach (['otra activa' => $otraActiva, 'inactiva' => $this->maestra(UnidadMedida::class, false), 'inexistente' => $this->codigoLibre(UnidadMedida::class), 'larga' => 'ABCDEFGHIJK', 'arreglo' => ['x']] as $caso => $valor) {
            $solicitud = $this->solicitudDe($persona);
            $item = $solicitud->items[0];

            $this->actingAs($persona, 'solicitante')
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                    'items' => [$item->id => ['cod_unidad_medida' => $valor]],
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('exito');

            $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado, "Caso {$caso}.");
            $this->assertSame($this->unidad, $item->fresh()->cod_unidad_medida, "Caso {$caso}: manda la del repuesto.");
        }

        // Tambien llamando al servicio directo, sin el FormRequest.
        $solicitud = $this->solicitudDe($persona);
        app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [], [
            $solicitud->items[0]->id => ['cod_unidad_medida' => $otraActiva],
        ]);
        $this->assertSame($this->unidad, $solicitud->items[0]->fresh()->cod_unidad_medida);
    }

    public function test_la_unidad_del_repuesto_se_guarda_con_la_clave_de_la_maestra(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        // Collation CI: el repuesto la trae en minusculas y con espacios.
        Repuesto::whereKey($this->idsRepuestos())->update(['unidad_medida' => ' '.strtolower($this->unidad).' ']);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['items' => []])
            ->assertSessionHasNoErrors();

        foreach ($solicitud->items as $item) {
            $this->assertSame($this->unidad, $item->fresh()->cod_unidad_medida);
        }
    }

    public function test_sin_unidad_activa_en_la_maestra_se_aprueba_con_null(): void
    {
        $persona = $this->solicitante();
        $inactiva = $this->maestra(UnidadMedida::class, false);
        $casos = [
            'sin fila en la maestra' => $this->codigoLibre(UnidadMedida::class),
            'inactiva' => $inactiva,
            'mas larga que la clave' => 'ABCDEFGHIJ',
            'repuesto sin unidad' => null,
        ];

        foreach ($casos as $caso => $unidad) {
            $solicitud = $this->solicitudDe($persona);
            Repuesto::whereKey($this->idsRepuestos())->update(['unidad_medida' => $unidad]);

            $html = $this->actingAs($persona, 'solicitante')
                ->get(route('solicitante.solicitudes.show', $solicitud->id))
                ->assertOk()
                ->getContent();
            $this->assertMatchesRegularExpression(
                '/<span id="'.$this->prefijo($solicitud->items[0]).'-cod_unidad_medida"[^>]*>'.preg_quote($unidad ?? 'Sin unidad', '/').'<\/span>/',
                $html,
                "Caso {$caso}: muestra lo que trae el repuesto."
            );

            $this->actingAs($persona, 'solicitante')
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                    'items' => [$solicitud->items[0]->id => ['cantidad' => '2']],
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('exito');

            $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado, "Caso {$caso}: no tumba la aprobacion.");

            foreach ($solicitud->items as $item) {
                $fresco = $item->fresh();
                $this->assertNull($fresco->cod_unidad_medida, "Caso {$caso}.");
                $this->assertSame('001', $fresco->cod_unidad_negocio);
            }
            $this->assertSame('2.000', $solicitud->items[0]->fresh()->cantidad);
        }
    }

    public function test_cada_item_toma_la_unidad_de_su_propio_repuesto(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();
        $otraUnidad = $this->maestra(UnidadMedida::class);

        $otroRepuesto = Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Otro repuesto prueba items',
            'unidad_medida' => $otraUnidad,
            'existencia' => 5,
            'stock_minimo' => 1,
            'stock_maximo' => 10,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
        $segundo->forceFill(['repuesto_id' => $otroRepuesto->id])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['items' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->unidad, $primero->fresh()->cod_unidad_medida);
        $this->assertSame($otraUnidad, $segundo->fresh()->cod_unidad_medida);
    }

    /* --- Bodega (del repuesto, V 1.4.31) ---------------------------------- */

    public function test_la_bodega_enviada_se_ignora_aunque_sea_invalida(): void
    {
        $persona = $this->solicitante();
        $otraActiva = $this->maestra(Bodega::class);
        $casos = [
            'otra activa' => $otraActiva,
            'inactiva' => $this->maestra(Bodega::class, false),
            'inexistente' => $this->codigoLibre(Bodega::class),
            'larga' => 'ABCDEFGHIJK',
            'arreglo' => ['x'],
        ];

        foreach ($casos as $caso => $valor) {
            $solicitud = $this->solicitudDe($persona);
            $item = $solicitud->items[0];

            $this->actingAs($persona, 'solicitante')
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                    'items' => [$item->id => ['cod_bodega' => $valor]],
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('exito');

            $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado, "Caso {$caso}.");
            $this->assertSame($this->bodega, $item->fresh()->cod_bodega, "Caso {$caso}: manda la del repuesto.");
        }

        // Tambien llamando al servicio directo, sin el FormRequest.
        $solicitud = $this->solicitudDe($persona);
        app(AprobacionSolicitudService::class)->aprobar($solicitud->id, $persona, [], [
            $solicitud->items[0]->id => ['cod_bodega' => $otraActiva],
        ]);
        $this->assertSame($this->bodega, $solicitud->items[0]->fresh()->cod_bodega);
    }

    public function test_la_bodega_del_repuesto_se_guarda_con_la_clave_de_la_maestra(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        // Collation CI: el repuesto la trae en minusculas y con espacios.
        Repuesto::whereKey($this->idsRepuestos())->update(['cod_bodega' => ' '.strtolower($this->bodega).' ']);

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['items' => []])
            ->assertSessionHasNoErrors();

        foreach ($solicitud->items as $item) {
            $this->assertSame($this->bodega, $item->fresh()->cod_bodega);
        }
    }

    public function test_sin_bodega_activa_en_la_maestra_se_aprueba_con_null(): void
    {
        $persona = $this->solicitante();
        $casos = [
            'sin fila en la maestra' => $this->codigoLibre(Bodega::class),
            'inactiva' => $this->maestra(Bodega::class, false),
            'repuesto sin bodega' => null,
        ];

        foreach ($casos as $caso => $bodega) {
            $solicitud = $this->solicitudDe($persona);
            Repuesto::whereKey($this->idsRepuestos())->update(['cod_bodega' => $bodega]);

            $html = $this->actingAs($persona, 'solicitante')
                ->get(route('solicitante.solicitudes.show', $solicitud->id))
                ->assertOk()
                ->getContent();
            $this->assertMatchesRegularExpression(
                '/<span id="'.$this->prefijo($solicitud->items[0]).'-cod_bodega"[^>]*>'.preg_quote($bodega ?? 'Sin bodega', '/').'<\/span>/',
                $html,
                "Caso {$caso}: muestra lo que trae el repuesto."
            );

            $this->actingAs($persona, 'solicitante')
                ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                    'items' => [$solicitud->items[0]->id => ['cantidad' => '2']],
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('exito');

            $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado, "Caso {$caso}: no tumba la aprobacion.");

            foreach ($solicitud->items as $item) {
                $fresco = $item->fresh();
                $this->assertNull($fresco->cod_bodega, "Caso {$caso}.");
                // La unidad de medida sigue resolviendose por su lado.
                $this->assertSame($this->unidad, $fresco->cod_unidad_medida, "Caso {$caso}.");
            }
            $this->assertSame('2.000', $solicitud->items[0]->fresh()->cantidad);
        }
    }

    public function test_cada_item_toma_la_bodega_de_su_propio_repuesto(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();
        $otraBodega = $this->maestra(Bodega::class);

        $otroRepuesto = Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Otro repuesto bodega items',
            'unidad_medida' => $this->unidad,
            'existencia' => 5,
            'stock_minimo' => 1,
            'stock_maximo' => 10,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
        Repuesto::whereKey($otroRepuesto->id)->update(['cod_bodega' => $otraBodega]);
        $segundo->forceFill(['repuesto_id' => $otroRepuesto->id])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['items' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->bodega, $primero->fresh()->cod_bodega);
        $this->assertSame($otraBodega, $segundo->fresh()->cod_bodega);
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
        $motivoInactivo = $this->maestra(Motivo::class, false);
        $casos = [
            // La bodega ya no se valida: sale del repuesto (V 1.4.31).
            'motivo inexistente' => ['cod_motivo', $this->codigoLibre(Motivo::class)],
            'motivo inactivo' => ['cod_motivo', $motivoInactivo],
            'centro mas largo que la clave' => ['cod_centro_operacion', 'ABCDEFGHIJK'],
            'proyecto mas largo que la clave' => ['cod_proyecto', 'ABCDEFGHIJK'],
            'motivo largo' => ['cod_motivo', 'ABCDEF'],
            'proyecto inexistente' => ['cod_proyecto', $this->codigoLibre(Proyecto::class, 10)],
            'motivo arreglo' => ['cod_motivo', ['x']],
            'cantidad cero' => ['cantidad', '0'],
            'cantidad cero decimal' => ['cantidad', '0,000'],
            'cantidad negativa' => ['cantidad', '-1'],
            'cantidad texto' => ['cantidad', 'dos'],
            'cantidad 4 decimales' => ['cantidad', '1,2345'],
            'cantidad con miles' => ['cantidad', '1.234,5'],
            'cantidad enorme' => ['cantidad', '1234567890'],
            'notas largas' => ['notas_item', str_repeat('x', 501)],
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
        $this->assertStringContainsString('id="'.$this->prefijo($item).'-cantidad-ayuda" class="invalid-feedback d-block"', $html);
        $this->assertStringContainsString('La cantidad del item 1 debe ser un numero mayor que cero', $html);
        // El campo con error esta en el renglon del item, a la vista: ya no
        // hay un bloque "Mas datos" plegable que haya que abrir.
        $this->assertStringNotContainsString('item-mas-datos', $html);
        $this->assertMatchesRegularExpression('/id="'.$this->prefijo($item).'-cantidad"[^>]*class="[^"]*is-invalid/s', $html);
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

    /* --- Unidad de negocio y Descripcion fuera del formulario (V 1.4.28) -- */

    public function test_las_listas_de_escritura_y_de_lectura_son_coherentes(): void
    {
        foreach (SolicitudItem::REFERENCIAS_APROBACION as $columna => $etiqueta) {
            $this->assertSame($etiqueta, SolicitudItem::REFERENCIAS_PARA_MOSTRAR[$columna] ?? null, $columna);
        }

        foreach (['cod_unidad_negocio', 'descripcion_item'] as $columna) {
            $this->assertArrayNotHasKey($columna, SolicitudItem::REFERENCIAS_APROBACION);
            $this->assertArrayHasKey($columna, SolicitudItem::REFERENCIAS_PARA_MOSTRAR);
        }

        // Cada lista con su propio orden (V 1.4.29): la de edicion pone la
        // cantidad aprobada junto a la solicitada; la de lectura no cambio.
        $this->assertSame(
            ['cod_unidad_medida', 'cantidad', 'cod_bodega', 'cod_motivo', 'cod_centro_operacion', 'cod_centro_de_costo', 'cod_proyecto', 'notas_item'],
            array_keys(SolicitudItem::REFERENCIAS_APROBACION)
        );
        $this->assertSame(
            ['cod_motivo', 'cod_bodega', 'cod_unidad_medida', 'cod_centro_operacion', 'cod_centro_de_costo', 'cantidad', 'cod_unidad_negocio', 'cod_proyecto', 'notas_item', 'descripcion_item'],
            array_keys(SolicitudItem::REFERENCIAS_PARA_MOSTRAR)
        );
    }

    public function test_aprobar_sigue_imponiendo_la_unidad_de_negocio_y_no_escribe_la_descripcion(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        [$primero, $segundo] = $solicitud->items->all();

        // Un valor previo (de antes de V 1.4.28) en el primero; el segundo sin nada.
        $primero->forceFill(['descripcion_item' => 'Previa '.$this->marca])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), [
                'items' => [
                    $primero->id => ['descripcion_item' => 'Nueva '.$this->marca, 'cod_unidad_negocio' => '999'],
                    // Mas de 500 caracteres: ya no se valida, se ignora y no tumba nada.
                    $segundo->id => ['descripcion_item' => str_repeat('x', 501)],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $this->assertSame(Solicitud::ESTADO_PENDIENTE, $solicitud->fresh()->estado);
        $this->assertSame('Previa '.$this->marca, $primero->fresh()->descripcion_item, 'Se conserva el valor previo.');
        $this->assertNull($segundo->fresh()->descripcion_item);
        $this->assertSame(SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO, $primero->fresh()->cod_unidad_negocio, 'Se ignora el 999.');
        $this->assertSame(SolicitudItem::UNIDAD_NEGOCIO_POR_DEFECTO, $segundo->fresh()->cod_unidad_negocio);

        // Tambien llamando al servicio directo, sin el FormRequest.
        $otra = $this->solicitudDe($persona);
        $otra->items[0]->forceFill(['descripcion_item' => 'Previa servicio'])->save();
        app(AprobacionSolicitudService::class)->aprobar($otra->id, $persona, [], [
            $otra->items[0]->id => ['descripcion_item' => 'Intento', 'cod_unidad_negocio' => '999'],
        ]);
        $this->assertSame('Previa servicio', $otra->items[0]->fresh()->descripcion_item);
        $this->assertSame('001', $otra->items[0]->fresh()->cod_unidad_negocio);
    }

    public function test_la_descripcion_previa_se_sigue_mostrando_en_solo_lectura(): void
    {
        $persona = $this->solicitante();
        $solicitud = $this->solicitudDe($persona);
        $solicitud->items[0]->forceFill(['descripcion_item' => 'Historica '.$this->marca])->save();

        $this->actingAs($persona, 'solicitante')
            ->post(route('solicitante.solicitudes.aprobar', $solicitud->id), ['items' => []])
            ->assertSessionHasNoErrors();

        $portal = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee('Historica '.$this->marca)
            ->getContent();
        $this->assertSame(1, substr_count($portal, '<dt>Descripcion</dt>'));
        $this->assertSame(2, substr_count($portal, '<dt>Unidad de negocio</dt>'));

        $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee('Historica '.$this->marca)
            ->assertSee('Unidad de negocio');
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

        // La bodega es la del repuesto (V 1.4.31), no la enviada.
        $textoBodega = $this->bodega.' - Maestra '.$this->marca.' '.$this->bodega;

        $portal = $this->actingAs($persona, 'solicitante')
            ->get(route('solicitante.solicitudes.show', $solicitud->id))
            ->assertOk()
            ->assertSee($textoBodega)
            ->assertSee('Nota '.$this->marca)
            ->assertSee('2,5')
            ->assertDontSee('name="items[', false)
            ->assertDontSee('item-mas-datos', false)
            ->getContent();

        // El segundo item solo tiene lo impuesto (unidad de negocio, unidad de
        // medida y bodega del repuesto): no se pintan renglones vacios.
        $this->assertSame(2, substr_count($portal, '<dt>Bodega</dt>'));
        $this->assertSame(1, substr_count($portal, '<dt>Motivo</dt>'));
        $this->assertSame(2, substr_count($portal, '<dt>Unidad de negocio</dt>'));

        // El orden de solo lectura NO cambio con V 1.4.29 (sale de
        // REFERENCIAS_PARA_MOSTRAR): Cantidad sigue despues de Centro de costo.
        $ordenLectura = '/<dt>Motivo<\/dt>.*?<dt>Bodega<\/dt>.*?<dt>Und\. medida<\/dt>.*?<dt>Centro de op\.<\/dt>.*?<dt>Centro de costo<\/dt>.*?<dt>Cantidad<\/dt>.*?<dt>Unidad de negocio<\/dt>.*?<dt>Proyecto<\/dt>.*?<dt>Notas<\/dt>/s';
        $this->assertMatchesRegularExpression($ordenLectura, $portal);

        $panel = $this->actingAs($this->admin(), 'web')
            ->get(route('admin.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSee($textoBodega)
            ->assertSee($datos['cod_proyecto'].' - Maestra '.$this->marca)
            ->getContent();
        $this->assertMatchesRegularExpression($ordenLectura, $panel);

        // Vista publica (mismo partial que /consultar): sin referencias por item.
        $this->withSession(['solicitud_recien_creada' => $solicitud->numero])
            ->get(route('solicitudes.confirmacion', $solicitud->numero))
            ->assertOk()
            ->assertDontSee($textoBodega)
            ->assertDontSee('Nota '.$this->marca)
            ->assertDontSee('referencias-item', false);
    }
}
