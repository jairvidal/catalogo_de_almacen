<?php

namespace Tests\Feature;

use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Filtros por columna y orden de las bandejas de solicitudes del panel
 * (admin.solicitudes.index y admin.solicitudes.listos).
 *
 * La base real ya tiene solicitudes, asi que cada prueba marca las suyas con un
 * texto unico en el nombre del solicitante y filtra por el: el resto de los
 * filtros y el orden se comprueban DENTRO de ese conjunto conocido.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase.
 */
class SolicitudesFiltrosOrdenTest extends TestCase
{
    use DatabaseTransactions;

    private string $marca;

    private ?Repuesto $repuesto = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marca = 'Filtro'.uniqid();
    }

    private function administrador(): User
    {
        return $this->usuario('Admin de prueba', 'admin');
    }

    private function usuario(string $nombre, string $clave = 'almacenista'): User
    {
        return User::create([
            'name' => $nombre,
            'email' => 'filtros.prueba.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => $clave,
            'rol_id' => Rol::where('col_clave', $clave)->value('id'),
            'activo' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function solicitud(string $sufijo, array $datos = [], int $items = 1): Solicitud
    {
        $solicitud = Solicitud::create(array_merge([
            // Marca no numerica: no entra en el MAX() del consecutivo.
            'numero' => 'TST-'.$this->marca.'-'.$sufijo,
            'solicitante_nombre' => $this->marca.' '.$sufijo,
            'solicitante_cedula' => '100'.random_int(100000, 999999),
            'solicitante_email' => 'filtros.prueba@sidocsa.com',
            'estado' => Solicitud::ESTADO_PENDIENTE,
        ], $datos));

        for ($i = 0; $i < $items; $i++) {
            SolicitudItem::create([
                'solicitud_id' => $solicitud->id,
                'repuesto_id' => $this->repuesto()->id,
                'codigo' => $this->repuesto()->codigo,
                'nombre' => 'Item de prueba',
                'cantidad_solicitada' => 1,
            ]);
        }

        return $solicitud;
    }

    private function repuesto(): Repuesto
    {
        return $this->repuesto ??= Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba filtros',
            'unidad_medida' => 'UND',
            'existencia' => 10,
            'stock_minimo' => 1,
            'stock_maximo' => 20,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * @param  array<string, string>  $parametros
     */
    private function listado(array $parametros, string $ruta = 'admin.solicitudes.index'): TestResponse
    {
        return $this->actingAs($this->administrador())
            ->get(route($ruta, $parametros))
            ->assertOk();
    }

    /**
     * @return list<int>
     */
    private function ids(TestResponse $respuesta): array
    {
        return $respuesta->viewData('solicitudes')->pluck('id')->all();
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    public function test_el_buscador_general_ya_no_se_pinta(): void
    {
        $respuesta = $this->listado([]);

        $respuesta->assertDontSee('name="q"', false);
        $respuesta->assertSee('name="numero"', false);
        $respuesta->assertSee('name="solicitante"', false);
        $respuesta->assertSee('name="items"', false);
        $respuesta->assertSee('name="estado"', false);
        $respuesta->assertSee('name="atendida"', false);
    }

    public function test_filtra_por_numero_parcial(): void
    {
        $a = $this->solicitud('A');
        $this->solicitud('B');

        $respuesta = $this->listado(['numero' => $this->marca.'-A']);

        $this->assertSame([$a->id], $this->ids($respuesta));
    }

    public function test_filtra_por_numero_normalizado_en_cualquiera_de_sus_formatos(): void
    {
        $maximo = (int) Solicitud::query()
            ->where('numero', 'like', '[0-9][0-9][0-9][0-9][0-9][0-9]')
            ->max('numero');
        $numero = str_pad((string) ($maximo + 70), 6, '0', STR_PAD_LEFT);

        $solicitud = $this->solicitud('N', ['numero' => $numero]);

        foreach ([$numero, ltrim($numero, '0'), 'SOL-2026-'.$numero] as $escrito) {
            $this->assertSame([$solicitud->id], $this->ids($this->listado(['numero' => $escrito])), $escrito);
        }
    }

    public function test_filtra_por_solicitante_en_nombre_cedula_y_area(): void
    {
        $a = $this->solicitud('A', ['solicitante_cedula' => '7'.random_int(10000000, 99999999)]);
        $b = $this->solicitud('B', ['solicitante_area' => 'Area '.$this->marca]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($this->listado(['solicitante' => $this->marca])));
        $this->assertSame([$a->id], $this->ids($this->listado(['solicitante' => $a->solicitante_cedula])));
        $this->assertSame([$b->id], $this->ids($this->listado(['solicitante' => 'Area '.$this->marca])));
    }

    public function test_el_filtro_de_solicitante_escapa_los_comodines_de_like(): void
    {
        $this->solicitud('A');

        // Sin el escape, "%" coincidiria con todo.
        $this->assertSame([], $this->ids($this->listado(['solicitante' => $this->marca.'%'])));
    }

    public function test_filtra_por_cantidad_de_items(): void
    {
        $this->solicitud('A', [], 1);
        $tres = $this->solicitud('B', [], 3);

        $respuesta = $this->listado(['solicitante' => $this->marca, 'items' => '3']);

        $this->assertSame([$tres->id], $this->ids($respuesta));
    }

    public function test_un_filtro_de_items_no_numerico_se_ignora(): void
    {
        $a = $this->solicitud('A', [], 1);
        $b = $this->solicitud('B', [], 2);

        $respuesta = $this->listado(['solicitante' => $this->marca, 'items' => 'abc']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($respuesta));
    }

    public function test_filtra_por_estado(): void
    {
        $this->solicitud('A');
        $lista = $this->solicitud('B', ['estado' => Solicitud::ESTADO_LISTO]);

        $respuesta = $this->listado(['solicitante' => $this->marca, 'estado' => Solicitud::ESTADO_LISTO]);

        $this->assertSame([$lista->id], $this->ids($respuesta));
    }

    public function test_un_estado_desconocido_equivale_a_todos(): void
    {
        $a = $this->solicitud('A');
        $b = $this->solicitud('B', ['estado' => Solicitud::ESTADO_LISTO]);

        $respuesta = $this->listado(['solicitante' => $this->marca, 'estado' => 'inventado']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($respuesta));
    }

    public function test_filtra_por_quien_atendio(): void
    {
        $usuario = $this->usuario('Atiende '.$this->marca);
        $this->solicitud('A');
        $atendida = $this->solicitud('B', ['atendida_por' => $usuario->id, 'estado' => Solicitud::ESTADO_EN_PROCESO]);

        $respuesta = $this->listado(['atendida' => 'Atiende '.$this->marca]);

        $this->assertSame([$atendida->id], $this->ids($respuesta));
    }

    // ------------------------------------------------------------------
    // Orden
    // ------------------------------------------------------------------

    public function test_ordena_por_numero_asc_y_desc(): void
    {
        $b = $this->solicitud('B');
        $a = $this->solicitud('A');
        $c = $this->solicitud('C');

        $asc = $this->listado(['solicitante' => $this->marca, 'orden' => 'numero', 'direccion' => 'asc']);
        $desc = $this->listado(['solicitante' => $this->marca, 'orden' => 'numero', 'direccion' => 'desc']);

        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($asc));
        $this->assertSame([$c->id, $b->id, $a->id], $this->ids($desc));
    }

    public function test_ordena_por_solicitante(): void
    {
        $c = $this->solicitud('C');
        $a = $this->solicitud('A');

        $respuesta = $this->listado(['solicitante' => $this->marca, 'orden' => 'solicitante', 'direccion' => 'desc']);

        $this->assertSame([$c->id, $a->id], $this->ids($respuesta));
    }

    public function test_ordena_por_cantidad_de_items(): void
    {
        $dos = $this->solicitud('A', [], 2);
        $tres = $this->solicitud('B', [], 3);
        $uno = $this->solicitud('C', [], 1);

        $asc = $this->listado(['solicitante' => $this->marca, 'orden' => 'items', 'direccion' => 'asc']);
        $desc = $this->listado(['solicitante' => $this->marca, 'orden' => 'items', 'direccion' => 'desc']);

        $this->assertSame([$uno->id, $dos->id, $tres->id], $this->ids($asc));
        $this->assertSame([$tres->id, $dos->id, $uno->id], $this->ids($desc));
    }

    public function test_ordena_por_estado_segun_el_avance_del_flujo(): void
    {
        $entregada = $this->solicitud('A', ['estado' => Solicitud::ESTADO_ENTREGADA]);
        $pendiente = $this->solicitud('B', ['estado' => Solicitud::ESTADO_PENDIENTE]);
        $lista = $this->solicitud('C', ['estado' => Solicitud::ESTADO_LISTO]);

        $asc = $this->listado(['solicitante' => $this->marca, 'orden' => 'estado', 'direccion' => 'asc']);
        $desc = $this->listado(['solicitante' => $this->marca, 'orden' => 'estado', 'direccion' => 'desc']);

        $this->assertSame([$pendiente->id, $lista->id, $entregada->id], $this->ids($asc));
        $this->assertSame([$entregada->id, $lista->id, $pendiente->id], $this->ids($desc));
    }

    public function test_ordena_por_nombre_de_quien_atendio(): void
    {
        $zeta = $this->usuario('Zeta '.$this->marca);
        $alfa = $this->usuario('Alfa '.$this->marca);
        $deZeta = $this->solicitud('A', ['atendida_por' => $zeta->id, 'estado' => Solicitud::ESTADO_EN_PROCESO]);
        $deAlfa = $this->solicitud('B', ['atendida_por' => $alfa->id, 'estado' => Solicitud::ESTADO_EN_PROCESO]);

        $asc = $this->listado(['solicitante' => $this->marca, 'orden' => 'atendida', 'direccion' => 'asc']);
        $desc = $this->listado(['solicitante' => $this->marca, 'orden' => 'atendida', 'direccion' => 'desc']);

        $this->assertSame([$deAlfa->id, $deZeta->id], $this->ids($asc));
        $this->assertSame([$deZeta->id, $deAlfa->id], $this->ids($desc));
    }

    public function test_un_orden_fuera_de_la_lista_blanca_no_revienta_y_cae_al_defecto(): void
    {
        $entregada = $this->solicitud('A', ['estado' => Solicitud::ESTADO_ENTREGADA]);
        $pendiente = $this->solicitud('B', ['estado' => Solicitud::ESTADO_PENDIENTE]);

        $respuesta = $this->listado([
            'solicitante' => $this->marca,
            'orden' => 'numero; drop table solicitudes',
            'direccion' => 'hacia un lado',
        ]);

        $respuesta->assertViewHas('orden', null);
        $respuesta->assertViewHas('direccion', 'asc');
        // Orden por defecto: por avance del flujo, pendientes primero.
        $this->assertSame([$pendiente->id, $entregada->id], $this->ids($respuesta));
    }

    public function test_los_enlaces_de_orden_conservan_los_filtros_y_la_flecha_activa_se_marca(): void
    {
        $this->solicitud('A');

        $respuesta = $this->listado(['solicitante' => $this->marca, 'orden' => 'numero', 'direccion' => 'asc']);

        // El enlace de la columna activa invierte la direccion y conserva el filtro.
        $respuesta->assertSee(route('admin.solicitudes.index', [
            'solicitante' => $this->marca,
            'orden' => 'numero',
            'direccion' => 'desc',
        ]));
        $respuesta->assertSee('aria-sort="ascending"', false);
        $respuesta->assertSee('bi-caret-up-fill activa', false);
        // Y el formulario de filtros conserva el orden.
        $respuesta->assertSee('<input type="hidden" name="orden" value="numero">', false);
    }

    // ------------------------------------------------------------------
    // Bandeja Listos
    // ------------------------------------------------------------------

    public function test_la_bandeja_de_listos_ignora_el_estado_de_la_url_y_no_pinta_tarjetas(): void
    {
        $lista = $this->solicitud('A', ['estado' => Solicitud::ESTADO_LISTO]);
        $this->solicitud('B', ['estado' => Solicitud::ESTADO_PENDIENTE]);

        $respuesta = $this->listado([
            'solicitante' => $this->marca,
            'estado' => Solicitud::ESTADO_PENDIENTE,
            'orden' => 'numero',
        ], 'admin.solicitudes.listos');

        $this->assertSame([$lista->id], $this->ids($respuesta));
        $respuesta->assertDontSee('tarjeta-metrica');
        // El select de estado solo muestra el estado fijo, deshabilitado y sin name.
        $respuesta->assertDontSee('name="estado"', false);
        // Los enlaces de orden no arrastran un estado que la ruta no admite.
        $respuesta->assertViewHas('consulta', fn (array $consulta) => ! array_key_exists('estado', $consulta));
    }
}
