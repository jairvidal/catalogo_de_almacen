<?php

namespace Tests\Feature;

use App\Models\Funcionalidad;
use App\Models\Parametro;
use App\Models\Rol;
use App\Models\User;
use App\Services\PermisoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seccion "Importacion ERP" de /admin/parametros (V 1.4.33): URL WS y
 * Estructura JSON (Parametro::IMPORTACION_ERP).
 *
 * Corre contra la base de .env: DatabaseTransactions. Los dos parametros los
 * crea la migracion 2026_10_11_100100; aqui se aseguran por si la base de
 * pruebas no la tuviera.
 */
class ParametroImportacionErpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Parametro::IMPORTACION_ERP as $nombre) {
            $parametro = Parametro::firstOrNew(['col_nombre' => $nombre]);
            $parametro->col_sistema = true;
            $parametro->fill(['col_valor' => '', 'col_estado' => Parametro::ESTADO_ACTIVO])->save();
        }
    }

    private function usuario(string $rol): User
    {
        return User::create([
            'name' => 'Usuario importacion '.$rol,
            'email' => 'importacion.'.$rol.'.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => $rol,
            'rol_id' => Rol::where('col_clave', $rol)->value('id'),
            'activo' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->usuario(User::ROL_ADMIN);
    }

    /** Perfil que solo puede VER parametros. */
    private function soloVer(): User
    {
        $rol = Rol::create([
            'col_clave' => 'parv_'.substr(uniqid(), -8),
            'col_nombre' => 'Solo ver parametros',
            'col_gestiona_catalogo' => false,
            'col_activo' => true,
        ]);
        $id = (int) DB::selectOne('select id from tbl_funcionalidad where col_clave = ?', [Funcionalidad::PARAMETROS])->id;
        app(PermisoService::class)->guardar($rol, [$id => ['ver' => '1']]);

        return User::create([
            'name' => 'Solo ver parametros',
            'email' => 'solo.ver.par.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ALMACENISTA,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function valor(string $nombre): string
    {
        return (string) Parametro::where('col_nombre', $nombre)->value('col_valor');
    }

    private function parametro(string $nombre): Parametro
    {
        return Parametro::where('col_nombre', $nombre)->firstOrFail();
    }

    /* --- Vista ---------------------------------------------------------- */

    public function test_la_seccion_arranca_cerrada_dentro_del_acordeon(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<h2 class="accordion-header" id="seccion-importacion-erp-encabezado">\s*<button class="accordion-button collapsed" type="button"\s+data-bs-toggle="collapse" data-bs-target="#seccion-importacion-erp"\s+aria-expanded="false" aria-controls="seccion-importacion-erp">/',
            $html
        );
        $this->assertMatchesRegularExpression('/<div id="seccion-importacion-erp" class="accordion-collapse collapse "/', $html);
        $this->assertStringContainsString('Importacion ERP', $html);
        $this->assertStringContainsString('<label for="url_ws" class="form-label fw-semibold">URL WS</label>', $html);
        $this->assertStringContainsString('<label for="estructura_json" class="form-label fw-semibold">Estructura JSON</label>', $html);
        $this->assertMatchesRegularExpression('/<textarea id="estructura_json" name="estructura_json"[^>]*class="form-control font-monospace/', $html);
        $this->assertStringContainsString('action="'.route('admin.parametros.importacion-erp').'"', $html);

        // Las dos secciones viven en el mismo acordeon y la de la API sigue abierta.
        $this->assertSame(1, substr_count($html, 'id="acordeon-parametros"'));
        $this->assertMatchesRegularExpression('/<div id="seccion-api-erp" class="accordion-collapse collapse show"/', $html);
        $this->assertTrue(strpos($html, 'id="seccion-api-erp"') < strpos($html, 'id="seccion-importacion-erp"'));
    }

    public function test_los_valores_guardados_se_muestran_en_la_seccion(): void
    {
        $this->parametro(Parametro::ERP_IMPORTACION_URL)->update(['col_valor' => 'https://erp.ejemplo.com/ws']);
        $this->parametro(Parametro::ERP_IMPORTACION_JSON)->update(['col_valor' => '{"a": 1}']);

        $this->actingAs($this->admin())
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->assertSee('value="https://erp.ejemplo.com/ws"', false)
            ->assertSee('{&quot;a&quot;: 1}</textarea>', false);
    }

    public function test_un_error_de_validacion_abre_la_seccion_y_pinta_el_error_bajo_el_campo(): void
    {
        $admin = $this->admin();
        $ruta = route('admin.parametros.index');

        $this->actingAs($admin)->from($ruta)
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'no es url', 'estructura_json' => '{"x": 1}'])
            ->assertRedirect($ruta);

        $html = $this->actingAs($admin)->get($ruta)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button class="accordion-button " type="button"\s+data-bs-toggle="collapse" data-bs-target="#seccion-importacion-erp"\s+aria-expanded="true"/', $html);
        $this->assertMatchesRegularExpression('/<div id="seccion-importacion-erp" class="accordion-collapse collapse show"/', $html);
        $this->assertStringContainsString('La URL WS debe ser una direccion completa', $html);
        $this->assertStringContainsString('value="no es url"', $html, 'Conserva lo digitado.');
        $this->assertStringContainsString('{&quot;x&quot;: 1}</textarea>', $html);
    }

    /* --- Guardar -------------------------------------------------------- */

    public function test_guardar_valores_validos(): void
    {
        $json = "{\n  \"cia\": 1,\n  \"campos\": [\"item\", \"cant\"]\n}";

        $this->actingAs($this->admin())
            ->put(route('admin.parametros.importacion-erp'), [
                'url_ws' => '  https://erp.ejemplo.com:98/api/v1/importar  ',
                'estructura_json' => $json,
            ])
            ->assertRedirect(route('admin.parametros.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        // Los extremos se recortan; el interior del JSON queda literal.
        $this->assertSame('https://erp.ejemplo.com:98/api/v1/importar', $this->valor(Parametro::ERP_IMPORTACION_URL));
        $this->assertSame($json, $this->valor(Parametro::ERP_IMPORTACION_JSON));

        // Solo cambia el valor: siguen del sistema y activos.
        foreach (Parametro::IMPORTACION_ERP as $nombre) {
            $parametro = $this->parametro($nombre);
            $this->assertTrue($parametro->col_sistema);
            $this->assertSame(Parametro::ESTADO_ACTIVO, $parametro->col_estado);
        }
    }

    public function test_la_url_invalida_se_rechaza_y_no_guarda_nada(): void
    {
        foreach (['texto' => 'no es url', 'ftp' => 'ftp://erp.ejemplo.com/ws', 'sin esquema' => 'erp.ejemplo.com/ws', 'larga' => 'https://e.com/'.str_repeat('a', 2048)] as $caso => $url) {
            $this->actingAs($this->admin())
                ->put(route('admin.parametros.importacion-erp'), ['url_ws' => $url, 'estructura_json' => '{"ok": true}'])
                ->assertSessionHasErrors('url_ws');

            $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL), "Caso {$caso}.");
            $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_JSON), "Caso {$caso}: no guarda a medias.");
        }
    }

    public function test_el_json_invalido_se_rechaza(): void
    {
        foreach (['{a: 1}', '{"a": 1,}', "{'a': 1}", '{"a": 1'] as $json) {
            $this->actingAs($this->admin())
                ->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'https://erp.ejemplo.com', 'estructura_json' => $json])
                ->assertSessionHasErrors('estructura_json');

            $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_JSON), $json);
            $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL), $json);
        }
    }

    public function test_los_dos_pueden_quedar_vacios(): void
    {
        $this->parametro(Parametro::ERP_IMPORTACION_URL)->update(['col_valor' => 'https://antes.com']);
        $this->parametro(Parametro::ERP_IMPORTACION_JSON)->update(['col_valor' => '[1]']);

        $this->actingAs($this->admin())
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => '', 'estructura_json' => '   '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito');

        $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL));
        $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_JSON));

        // Sin enviar los campos, igual.
        $this->actingAs($this->admin())
            ->put(route('admin.parametros.importacion-erp'), [])
            ->assertSessionHasNoErrors();
    }

    public function test_un_json_de_mas_de_255_caracteres_se_guarda_completo(): void
    {
        $campos = [];
        for ($i = 0; $i < 60; $i++) {
            $campos["campo_{$i}"] = "valor con tilde ñ {$i}";
        }
        $json = json_encode(['estructura' => $campos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertGreaterThan(2000, mb_strlen($json));

        $this->actingAs($this->admin())
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => '', 'estructura_json' => $json])
            ->assertSessionHasNoErrors();

        $this->assertSame($json, $this->valor(Parametro::ERP_IMPORTACION_JSON));
    }

    /* --- Permisos ------------------------------------------------------- */

    public function test_el_almacenista_recibe_403(): void
    {
        $this->actingAs($this->usuario(User::ROL_ALMACENISTA))
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'https://intruso.com'])
            ->assertForbidden();

        $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL));
    }

    public function test_con_solo_ver_la_seccion_es_de_lectura_y_el_put_da_403(): void
    {
        $usuario = $this->soloVer();

        $html = $this->actingAs($usuario)
            ->get(route('admin.parametros.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input type="text" id="url_ws"[^>]*readonly/s', $html);
        $this->assertMatchesRegularExpression('/<textarea id="estructura_json"[^>]*readonly/s', $html);
        $this->assertMatchesRegularExpression('/accion-sin-permiso" disabled[^>]*data-sin-permiso="editar">\s*<i class="bi bi-floppy me-1"><\/i>Guardar/', $html);

        $this->actingAs($usuario)
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'https://intruso.com'])
            ->assertForbidden();

        $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL));
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'https://intruso.com'])
            ->assertRedirect(route('admin.login'));
    }

    /* --- Fuera del listado general y de las rutas genericas -------------- */

    public function test_no_aparecen_en_el_listado_general_ni_en_sus_conteos(): void
    {
        $admin = $this->admin();
        $esperados = [
            'todos' => Parametro::whereNotIn('col_nombre', Parametro::IMPORTACION_ERP)->count(),
            'activos' => Parametro::whereNotIn('col_nombre', Parametro::IMPORTACION_ERP)->where('col_estado', Parametro::ESTADO_ACTIVO)->count(),
        ];

        $respuesta = $this->actingAs($admin)->get(route('admin.parametros.index'))->assertOk();
        $respuesta->assertViewHas('totales', fn ($totales) => $totales['todos'] === $esperados['todos'] && $totales['activos'] === $esperados['activos']);
        $respuesta->assertViewHas('parametros', fn ($pagina) => $pagina->getCollection()
            ->pluck('col_nombre')->intersect(Parametro::IMPORTACION_ERP)->isEmpty());

        $listado = $respuesta->getContent();
        foreach (Parametro::IMPORTACION_ERP as $nombre) {
            $this->assertStringNotContainsString('>'.$nombre.'</div>', $listado, $nombre);
        }

        // Tampoco por la busqueda.
        $this->actingAs($admin)
            ->get(route('admin.parametros.index', ['q' => 'erp.importacion']))
            ->assertOk()
            ->assertViewHas('parametros', fn ($pagina) => $pagina->total() === 0);
    }

    public function test_las_rutas_genericas_no_los_editan_ni_los_anulan(): void
    {
        $admin = $this->admin();

        foreach (Parametro::IMPORTACION_ERP as $nombre) {
            $parametro = $this->parametro($nombre);

            $this->actingAs($admin)->get(route('admin.parametros.edit', $parametro->id))->assertNotFound();

            $this->actingAs($admin)->put(route('admin.parametros.update', $parametro->id), [
                'col_nombre' => $nombre,
                'col_valor' => 'por la puerta de atras',
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ])->assertNotFound();

            $this->actingAs($admin)->delete(route('admin.parametros.destroy', $parametro->id))->assertNotFound();

            $fresco = $parametro->fresh();
            $this->assertSame('', $fresco->col_valor, $nombre);
            $this->assertSame(Parametro::ESTADO_ACTIVO, $fresco->col_estado, $nombre);
        }

        // Y el formulario generico no deja crear uno con esos nombres.
        Parametro::where('col_nombre', Parametro::ERP_IMPORTACION_URL)->delete();
        $this->actingAs($admin)->post(route('admin.parametros.store'), [
            'col_nombre' => Parametro::ERP_IMPORTACION_URL,
            'col_valor' => 'x',
            'col_estado' => Parametro::ESTADO_ACTIVO,
        ])->assertSessionHasErrors('col_nombre');
        $this->assertFalse(Parametro::where('col_nombre', Parametro::ERP_IMPORTACION_URL)->exists());
    }

    public function test_si_falta_un_parametro_no_guarda_ninguno(): void
    {
        Parametro::where('col_nombre', Parametro::ERP_IMPORTACION_JSON)->delete();
        $ruta = route('admin.parametros.index');

        $this->actingAs($this->admin())->from($ruta)
            ->put(route('admin.parametros.importacion-erp'), ['url_ws' => 'https://erp.ejemplo.com', 'estructura_json' => '{}'])
            ->assertRedirect($ruta)
            ->assertSessionHas('error');

        $this->assertSame('', $this->valor(Parametro::ERP_IMPORTACION_URL));
    }

    public function test_los_demas_parametros_siguen_topados_a_255(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.parametros.store'), [
                'col_nombre' => 'prueba.largo_'.substr(uniqid(), -6),
                'col_valor' => str_repeat('x', 256),
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ])
            ->assertSessionHasErrors('col_valor');
    }

    public function test_col_valor_es_nvarchar_max_con_su_default(): void
    {
        $columna = DB::selectOne(
            "select type_name(user_type_id) as tipo, max_length as largo, is_nullable as nula
               from sys.columns where object_id = object_id('dbo.tbl_parametro') and name = 'col_valor'"
        );

        $this->assertSame('nvarchar', $columna->tipo);
        $this->assertSame(-1, (int) $columna->largo);
        $this->assertSame(0, (int) $columna->nula);

        $default = DB::selectOne(
            "select d.definition from sys.default_constraints d
               join sys.columns c on c.object_id = d.parent_object_id and c.column_id = d.parent_column_id
              where d.name = 'df_tbl_parametro_col_valor' and c.name = 'col_valor'"
        );
        $this->assertSame("('')", $default?->definition);
    }
}
