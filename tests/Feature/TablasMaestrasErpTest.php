<?php

namespace Tests\Feature;

use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Motivo;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * tbl_centro_operacion, tbl_centro_de_costo y tbl_proyecto (migracion
 * 2026_09_26_100800) y tbl_motivo (2026_09_26_100900): la PK es el codigo
 * varchar(10), o varchar(5) en tbl_motivo, con nombre explicito, e
 * `id` es IDENTITY con indice unico pero no es la PK.
 *
 * Corre contra la base de .env (ver phpunit.xml): DatabaseTransactions.
 */
class TablasMaestrasErpTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, array{class-string<Model>, string, string, int}>
     */
    public static function maestras(): array
    {
        return [
            'centro de operacion' => [CentroOperacion::class, 'tbl_centro_operacion', 'col_cod_centro_operacion', 10],
            'centro de costo' => [CentroDeCosto::class, 'tbl_centro_de_costo', 'col_cod_centro_de_costo', 10],
            'proyecto' => [Proyecto::class, 'tbl_proyecto', 'col_cod_proyecto', 10],
            'motivo' => [Motivo::class, 'tbl_motivo', 'col_cod_motivo', 5],
        ];
    }

    #[DataProvider('maestras')]
    public function test_el_esquema_tiene_los_tipos_la_pk_y_los_indices_pedidos(string $modelo, string $tabla, string $codigo, int $largo): void
    {
        $columnas = [];
        foreach (DB::select(
            "select COLUMN_NAME as nombre, DATA_TYPE as tipo, CHARACTER_MAXIMUM_LENGTH as largo,
                    IS_NULLABLE as nulable, COLUMN_DEFAULT as defecto,
                    COLUMNPROPERTY(object_id(TABLE_NAME), COLUMN_NAME, 'IsIdentity') as identidad
               from INFORMATION_SCHEMA.COLUMNS
              where TABLE_NAME = ?",
            [$tabla]
        ) as $fila) {
            $columnas[$fila->nombre] = $fila;
        }

        $this->assertSame(['bigint', 'NO', 1], [$columnas['id']->tipo, $columnas['id']->nulable, (int) $columnas['id']->identidad]);
        $this->assertSame(['varchar', $largo, 'NO'], [$columnas[$codigo]->tipo, (int) $columnas[$codigo]->largo, $columnas[$codigo]->nulable]);
        $this->assertSame(['nvarchar', 150, 'NO'], [$columnas['col_nombre']->tipo, (int) $columnas['col_nombre']->largo, $columnas['col_nombre']->nulable]);
        $this->assertSame(['bit', 'NO', '((1))'], [$columnas['col_activo']->tipo, $columnas['col_activo']->nulable, $columnas['col_activo']->defecto]);
        $this->assertSame(['datetime', 'YES'], [$columnas['created_at']->tipo, $columnas['created_at']->nulable]);
        $this->assertSame(['datetime', 'YES'], [$columnas['updated_at']->tipo, $columnas['updated_at']->nulable]);

        $indices = [];
        foreach (DB::select(
            'select i.name as nombre, i.is_primary_key as pk, i.is_unique as unico, min(c.name) as columna, count(*) as total
               from sys.indexes i
               join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
               join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
              where i.object_id = object_id(?)
              group by i.name, i.is_primary_key, i.is_unique',
            ['dbo.'.$tabla]
        ) as $fila) {
            $indices[$fila->nombre] = $fila;
        }

        $this->assertArrayHasKey("pk_{$tabla}", $indices, 'La PK debe tener nombre explicito.');
        $this->assertSame([1, $codigo, 1], [(int) $indices["pk_{$tabla}"]->pk, $indices["pk_{$tabla}"]->columna, (int) $indices["pk_{$tabla}"]->total]);

        $this->assertArrayHasKey("uq_{$tabla}_id", $indices);
        $this->assertSame([0, 1, 'id'], [(int) $indices["uq_{$tabla}_id"]->pk, (int) $indices["uq_{$tabla}_id"]->unico, $indices["uq_{$tabla}_id"]->columna]);
    }

    #[DataProvider('maestras')]
    public function test_el_modelo_crea_y_lee_por_codigo_sin_mandar_id(string $modelo, string $tabla, string $codigo, int $largo): void
    {
        // Sin col_activo: la base pone el default 1. El codigo con cero a la
        // izquierda prueba que la llave se trata como texto.
        $libre = $this->codigoLibre($modelo);
        $modelo::create([$codigo => $libre, 'col_nombre' => 'Prueba ñandú']);

        $leido = $modelo::find($libre);

        $this->assertNotNull($leido);
        $this->assertSame($libre, $leido->getKey());
        $this->assertSame('Prueba ñandú', $leido->col_nombre);
        $this->assertTrue($leido->col_activo);
        $this->assertIsInt($leido->id);
        $this->assertGreaterThan(0, $leido->id);
        $this->assertNull($modelo::find(ltrim($libre, '0')), 'La llave no debe convertirse a numero.');
    }

    #[DataProvider('maestras')]
    public function test_la_pk_rechaza_un_codigo_repetido(string $modelo, string $tabla, string $codigo, int $largo): void
    {
        $libre = $this->codigoLibre($modelo);
        $modelo::create([$codigo => $libre, 'col_nombre' => 'Primero']);

        $this->expectException(QueryException::class);

        $modelo::create([$codigo => $libre, 'col_nombre' => 'Segundo']);
    }

    /**
     * Codigo de 4 caracteres con cero a la izquierda ('0xyz') que no existe en
     * la tabla, ni el ni su version sin ceros: las tablas ya tienen datos
     * reales cargados (p. ej. '001' y '015' en tbl_centro_operacion).
     *
     * @param  class-string<Model>  $modelo
     */
    private function codigoLibre(string $modelo): string
    {
        for ($intento = 0; $intento < 50; $intento++) {
            $candidato = '0'.random_int(100, 999);

            if ($modelo::find($candidato) === null && $modelo::find(ltrim($candidato, '0')) === null) {
                return $candidato;
            }
        }

        $this->fail("No se encontro un codigo libre en {$modelo}.");
    }

    #[DataProvider('maestras')]
    public function test_id_no_es_asignable_en_masa(string $modelo, string $tabla, string $codigo, int $largo): void
    {
        $this->assertFalse((new $modelo)->isFillable('id'));
    }
}
