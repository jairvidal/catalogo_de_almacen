<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Models\Repuesto;
use App\Services\InicializadorExistenciaDesdeStock;
use App\Services\InicializadorExistenciaRepuestos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Carga inicial de repuestos.existencia copiando repuestos.stock.
 *
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 * Cada corrida real toca TODO el catalogo —es una sola sentencia sobre la tabla
 * entera— y la transaccion de la prueba lo devuelve a como estaba.
 *
 * Aqui no hay Http::fake(): esta via no habla con la API.
 */
class InicializarExistenciaDesdeStockTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto el inicializador se niega a trabajar: su respaldo es "ya
        // inicializada", que es lo seguro fuera de las pruebas.
        $this->parametro(Parametro::INV_EXISTENCIA_INICIALIZADA, Parametro::INV_NO);
    }

    private function parametro(string $nombre, string $valor): Parametro
    {
        $parametro = Parametro::firstOrNew(['col_nombre' => $nombre]);
        $parametro->col_valor = $valor;
        $parametro->col_estado = Parametro::ESTADO_ACTIVO;
        $parametro->save();

        return $parametro;
    }

    /**
     * Codigo de 9 digitos, muy por encima de los del catalogo real, para no
     * chocar con el indice unico de repuestos.codigo.
     */
    private function codigoDePrueba(): int
    {
        return random_int(900000000, 949999999);
    }

    private function repuesto(int $codigo, float $existencia, float $stock): Repuesto
    {
        return Repuesto::create([
            'codigo' => $codigo,
            'nombre' => 'Repuesto de prueba '.$codigo,
            'unidad_medida' => 'UND',
            'existencia' => $existencia,
            'stock' => $stock,
            'stock_minimo' => 2,
            'stock_maximo' => 4,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    public function test_copia_stock_en_las_filas_que_estan_en_cero(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 30);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(30.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /**
     * LA PRUEBA QUE SOSTIENE LA BARRERA 2: un repuesto con saldo operativo no se
     * pisa nunca. Si esta falla, la carga inicial borra lo ya despachado.
     */
    public function test_no_pisa_una_fila_con_saldo_vivo(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 5, stock: 999);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(5.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /**
     * Las cantidades son decimal(12,3) porque el almacen mide en KG: copiar el
     * stock truncando a entero perderia los decimales en silencio.
     */
    public function test_copia_el_valor_exacto_con_decimales(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 12.525);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(12.525, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /** Escribir un 0 sobre un 0 no cambia nada y solo moveria la fecha. */
    public function test_salta_las_filas_sin_stock(): void
    {
        $enCero = $this->codigoDePrueba();
        $negativo = $this->codigoDePrueba();
        $this->repuesto($enCero, existencia: 0, stock: 0);
        $this->repuesto($negativo, existencia: 0, stock: -3);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(0.0, Repuesto::where('codigo', $enCero)->value('existencia'));
        $this->assertSame(0.0, Repuesto::where('codigo', $negativo)->value('existencia'));
    }

    /**
     * El origen es de solo lectura: `stock` lo escribe la sincronizacion con el
     * ERP y esta carga no tiene por que tocarlo, ni a el ni a ningun otro campo
     * del catalogo. fecha_actualizacion si se mueve, y debe hacerlo: es la marca
     * de que la fila cambio.
     */
    public function test_no_toca_stock_ni_ninguna_otra_columna(): void
    {
        $codigo = $this->codigoDePrueba();
        $antes = $this->repuesto($codigo, existencia: 0, stock: 30);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $despues = Repuesto::where('codigo', $codigo)->firstOrFail();

        $this->assertSame(30.0, $despues->stock);
        $this->assertSame(2.0, $despues->stock_minimo);
        $this->assertSame(4.0, $despues->stock_maximo);
        $this->assertSame($antes->nombre, $despues->nombre);
        $this->assertSame($antes->unidad_medida, $despues->unidad_medida);
        $this->assertSame(Repuesto::ESTADO_ACTIVO, $despues->estado);
    }

    public function test_simular_no_escribe_ni_el_saldo_ni_la_bandera(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 30);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--simular' => true])
            ->assertSuccessful();

        $this->assertSame(0.0, Repuesto::where('codigo', $codigo)->value('existencia'));
        $this->assertSame(Parametro::INV_NO, Parametro::valor(Parametro::INV_EXISTENCIA_INICIALIZADA));
    }

    /**
     * La segunda corrida no puede volver a escribir: barreria lo ya despachado.
     */
    public function test_la_bandera_bloquea_una_segunda_corrida(): void
    {
        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 30);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();
        $this->assertSame(30.0, Repuesto::where('codigo', $codigo)->value('existencia'));
        $this->assertSame(Parametro::INV_SI, Parametro::valor(Parametro::INV_EXISTENCIA_INICIALIZADA));

        // El almacen despacha 25 unidades.
        Repuesto::where('codigo', $codigo)->update(['existencia' => 5]);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(5.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /**
     * El respaldo de la bandera es "ya inicializada", al reves que el de
     * inv.actualizar: si no se puede confirmar que hace falta, no se escribe.
     */
    public function test_sin_el_parametro_la_carga_no_corre(): void
    {
        $marca = Parametro::where('col_nombre', Parametro::INV_EXISTENCIA_INICIALIZADA)->firstOrFail();
        $marca->col_estado = Parametro::ESTADO_INACTIVO;
        $marca->save();

        $codigo = $this->codigoDePrueba();
        $this->repuesto($codigo, existencia: 0, stock: 30);

        $this->artisan('repuestos:inicializar-existencia-desde-stock', ['--forzar' => true])
            ->assertSuccessful();

        $this->assertSame(0.0, Repuesto::where('codigo', $codigo)->value('existencia'));
    }

    /**
     * La bandera es UNA SOLA para las dos vias: si la de la API ya inicializo,
     * esta tampoco corre.
     */
    public function test_la_bandera_es_la_misma_de_la_via_por_api(): void
    {
        $this->parametro(Parametro::INV_EXISTENCIA_INICIALIZADA, Parametro::INV_SI);

        $this->assertTrue(app(InicializadorExistenciaDesdeStock::class)->yaSeInicializo());
        $this->assertTrue(app(InicializadorExistenciaRepuestos::class)->yaSeInicializo());
    }

    /**
     * Los contadores parten el catalogo en tres grupos sin dejar ni repetir
     * filas: si la suma no da el total, la tabla del comando esta mintiendo.
     */
    public function test_los_contadores_cuadran_con_el_total(): void
    {
        $this->repuesto($this->codigoDePrueba(), existencia: 0, stock: 30);
        $this->repuesto($this->codigoDePrueba(), existencia: 5, stock: 30);
        $this->repuesto($this->codigoDePrueba(), existencia: 0, stock: 0);

        $previo = app(InicializadorExistenciaDesdeStock::class)->previsualizar();

        $this->assertSame(Repuesto::count(), $previo->total);
        $this->assertSame(
            $previo->total,
            $previo->candidatas + $previo->yaConSaldo + $previo->sinStock
        );
        $this->assertTrue($previo->simulado);
        $this->assertSame(0, $previo->afectadas);
    }
}
