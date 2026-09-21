<?php

namespace Tests\Feature;

use App\Models\Repuesto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Indicador y filtro "Sobre stock" del listado de catalogo e inventario.
 *
 * Cuenta y filtra los repuestos cuya existencia supera stock_maximo, dejando
 * fuera los que no tienen maximo definido (stock_maximo nulo o en 0): hoy son
 * 25.086 de 28.490 filas, asi que sin ese recorte el indicador mostraria casi
 * todo el catalogo como excedido.
 */
class RepuestoSobreStockTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $rol = Rol::where('col_clave', User::ROL_ADMIN)->firstOrFail();

        return User::create([
            'name' => 'Admin de prueba',
            'email' => 'admin.sobrestock.'.uniqid().'@sidocsa.com',
            'password' => 'Almacen2026*',
            'rol' => User::ROL_ADMIN,
            'rol_id' => $rol->id,
            'activo' => true,
        ]);
    }

    private function nuevoRepuesto(float $existencia, float $stockMaximo): Repuesto
    {
        return Repuesto::create([
            'codigo' => random_int(950000000, 999999999),
            'nombre' => 'Repuesto de prueba sobre stock',
            'unidad_medida' => 'UND',
            'existencia' => $existencia,
            'stock_minimo' => 1,
            'stock_maximo' => $stockMaximo,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    /**
     * El conteo se mide por diferencia: las pruebas corren contra la base real
     * (phpunit.xml apunta al .env) y el total absoluto cambia con los datos.
     */
    private function conteoSobreStock(User $usuario): int
    {
        return $this->actingAs($usuario)
            ->get(route('admin.repuestos.index'))
            ->assertOk()
            ->viewData('totales')['sobre_stock'];
    }

    /**
     * Ids que devuelve el listado filtrado, buscando por el codigo del
     * repuesto para que la pagina no dependa del orden del catalogo.
     *
     * Se mira el paginador y no el HTML porque el buscador reimprime el
     * termino: un assertDontSee sobre el codigo daria falso positivo.
     *
     * @return list<int>
     */
    private function idsFiltrados(User $usuario, Repuesto $repuesto): array
    {
        return $this->actingAs($usuario)
            ->get(route('admin.repuestos.index', ['filtro' => 'sobre_stock', 'q' => $repuesto->codigo]))
            ->assertOk()
            ->viewData('repuestos')
            ->pluck('id')
            ->all();
    }

    public function test_el_filtro_muestra_el_repuesto_con_existencia_sobre_el_maximo(): void
    {
        $repuesto = $this->nuevoRepuesto(existencia: 20, stockMaximo: 10);

        $this->assertContains($repuesto->id, $this->idsFiltrados($this->admin(), $repuesto));
    }

    public function test_el_filtro_deja_fuera_el_repuesto_dentro_del_maximo(): void
    {
        $repuesto = $this->nuevoRepuesto(existencia: 5, stockMaximo: 10);

        $this->assertNotContains($repuesto->id, $this->idsFiltrados($this->admin(), $repuesto));
    }

    public function test_el_filtro_deja_fuera_el_repuesto_sin_maximo_definido(): void
    {
        $repuesto = $this->nuevoRepuesto(existencia: 20, stockMaximo: 0);

        $this->assertNotContains($repuesto->id, $this->idsFiltrados($this->admin(), $repuesto));
    }

    public function test_el_conteo_sube_en_uno_con_un_repuesto_excedido(): void
    {
        $usuario = $this->admin();
        $antes = $this->conteoSobreStock($usuario);

        $this->nuevoRepuesto(existencia: 20, stockMaximo: 10);

        $this->assertSame($antes + 1, $this->conteoSobreStock($usuario));
    }

    public function test_el_conteo_no_sube_con_un_repuesto_sin_maximo_definido(): void
    {
        $usuario = $this->admin();
        $antes = $this->conteoSobreStock($usuario);

        $this->nuevoRepuesto(existencia: 20, stockMaximo: 0);

        $this->assertSame($antes, $this->conteoSobreStock($usuario));
    }
}
