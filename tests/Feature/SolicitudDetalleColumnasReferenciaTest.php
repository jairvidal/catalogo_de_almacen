<?php

namespace Tests\Feature;

use App\Models\SolicitudItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Columnas agregadas por la migracion 2026_09_26_100600 a tbl_solicitud_detalle.
 * Se verifica contra el catalogo porque el usuario pidio VARCHAR y no NVARCHAR,
 * y decimal(12,3) para `cantidad` (no el decimal(4,4) inicial).
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions.
 */
class SolicitudDetalleColumnasReferenciaTest extends TestCase
{
    use DatabaseTransactions;

    /** nombre => [tipo, largo en caracteres o precision, escala o null]. */
    private const ESPERADAS = [
        'cod_bodega' => ['varchar', 10, null],
        'cod_motivo' => ['varchar', 50, null],
        'cantidad' => ['decimal', 12, 3],
        'cod_unidad_medida' => ['varchar', 5, null],
        'cod_unidad_negocio' => ['varchar', 5, null],
        'cod_centro_operacion' => ['varchar', 5, null],
        'cod_centro_de_costo' => ['varchar', 10, null],
        'cod_proyecto' => ['varchar', 100, null],
        'notas_item' => ['varchar', 500, null],
        'descripcion_item' => ['varchar', 500, null],
    ];

    public function test_las_columnas_existen_con_su_tipo_largo_y_son_nulables(): void
    {
        $filas = DB::select(
            "select COLUMN_NAME as nombre, DATA_TYPE as tipo, CHARACTER_MAXIMUM_LENGTH as largo,
                    NUMERIC_PRECISION as precision, NUMERIC_SCALE as escala, IS_NULLABLE as nulable
               from INFORMATION_SCHEMA.COLUMNS
              where TABLE_NAME = 'tbl_solicitud_detalle'"
        );

        $columnas = [];
        foreach ($filas as $fila) {
            $columnas[$fila->nombre] = $fila;
        }

        foreach (self::ESPERADAS as $nombre => [$tipo, $tamano, $escala]) {
            $this->assertArrayHasKey($nombre, $columnas, "Falta la columna {$nombre}.");
            $columna = $columnas[$nombre];

            $this->assertSame($tipo, $columna->tipo, "Tipo de {$nombre}.");
            $this->assertSame('YES', $columna->nulable, "{$nombre} debe admitir NULL.");

            if ($tipo === 'decimal') {
                $this->assertSame($tamano, (int) $columna->precision, "Precision de {$nombre}.");
                $this->assertSame($escala, (int) $columna->escala, "Escala de {$nombre}.");
            } else {
                $this->assertSame($tamano, (int) $columna->largo, "Largo de {$nombre}.");
            }
        }
    }

    public function test_el_modelo_lee_cantidad_con_tres_decimales(): void
    {
        $id = SolicitudItem::query()->value('id');

        if ($id === null) {
            $this->markTestSkipped('La base no tiene items de solicitud con los que probar la lectura.');
        }

        DB::table('tbl_solicitud_detalle')->where('id', $id)->update(['cantidad' => 12.5]);

        $this->assertSame('12.500', SolicitudItem::findOrFail($id)->cantidad);
    }

    public function test_las_columnas_nuevas_no_son_asignables_en_masa(): void
    {
        $item = new SolicitudItem;

        foreach (array_keys(self::ESPERADAS) as $nombre) {
            $this->assertFalse($item->isFillable($nombre), "{$nombre} no debe ser fillable.");
        }
    }
}
