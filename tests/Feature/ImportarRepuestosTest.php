<?php

namespace Tests\Feature;

use App\Models\Repuesto;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los tests corren contra la base configurada en .env (ver phpunit.xml), asi que
 * se usa DatabaseTransactions y no RefreshDatabase para no borrar datos reales.
 *
 * Los codigos son de ocho digitos para no chocar con los del catalogo real, que
 * son de siete.
 */
class ImportarRepuestosTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }

        parent::tearDown();
    }

    public function test_el_encabezado_viejo_cantidad_disponible_escribe_existencia(): void
    {
        $repuesto = $this->repuesto(99000001, existencia: 7);

        $this->importar("codigo;cantidad_disponible\n99000001;25\n");

        $this->assertSame(25.0, $repuesto->fresh()->existencia);
    }

    public function test_el_encabezado_nuevo_existencia_escribe_existencia(): void
    {
        $repuesto = $this->repuesto(99000002, existencia: 7);

        $this->importar("codigo;existencia\n99000002;30\n");

        $this->assertSame(30.0, $repuesto->fresh()->existencia);
    }

    /**
     * Con las dos columnas manda la nueva: traducir el alias encima habria
     * dejado que dos columnas escribieran el mismo campo.
     */
    public function test_con_las_dos_columnas_manda_existencia(): void
    {
        $repuesto = $this->repuesto(99000003, existencia: 7);

        $this->importar("codigo;existencia;cantidad_disponible\n99000003;30;99\n");

        $this->assertSame(30.0, $repuesto->fresh()->existencia);
    }

    /**
     * existencia es decimal(12,3): el almacen mide en KG y hay items con
     * fraccion. Con la conversion a entero que tenia antes, 12,5 KG entraban
     * como 12.
     */
    public function test_una_cantidad_con_decimales_no_se_trunca(): void
    {
        $repuesto = $this->repuesto(99000004, existencia: 7);

        $this->importar("codigo;existencia;stock_minimo\n99000004;12,5;1.25\n");

        $fresco = $repuesto->fresh();
        $this->assertSame(12.5, $fresco->existencia);
        $this->assertSame(1.25, $fresco->stock_minimo);
    }

    /**
     * Un (float) a secas convierte "abc" en 0.0 y le borraria el saldo a un
     * item real sin decir nada.
     */
    public function test_una_cantidad_no_numerica_no_borra_el_saldo(): void
    {
        $repuesto = $this->repuesto(99000005, existencia: 7);

        $this->importar("codigo;nombre;existencia\n99000005;Valvula de bola;abc\n");

        $fresco = $repuesto->fresh();
        $this->assertSame(7.0, $fresco->existencia);
        $this->assertSame('Valvula de bola', $fresco->nombre);
    }

    /**
     * stock es la columna que escribe la sincronizacion con el ERP: si el
     * importador la tocara, el proximo despacho iria contra un saldo fantasma.
     */
    public function test_la_importacion_no_toca_stock(): void
    {
        $repuesto = $this->repuesto(99000006, existencia: 7, stock: 500);

        $this->importar("codigo;existencia;stock\n99000006;25;1\n");

        $fresco = $repuesto->fresh();
        $this->assertSame(25.0, $fresco->existencia);
        $this->assertSame(500.0, $fresco->stock);
    }

    private function repuesto(int $codigo, float $existencia = 7, float $stock = 0): Repuesto
    {
        return Repuesto::create([
            'codigo' => $codigo,
            'nombre' => 'Repuesto de prueba '.$codigo,
            'unidad_medida' => 'UND',
            'existencia' => $existencia,
            'stock' => $stock,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);
    }

    private function importar(string $contenido): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'repuestos').'.csv';
        file_put_contents($ruta, $contenido);
        $this->archivos[] = $ruta;

        $this->artisan('repuestos:importar', ['archivo' => $ruta, '--separador' => ';'])
            ->assertSuccessful();
    }
}
