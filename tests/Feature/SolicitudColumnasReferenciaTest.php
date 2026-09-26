<?php

namespace Tests\Feature;

use App\Models\Solicitud;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Columnas agregadas por la migracion 2026_09_26_100500. Se verifica contra el
 * catalogo porque el usuario pidio VARCHAR y no NVARCHAR, y $table->string()
 * de Laravel en sqlsrv habria dado nvarchar sin que ninguna otra prueba lo note.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions.
 */
class SolicitudColumnasReferenciaTest extends TestCase
{
    use DatabaseTransactions;

    /** nombre => [tipo, largo en caracteres o null]. */
    private const ESPERADAS = [
        'cod_referencia' => ['varchar', 50],
        'cod_OC' => ['varchar', 50],
        'dias_entrega' => ['int', null],
        'centro_operacion' => ['varchar', 50],
        'cod_centro_de_costo' => ['varchar', 50],
        'proyecto' => ['varchar', 100],
        'enviado' => ['varchar', 1000],
    ];

    public function test_las_columnas_existen_con_su_tipo_largo_y_son_nulables(): void
    {
        $filas = DB::select(
            "select COLUMN_NAME as nombre, DATA_TYPE as tipo,
                    CHARACTER_MAXIMUM_LENGTH as largo, IS_NULLABLE as nulable
               from INFORMATION_SCHEMA.COLUMNS
              where TABLE_NAME = 'tbl_solicitudes'"
        );

        $columnas = [];
        foreach ($filas as $fila) {
            $columnas[$fila->nombre] = $fila;
        }

        foreach (self::ESPERADAS as $nombre => [$tipo, $largo]) {
            // array_key_exists distingue la caja: exige `cod_OC` tal cual.
            $this->assertArrayHasKey($nombre, $columnas, "Falta la columna {$nombre}.");
            $this->assertSame($tipo, $columnas[$nombre]->tipo, "Tipo de {$nombre}.");
            $this->assertSame($largo, $columnas[$nombre]->largo === null ? null : (int) $columnas[$nombre]->largo, "Largo de {$nombre}.");
            $this->assertSame('YES', $columnas[$nombre]->nulable, "{$nombre} debe admitir NULL.");
        }
    }

    public function test_el_modelo_lee_cod_oc_con_su_caja_y_dias_entrega_como_entero(): void
    {
        $id = Solicitud::query()->value('id');

        if ($id === null) {
            $this->markTestSkipped('La base no tiene solicitudes con las que probar la lectura.');
        }

        DB::table('tbl_solicitudes')->where('id', $id)->update([
            'cod_OC' => 'OC-PRUEBA',
            'dias_entrega' => 7,
        ]);

        $solicitud = Solicitud::findOrFail($id);

        $this->assertSame('OC-PRUEBA', $solicitud->cod_OC);
        $this->assertSame(7, $solicitud->dias_entrega);
    }

    public function test_las_columnas_nuevas_no_son_asignables_en_masa(): void
    {
        $solicitud = new Solicitud;

        foreach (array_keys(self::ESPERADAS) as $nombre) {
            $this->assertFalse($solicitud->isFillable($nombre), "{$nombre} no debe ser fillable.");
        }
    }
}
