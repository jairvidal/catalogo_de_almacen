<?php

namespace Tests\Feature;

use App\Models\SolicitanteErp;
use App\Services\ImportadorSolicitantesErp;
use App\Services\LectorSolicitantesCsv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Comando solicitantes:importar (LectorSolicitantesCsv + ImportadorSolicitantesErp).
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions y no
 * RefreshDatabase. Los codigos llevan el prefijo PRB- y una marca unica para
 * no chocar con solicitantes reales.
 */
class ImportarSolicitantesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $archivos = [];

    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marca = 'PRB-'.substr(uniqid(), -7);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }

        parent::tearDown();
    }

    private function archivo(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sol').'.csv';
        file_put_contents($ruta, $contenido);
        $this->archivos[] = $ruta;

        return $ruta;
    }

    private function buscar(string $sufijo): ?SolicitanteErp
    {
        return SolicitanteErp::where('col_codigo_erp', $this->marca.$sufijo)->first();
    }

    public function test_importa_los_solicitantes_con_todas_sus_columnas(): void
    {
        $m = $this->marca;
        // BOM de Excel, encabezado en mayusculas y en otro orden.
        $ruta = $this->archivo("\xEF\xBB\xBFNOMBRE;Codigo_ERP;cedula;correo;area;telefono;activo\n".
            "Juan  Perez ;{$m}1;1.001.234;Juan@Sidocsa.com;Mantenimiento;4455;1\n");

        $this->artisan('solicitantes:importar', ['archivo' => $ruta])->assertSuccessful();

        $persona = $this->buscar('1');
        $this->assertNotNull($persona);
        $this->assertSame('Juan Perez', $persona->col_nombre);
        $this->assertSame('1.001.234', $persona->col_cedula);
        $this->assertSame('juan@sidocsa.com', $persona->col_correo);
        $this->assertSame('Mantenimiento', $persona->col_area);
        $this->assertSame('4455', $persona->col_telefono);
        $this->assertTrue($persona->col_activo);
    }

    /**
     * Volver a importar el mismo archivo no crea ni reescribe nada: el MERGE
     * solo actualiza cuando algun dato cambio.
     */
    public function test_la_importacion_es_idempotente(): void
    {
        $m = $this->marca;
        $csv = "codigo_erp;nombre;correo\n{$m}1;Ana Gomez;ana@sidocsa.com\n{$m}2;Luis Rios;luis@sidocsa.com\n";
        $importador = app(ImportadorSolicitantesErp::class);
        $lector = app(LectorSolicitantesCsv::class);

        $primera = $importador->importar($lector->leer($this->archivo($csv)));
        $segunda = $importador->importar($lector->leer($this->archivo($csv)));

        $this->assertSame([2, 0, 0], [$primera->creados, $primera->actualizados, $primera->sinCambio]);
        $this->assertSame([0, 0, 2], [$segunda->creados, $segunda->actualizados, $segunda->sinCambio]);
        $this->assertSame(2, SolicitanteErp::where('col_codigo_erp', 'like', $m.'%')->count());
    }

    public function test_un_cambio_en_el_erp_actualiza_la_fila_existente(): void
    {
        $m = $this->marca;
        $this->artisan('solicitantes:importar', ['archivo' => $this->archivo("codigo_erp;nombre;area\n{$m}1;Ana Gomez;Planta 1\n")]);
        $id = $this->buscar('1')->id;

        $resultado = app(ImportadorSolicitantesErp::class)->importar(
            app(LectorSolicitantesCsv::class)->leer($this->archivo("codigo_erp;nombre;area;activo\n{$m}1;ANA GOMEZ;Planta 2;no\n"))
        );

        $persona = $this->buscar('1');
        $this->assertSame(1, $resultado->actualizados);
        $this->assertSame($id, $persona->id);
        // Un cambio solo de mayusculas tambien cuenta (comparacion binaria).
        $this->assertSame('ANA GOMEZ', $persona->col_nombre);
        $this->assertSame('Planta 2', $persona->col_area);
        $this->assertFalse($persona->col_activo);
    }

    /**
     * Un dato no informado (columna ausente o celda vacia) conserva el que ya
     * habia: un archivo sin correo no le borra el correo a nadie.
     */
    public function test_un_dato_no_informado_conserva_el_existente(): void
    {
        $m = $this->marca;
        $this->artisan('solicitantes:importar', ['archivo' => $this->archivo("codigo_erp;nombre;correo;cedula\n{$m}1;Ana;ana@sidocsa.com;123\n")]);
        $this->artisan('solicitantes:importar', ['archivo' => $this->archivo("codigo_erp;nombre;cedula\n{$m}1;Ana;\n")]);

        $persona = $this->buscar('1');
        $this->assertSame('ana@sidocsa.com', $persona->col_correo);
        $this->assertSame('123', $persona->col_cedula);
    }

    public function test_las_filas_invalidas_se_omiten_con_aviso(): void
    {
        $m = $this->marca;
        $nombreLargo = str_repeat('x', 151);
        $ruta = $this->archivo("codigo_erp;nombre;correo;cedula\n".
            ";Sin Codigo;;\n".
            "{$m}2;;;\n".
            "{$m}3;{$nombreLargo};;\n".
            "{$m}4;Valida;no-es-correo;12AB\n");

        $this->artisan('solicitantes:importar', ['archivo' => $ruta])
            ->expectsOutputToContain('Linea 2: se omite la fila porque no trae codigo_erp.')
            ->expectsOutputToContain("Linea 3: se omite el codigo {$m}2 porque no trae nombre.")
            ->expectsOutputToContain('Linea 4: se omite la fila porque nombre supera los 150 caracteres.')
            ->expectsOutputToContain("Linea 5: codigo {$m}4: se ignora el correo")
            ->expectsOutputToContain("Linea 5: codigo {$m}4: se ignora la cedula")
            ->assertSuccessful();

        $this->assertNull($this->buscar('2'));
        $this->assertNull($this->buscar('3'));

        // La fila con datos opcionales invalidos entra sin ellos.
        $valida = $this->buscar('4');
        $this->assertNotNull($valida);
        $this->assertNull($valida->col_correo);
        $this->assertNull($valida->col_cedula);
    }

    public function test_un_codigo_repetido_usa_la_ultima_fila(): void
    {
        $m = $this->marca;
        $ruta = $this->archivo("codigo_erp;nombre\n{$m}1;Primera\n{$m}1;Ultima\n");

        $this->artisan('solicitantes:importar', ['archivo' => $ruta])
            ->expectsOutputToContain("el codigo {$m}1 se repite")
            ->assertSuccessful();

        $this->assertSame('Ultima', $this->buscar('1')->col_nombre);
    }

    public function test_sin_columnas_obligatorias_falla_sin_escribir(): void
    {
        $m = $this->marca;
        $ruta = $this->archivo("codigo_erp;correo\n{$m}1;a@b.com\n");

        $this->artisan('solicitantes:importar', ['archivo' => $ruta])
            ->expectsOutputToContain('Faltan columnas obligatorias: nombre')
            ->assertFailed();

        $this->assertNull($this->buscar('1'));
    }

    public function test_un_archivo_inexistente_falla_con_mensaje(): void
    {
        $this->artisan('solicitantes:importar', ['archivo' => 'C:/no/existe.csv'])
            ->expectsOutputToContain('No se encontro el archivo')
            ->assertFailed();
    }

    public function test_acepta_la_coma_como_separador_y_el_alias_cod_empleado(): void
    {
        $m = $this->marca;
        $ruta = $this->archivo("cod_empleado,nombre,email\n{$m}1,Ana,ana@sidocsa.com\n");

        $this->artisan('solicitantes:importar', ['archivo' => $ruta, '--separador' => ','])->assertSuccessful();

        $this->assertSame('ana@sidocsa.com', $this->buscar('1')?->col_correo);
    }

    /**
     * Excel en espanol guarda el CSV en Windows-1252: la enie tiene que llegar
     * bien a la base.
     */
    public function test_convierte_un_archivo_en_windows_1252(): void
    {
        $m = $this->marca;
        $ruta = $this->archivo(mb_convert_encoding("codigo_erp;nombre\n{$m}1;Peña Núñez\n", 'Windows-1252', 'UTF-8'));

        $this->artisan('solicitantes:importar', ['archivo' => $ruta])->assertSuccessful();

        $this->assertSame('Peña Núñez', $this->buscar('1')->col_nombre);
    }

    /**
     * Mas filas que un lote (200): el MERGE se parte sin pasarse del limite de
     * 2100 parametros de SQL Server.
     */
    public function test_importa_mas_filas_que_un_lote(): void
    {
        $m = $this->marca;
        $filas = collect(range(1, 450))->map(fn ($i) => "{$m}{$i};Persona {$i}")->implode("\n");

        $this->artisan('solicitantes:importar', ['archivo' => $this->archivo("codigo_erp;nombre\n{$filas}\n")])->assertSuccessful();

        $this->assertSame(450, SolicitanteErp::where('col_codigo_erp', 'like', $m.'%')->count());
    }
}
