<?php

namespace Tests\Feature;

use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Cambio de la foto de un repuesto desde el panel.
 *
 * En produccion (IIS) la foto subida quedaba con los permisos del directorio
 * temporal de PHP y el servidor respondia 401 al pedirla. La correccion copia
 * el contenido a un archivo nuevo en public/img en vez de moverlo; estas
 * pruebas sostienen que el archivo llega completo y que el repuesto lo apunta.
 *
 * Tambien sostienen el otro lado del cambio del 2026-09-21: la sincronizacion
 * con el ERP dejo de mover fecha_actualizacion, asi que esa columna tiene que
 * seguir moviendose cuando la edicion viene del panel. Si dejara de hacerlo,
 * nadie se enteraria: no hay pantalla que la muestre.
 */
class RepuestoFotoAdminTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $archivosCreados = [];

    protected function tearDown(): void
    {
        foreach ($this->archivosCreados as $archivo) {
            @unlink(public_path('img/'.$archivo));
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'admin.foto.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function nuevoRepuesto(): Repuesto
    {
        return Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba',
            'unidad_medida' => 'UND',
            'existencia' => 5,
            'stock_minimo' => 1,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    private function datosFormulario(Repuesto $repuesto, array $extra = []): array
    {
        return array_merge([
            'codigo' => $repuesto->codigo,
            'nombre' => $repuesto->nombre,
            'unidad_medida' => $repuesto->unidad_medida,
            'existencia' => 5,
            'stock_minimo' => 1,
            'estado' => '1',
        ], $extra);
    }

    public function test_cambiar_la_foto_copia_el_archivo_a_public_img_y_lo_asocia(): void
    {
        $repuesto = $this->nuevoRepuesto();
        $imagen = UploadedFile::fake()->image('foto.jpeg', 40, 40);
        $contenido = file_get_contents($imagen->getRealPath());

        $this->actingAs($this->admin())
            ->put(route('admin.repuestos.update', $repuesto), $this->datosFormulario($repuesto, ['imagen' => $imagen]))
            ->assertRedirect(route('admin.repuestos.index'));

        $foto = $repuesto->fresh()->foto;
        $this->archivosCreados[] = $foto;

        $this->assertNotNull($foto);
        $this->assertStringStartsWith($repuesto->codigo.'-', $foto);
        $this->assertFileExists(public_path('img/'.$foto));
        $this->assertSame($contenido, file_get_contents(public_path('img/'.$foto)));
        $this->assertStringContainsString('img/'.$foto, $repuesto->fresh()->foto_url);
    }

    public function test_guardar_sin_imagen_conserva_la_foto_anterior(): void
    {
        $repuesto = $this->nuevoRepuesto();
        $repuesto->update(['foto' => 'foto-anterior.jpg']);

        $this->actingAs($this->admin())
            ->put(route('admin.repuestos.update', $repuesto), $this->datosFormulario($repuesto))
            ->assertRedirect(route('admin.repuestos.index'));

        $this->assertSame('foto-anterior.jpg', $repuesto->fresh()->foto);
    }

    /**
     * Editar el repuesto desde el panel SI mueve fecha_actualizacion: es el
     * updated_at del modelo y ahora es lo unico que lo mueve, porque la
     * sincronizacion con el ERP escribe su propia columna (fecha_actual_ERP).
     */
    public function test_editar_el_repuesto_desde_el_panel_mueve_fecha_actualizacion(): void
    {
        $repuesto = $this->nuevoRepuesto();

        $marca = '2026-01-15 08:30:00';
        Repuesto::where('id', $repuesto->id)->update(['fecha_actualizacion' => $marca]);

        $this->actingAs($this->admin())
            ->put(route('admin.repuestos.update', $repuesto), $this->datosFormulario($repuesto, [
                'nombre' => 'Nombre corregido a mano',
            ]))
            ->assertRedirect(route('admin.repuestos.index'));

        $actualizado = $repuesto->fresh();

        $this->assertSame('Nombre corregido a mano', $actualizado->nombre);
        $this->assertNotSame($marca, $actualizado->fecha_actualizacion->format('Y-m-d H:i:s'));
        $this->assertTrue($actualizado->fecha_actualizacion->greaterThan($marca));
    }

    /**
     * "Tipo de repuesto" (desc_cat_3) es de solo lectura en Editar: se muestra,
     * sin name, y en Nuevo repuesto no se pinta.
     */
    public function test_editar_muestra_el_tipo_de_repuesto_en_solo_lectura(): void
    {
        $repuesto = $this->nuevoRepuesto();
        Repuesto::where('id', $repuesto->id)->update(['desc_cat_3' => 'INVENT MATERIA PRIMA']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.repuestos.edit', $repuesto))
            ->assertOk()
            ->assertSee('Tipo de repuesto')
            ->assertSee('value="INVENT MATERIA PRIMA"', false)
            ->assertDontSee('name="desc_cat_3"', false);

        Repuesto::where('id', $repuesto->id)->update(['desc_cat_3' => null]);

        $this->actingAs($admin)
            ->get(route('admin.repuestos.edit', $repuesto))
            ->assertOk()
            ->assertSee('placeholder="Sin tipo"', false);

        $this->actingAs($admin)
            ->get(route('admin.repuestos.create'))
            ->assertOk()
            ->assertDontSee('Tipo de repuesto');
    }

    /** Guardar el formulario no toca desc_cat_3, aunque llegue en el payload. */
    public function test_guardar_no_modifica_el_tipo_de_repuesto(): void
    {
        $repuesto = $this->nuevoRepuesto();
        Repuesto::where('id', $repuesto->id)->update(['desc_cat_3' => 'INVENT MATERIA PRIMA']);

        $this->actingAs($this->admin())
            ->put(route('admin.repuestos.update', $repuesto), $this->datosFormulario($repuesto, [
                'desc_cat_3' => 'VALOR INYECTADO',
            ]))
            ->assertRedirect(route('admin.repuestos.index'));

        $this->assertSame('INVENT MATERIA PRIMA', $repuesto->fresh()->desc_cat_3);
    }

    /** El ajuste rapido de existencia desde el listado tambien es una edicion. */
    public function test_ajustar_la_existencia_desde_el_panel_mueve_fecha_actualizacion(): void
    {
        $repuesto = $this->nuevoRepuesto();

        $marca = '2026-01-15 08:30:00';
        Repuesto::where('id', $repuesto->id)->update(['fecha_actualizacion' => $marca]);

        $this->actingAs($this->admin())
            ->patch(route('admin.repuestos.stock', $repuesto), ['existencia' => 12])
            ->assertRedirect();

        $actualizado = $repuesto->fresh();

        $this->assertSame(12.0, $actualizado->existencia);
        $this->assertTrue($actualizado->fecha_actualizacion->greaterThan($marca));
    }
}
