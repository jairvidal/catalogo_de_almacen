<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\User;
use App\Services\InventarioApiSidocsa;
use App\Services\SincronizadorStockRepuestos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 */
class ParametroAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'admin.parametro.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function almacenista(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ALMACENISTA)->firstOrFail();

        return User::create([
            'name' => 'Almacenista de prueba',
            'email' => 'almacenista.parametro.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function nuevoParametro(array $atributos = [], bool $sistema = false): Parametro
    {
        $parametro = new Parametro(array_merge([
            'col_nombre' => 'prueba.'.substr(uniqid(), -8),
            'col_valor' => 'valor de prueba',
            'col_estado' => Parametro::ESTADO_ACTIVO,
            'col_descripcion' => 'Creado por las pruebas automaticas.',
        ], $atributos));

        // col_sistema no es fillable a proposito: no llega del formulario.
        $parametro->col_sistema = $sistema;
        $parametro->save();

        return $parametro;
    }

    public function test_el_admin_crea_un_parametro(): void
    {
        $nombre = 'prueba.creado_'.substr(uniqid(), -6);

        $respuesta = $this->actingAs($this->admin())->post(route('admin.parametros.store'), [
            'col_nombre' => $nombre,
            'col_valor' => '15',
            'col_estado' => Parametro::ESTADO_ACTIVO,
            'col_descripcion' => 'Un parametro cualquiera.',
        ]);

        $respuesta->assertRedirect(route('admin.parametros.index'));
        $respuesta->assertSessionHas('exito');

        $parametro = Parametro::where('col_nombre', $nombre)->first();

        $this->assertNotNull($parametro);
        $this->assertSame(Parametro::ESTADO_ACTIVO, $parametro->col_estado);
        // col_sistema nunca llega del formulario.
        $this->assertFalse($parametro->col_sistema);
    }

    public function test_el_almacenista_no_entra_al_modulo_de_parametros(): void
    {
        $this->actingAs($this->almacenista())
            ->get(route('admin.parametros.index'))
            ->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('admin.parametros.index'))->assertRedirect(route('admin.login'));
    }

    public function test_el_nombre_no_se_repite(): void
    {
        $existente = $this->nuevoParametro();

        $this->actingAs($this->admin())
            ->post(route('admin.parametros.store'), [
                'col_nombre' => $existente->col_nombre,
                'col_valor' => 'otro',
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ])
            ->assertSessionHasErrors('col_nombre');
    }

    public function test_el_estado_solo_admite_activo_o_inactivo(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.parametros.store'), [
                'col_nombre' => 'prueba.estado_'.substr(uniqid(), -6),
                'col_valor' => 'x',
                'col_estado' => 'apagado',
            ])
            ->assertSessionHasErrors('col_estado');
    }

    /**
     * col_valor esta fuera del middleware TrimStrings: el espacio final es parte
     * del valor y ni el formulario ni el lector lo pueden recortar. Sigue siendo
     * la regla general para cualquier parametro de texto libre; los criterios de
     * la API ya no dependen de ella porque son IDs numericos.
     */
    public function test_el_valor_conserva_los_espacios_del_final(): void
    {
        $nombre = 'prueba.espacio_'.substr(uniqid(), -6);

        $this->actingAs($this->admin())
            ->post(route('admin.parametros.store'), [
                'col_nombre' => $nombre,
                'col_valor' => 'SEGURIDAD ',
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SEGURIDAD ', Parametro::where('col_nombre', $nombre)->value('col_valor'));
        $this->assertSame('SEGURIDAD ', Parametro::valor($nombre));
    }

    public function test_modificar_un_parametro_del_sistema_no_cambia_su_nombre_ni_su_estado(): void
    {
        $sistema = $this->nuevoParametro(['col_valor' => 'original'], sistema: true);
        $nombreOriginal = $sistema->col_nombre;

        $this->actingAs($this->admin())
            ->put(route('admin.parametros.update', $sistema), [
                'col_nombre' => 'prueba.nombre_nuevo',
                'col_valor' => 'cambiado',
                'col_estado' => Parametro::ESTADO_INACTIVO,
                'col_descripcion' => 'Descripcion nueva.',
            ])
            ->assertRedirect(route('admin.parametros.index'));

        $sistema->refresh();

        $this->assertSame($nombreOriginal, $sistema->col_nombre);
        $this->assertSame(Parametro::ESTADO_ACTIVO, $sistema->col_estado);
        // El valor y la descripcion si son editables.
        $this->assertSame('cambiado', $sistema->col_valor);
        $this->assertSame('Descripcion nueva.', $sistema->col_descripcion);
    }

    public function test_no_se_anula_un_parametro_del_sistema(): void
    {
        $sistema = $this->nuevoParametro([], sistema: true);

        $this->actingAs($this->admin())
            ->delete(route('admin.parametros.destroy', $sistema))
            ->assertSessionHas('error');

        $this->assertSame(Parametro::ESTADO_ACTIVO, $sistema->refresh()->col_estado);
    }

    public function test_se_anula_un_parametro_que_no_es_del_sistema(): void
    {
        $parametro = $this->nuevoParametro();

        $this->actingAs($this->admin())
            ->delete(route('admin.parametros.destroy', $parametro))
            ->assertSessionHas('exito');

        $this->assertSame(Parametro::ESTADO_INACTIVO, $parametro->refresh()->col_estado);
    }

    public function test_un_parametro_inactivo_no_se_lee(): void
    {
        $parametro = $this->nuevoParametro([
            'col_valor' => 'no me leas',
            'col_estado' => Parametro::ESTADO_INACTIVO,
        ]);

        $this->assertNull(Parametro::valor($parametro->col_nombre));
        $this->assertSame('respaldo', Parametro::valor($parametro->col_nombre, 'respaldo'));
    }

    /**
     * La credencial no puede salir en el HTML: ni en el listado ni en el
     * formulario de edicion.
     */
    public function test_la_credencial_no_aparece_en_el_html(): void
    {
        $codigo = Parametro::where('col_nombre', 'codigo_api')->firstOrFail();
        $codigo->col_valor = 'CLAVE-SECRETA-DE-PRUEBA';
        $codigo->save();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.parametros.index', ['q' => 'codigo_api']))
            ->assertOk()
            ->assertDontSee('CLAVE-SECRETA-DE-PRUEBA');

        $this->actingAs($admin)
            ->get(route('admin.parametros.edit', $codigo))
            ->assertOk()
            ->assertDontSee('CLAVE-SECRETA-DE-PRUEBA');
    }

    /**
     * Como el formulario no trae la credencial, guardar con el campo vacio no
     * puede borrarla.
     */
    public function test_guardar_la_credencial_vacia_conserva_la_actual(): void
    {
        $codigo = Parametro::where('col_nombre', 'codigo_api')->firstOrFail();
        $codigo->col_valor = 'CLAVE-VIGENTE';
        $codigo->save();

        $this->actingAs($this->admin())
            ->put(route('admin.parametros.update', $codigo), [
                'col_nombre' => $codigo->col_nombre,
                'col_valor' => '',
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $codigo->col_descripcion,
            ])
            ->assertRedirect(route('admin.parametros.index'));

        $this->assertSame('CLAVE-VIGENTE', $codigo->refresh()->col_valor);
    }

    /*
    |--------------------------------------------------------------------------
    | inv.actualizar: modo automatico / manual y boton "Actualizar"
    |--------------------------------------------------------------------------
    */

    /**
     * Deja el modulo de parametros listo para sincronizar y devuelve el
     * parametro del modo. Toda la API se responde con Http::fake(): nunca se
     * llama al endpoint real.
     */
    private function configurarSincronizacion(string $modo): Parametro
    {
        Cache::forget(InventarioApiSidocsa::CLAVE_CACHE_TOKEN);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA);
        Cache::forget(SincronizadorStockRepuestos::CLAVE_EN_CURSO);

        foreach ([
            'codigo_api' => 'CODIGO-DE-PRUEBA',
            'tiempo.actualizar' => '60',
            'api.id_bod' => 'P0001',
            'api.id_cia' => '1',
            'api.criterio_2' => '2002',
            Parametro::INV_ACTUALIZAR => $modo,
        ] as $nombre => $valor) {
            $parametro = Parametro::firstOrNew(['col_nombre' => $nombre]);
            $parametro->col_valor = $valor;
            $parametro->col_estado = Parametro::ESTADO_ACTIVO;
            $parametro->save();
        }

        return Parametro::where('col_nombre', Parametro::INV_ACTUALIZAR)->firstOrFail();
    }

    /**
     * Codigo numerico de 9 digitos: el cruce con el ERP es numerico y el
     * catalogo real usa 7 digitos, asi que no choca con ningun repuesto de
     * verdad ni colisiona al convertirlo a entero (mismo criterio que
     * SincronizarStockTest).
     */
    private function repuestoDePrueba(float $existencia = 7, float $stock = 0): Repuesto
    {
        $codigo = random_int(900000000, 949999999);

        return Repuesto::create([
            'codigo' => $codigo,
            'nombre' => 'Repuesto de prueba '.$codigo,
            'unidad_medida' => 'UND',
            'existencia' => $existencia,
            'stock' => $stock,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    public function test_inv_actualizar_solo_acepta_automatico_o_manual(): void
    {
        $modo = $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);
        $admin = $this->admin();

        // Un valor fuera de la lista cerrada se rechaza en el servidor, no solo
        // en el HTML del radio.
        $this->actingAs($admin)
            ->put(route('admin.parametros.update', $modo), [
                'col_nombre' => $modo->col_nombre,
                'col_valor' => 'a_ratos',
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $modo->col_descripcion,
            ])
            ->assertSessionHasErrors('col_valor');

        $this->assertSame(Parametro::INV_AUTOMATICO, $modo->refresh()->col_valor);

        // Y las dos opciones legitimas si entran.
        $this->actingAs($admin)
            ->put(route('admin.parametros.update', $modo), [
                'col_nombre' => $modo->col_nombre,
                'col_valor' => Parametro::INV_MANUAL,
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $modo->col_descripcion,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Parametro::INV_MANUAL, $modo->refresh()->col_valor);
    }

    /**
     * Los criterios de la API son IDs numericos: un nombre de grupo se rechaza
     * EN EL SERVIDOR, no solo con la ayuda del formulario. Es lo que impide que
     * el administrador deje la sincronizacion consultando sin filtro sin darse
     * cuenta.
     */
    public function test_los_criterios_de_la_api_solo_aceptan_ids_numericos(): void
    {
        $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);
        $criterio = Parametro::where('col_nombre', Parametro::API_CRITERIO_2)->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.parametros.update', $criterio), [
                'col_nombre' => $criterio->col_nombre,
                'col_valor' => 'ELECTRICO,MATERIAS PRIMAS',
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $criterio->col_descripcion,
            ])
            ->assertSessionHasErrors('col_valor');

        $this->assertSame('2002', $criterio->refresh()->col_valor);

        // Una lista de IDs si entra, con espacios alrededor incluidos: los
        // recorta Parametro::listaEnteros().
        $this->actingAs($admin)
            ->put(route('admin.parametros.update', $criterio), [
                'col_nombre' => $criterio->col_nombre,
                'col_valor' => '2002, 2010',
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $criterio->col_descripcion,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([2002, 2010], Parametro::listaEnteros(Parametro::API_CRITERIO_2));

        // Y el vacio tambien: significa "sin filtro".
        $this->actingAs($admin)
            ->put(route('admin.parametros.update', $criterio), [
                'col_nombre' => $criterio->col_nombre,
                'col_valor' => '',
                'col_estado' => Parametro::ESTADO_ACTIVO,
                'col_descripcion' => $criterio->col_descripcion,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], Parametro::listaEnteros(Parametro::API_CRITERIO_2));
    }

    public function test_el_formulario_dice_que_los_criterios_son_ids_numericos(): void
    {
        $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);
        $criterio = Parametro::where('col_nombre', Parametro::API_CRITERIO_2)->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('admin.parametros.edit', $criterio))
            ->assertOk()
            ->assertSee('IDs numericos del ERP separados por coma')
            ->assertSee('3038,1230');
    }

    public function test_el_formulario_pinta_radios_para_inv_actualizar(): void
    {
        $modo = $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);

        $respuesta = $this->actingAs($this->admin())
            ->get(route('admin.parametros.edit', $modo))
            ->assertOk();

        $respuesta->assertSee('id="col_valor_'.Parametro::INV_AUTOMATICO.'"', false);
        $respuesta->assertSee('id="col_valor_'.Parametro::INV_MANUAL.'"', false);
        $respuesta->assertSee('type="radio"', false);
        // No hay campo de texto libre para este parametro.
        $respuesta->assertDontSee('id="col_valor"', false);
    }

    public function test_el_formulario_de_un_parametro_normal_sigue_siendo_texto_libre(): void
    {
        $parametro = $this->nuevoParametro();

        $this->actingAs($this->admin())
            ->get(route('admin.parametros.edit', $parametro))
            ->assertOk()
            ->assertSee('id="col_valor"', false)
            ->assertDontSee('id="col_valor_'.Parametro::INV_MANUAL.'"', false);
    }

    public function test_el_boton_actualizar_solo_aparece_en_modo_manual(): void
    {
        $admin = $this->admin();

        $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);
        $this->actingAs($admin)
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->assertDontSee(route('admin.parametros.sincronizar'));

        $this->configurarSincronizacion(Parametro::INV_MANUAL);
        $this->actingAs($admin)
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->assertSee(route('admin.parametros.sincronizar'))
            ->assertSee('data-sincronizar-stock', false);
    }

    public function test_el_almacenista_no_dispara_la_sincronizacion_manual(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);
        Http::fake();

        $this->actingAs($this->almacenista())
            ->post(route('admin.parametros.sincronizar'))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * La misma regla que sostiene la corrida programada: el ERP alimenta stock
     * y jamas el saldo operativo del almacen.
     */
    public function test_la_sincronizacion_manual_escribe_stock_y_no_existencia(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);
        $repuesto = $this->repuestoDePrueba(existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => $repuesto->codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertOk();

        $respuesta->assertJson(['ok' => true, 'actualizados' => 1]);

        $repuesto->refresh();

        $this->assertSame(250.0, $repuesto->stock);
        $this->assertSame(7.0, $repuesto->existencia);

        // La corrida manual tambien deja la marca de la ultima corrida y suelta
        // la de "en curso".
        $this->assertNotNull(Cache::get(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA));
        $this->assertFalse(Cache::has(SincronizadorStockRepuestos::CLAVE_EN_CURSO));
    }

    /**
     * La fecha de la corrida vuelve en el JSON y en hora de Colombia: es lo que
     * app.js pinta en [data-sincronizar-ultima] para que el administrador vea
     * moverse la marca sin recargar. Con la zona de la aplicacion (UTC) salia
     * cinco horas adelantada y se leia como una fecha que no se habia movido.
     */
    public function test_la_sincronizacion_manual_devuelve_la_fecha_de_la_corrida(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);
        $repuesto = $this->repuestoDePrueba(existencia: 7, stock: 0);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => $repuesto->codigo, 'cant_disp' => 250],
            ]]),
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertOk();

        $marca = Cache::get(SincronizadorStockRepuestos::CLAVE_ULTIMA_CORRIDA);
        $esperada = Carbon::createFromTimestamp((int) $marca, 'America/Bogota')->format('d/m/Y h:i a');

        $respuesta->assertJson(['ok' => true, 'ultima' => $esperada]);

        // Y el panel trae el nodo que app.js reescribe con esa fecha.
        $this->actingAs($this->admin())
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->assertSee('data-sincronizar-ultima', false)
            ->assertSee($esperada);
    }

    /**
     * El candado es lo que impide que el boton y el programador se pisen: con
     * una corrida tomada, la peticion manual no lanza una segunda ni llama a la
     * API.
     */
    public function test_no_se_lanza_una_segunda_sincronizacion_si_ya_hay_una_en_curso(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);
        $repuesto = $this->repuestoDePrueba(existencia: 7, stock: 3);

        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'token-1']),
            '*/api/v1/inventario/consultar' => Http::response(['data' => [
                ['item' => $repuesto->codigo, 'cant_disp' => 999],
            ]]),
        ]);

        // Otra corrida tiene el candado tomado.
        $candado = Cache::lock(SincronizadorStockRepuestos::CLAVE_CANDADO, 60);
        $this->assertTrue($candado->get());

        try {
            $this->actingAs($this->admin())
                ->postJson(route('admin.parametros.sincronizar'))
                ->assertStatus(409)
                ->assertJson(['ok' => false]);
        } finally {
            $candado->release();
        }

        Http::assertNothingSent();
        $this->assertSame(3.0, $repuesto->refresh()->stock);
    }

    /**
     * En modo automatico la corrida es del programador: el boton no se pinta y
     * el servidor tampoco la deja disparar a mano.
     */
    public function test_la_sincronizacion_manual_se_rechaza_en_modo_automatico(): void
    {
        $this->configurarSincronizacion(Parametro::INV_AUTOMATICO);
        Http::fake();

        $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertStatus(409)
            ->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    /**
     * Un fallo de la API sale legible y sin credenciales: ni el codigo_api ni
     * el token pueden aparecer en la respuesta.
     */
    public function test_un_fallo_de_la_api_no_filtra_la_credencial(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);

        Http::fake([
            '*/api/v1/token' => Http::response(['error' => 'Codigo invalido.'], 401),
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertStatus(502)
            ->assertJson(['ok' => false]);

        $respuesta->assertDontSee('CODIGO-DE-PRUEBA');

        // Y el candado quedo suelto: el boton vuelve a servir enseguida.
        $this->assertFalse(Cache::has(SincronizadorStockRepuestos::CLAVE_EN_CURSO));
    }

    /**
     * El caso que motivo el boton: una corrida que murio de golpe (IIS cortando
     * la peticion) deja el candado y la marca puestos, porque el `finally` que
     * los suelta no llega a correr. A partir de ahi cada intento rebota con 409
     * sin llamar al ERP, y antes de esto la unica salida era borrar filas de
     * cache_locks a mano.
     */
    public function test_liberar_el_bloqueo_deja_volver_a_sincronizar(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);

        // Se simula el proceso muerto a medias: candado tomado y marca escrita,
        // sin nadie que las suelte.
        Cache::lock(SincronizadorStockRepuestos::CLAVE_CANDADO, 900)->get();
        Cache::put(SincronizadorStockRepuestos::CLAVE_EN_CURSO, now()->getTimestamp(), 900);

        // El fake se arma UNA sola vez: un Http::fake() sin argumentos deja un
        // comodin que gana sobre los stubs que se registren despues.
        Http::fake([
            '*/api/v1/token' => Http::response(['token' => 'tok']),
            '*/api/v1/inventario/consultar' => Http::response(['resultado' => []]),
        ]);

        // Con el bloqueo puesto, el boton "Actualizar" ni siquiera llama al ERP.
        $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertStatus(409);

        Http::assertNothingSent();

        $this->actingAs($this->admin())
            ->post(route('admin.parametros.liberar'))
            ->assertRedirect();

        $this->assertFalse(Cache::has(SincronizadorStockRepuestos::CLAVE_EN_CURSO));

        // Y ahora si: la sincronizacion vuelve a correr de verdad.
        $this->actingAs($this->admin())
            ->postJson(route('admin.parametros.sincronizar'))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /** Liberar el bloqueo es editar: el almacenista no puede. */
    public function test_el_almacenista_no_puede_liberar_el_bloqueo(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);

        $this->actingAs($this->almacenista())
            ->post(route('admin.parametros.liberar'))
            ->assertForbidden();
    }

    /**
     * La tarjeta dice DESDE CUANDO lleva la corrida: "en curso" a secas no
     * distingue una que acaba de arrancar de un candado colgado.
     */
    public function test_la_tarjeta_dice_desde_cuando_lleva_la_corrida(): void
    {
        $this->configurarSincronizacion(Parametro::INV_MANUAL);

        Cache::put(SincronizadorStockRepuestos::CLAVE_EN_CURSO, now()->getTimestamp(), 900);

        $this->actingAs($this->admin())
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->assertSee('Sincronizacion en curso desde las')
            ->assertSee('Liberar bloqueo');
    }
}
