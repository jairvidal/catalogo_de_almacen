<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\CentroDeCosto;
use App\Models\CentroOperacion;
use App\Models\Motivo;
use App\Models\Proyecto;
use App\Models\Repuesto;
use App\Models\Solicitud;
use App\Models\SolicitudItem;
use App\Models\UnidadMedida;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Llaves foraneas de la migracion 2026_09_27_100000: de las columnas de
 * referencia de tbl_solicitudes y tbl_solicitud_detalle hacia las maestras
 * del ERP, cada una con su indice, y con la columna igualada al tipo, largo y
 * collation de la clave de la maestra.
 *
 * Corre contra la base de .env: DatabaseTransactions. Las violaciones de FK
 * (error 547) son de sentencia: no condenan la transaccion de la prueba.
 */
class SolicitudFkMaestrasErpTest extends TestCase
{
    use DatabaseTransactions;

    private const ERROR_FK = 547;

    /**
     * @return array<string, array{0: string, 1: string, 2: class-string}>
     */
    public static function referencias(): array
    {
        return [
            'cabecera centro de operacion' => ['tbl_solicitudes', 'cod_centro_operacion', CentroOperacion::class],
            'cabecera centro de costo' => ['tbl_solicitudes', 'cod_centro_de_costo', CentroDeCosto::class],
            'cabecera proyecto' => ['tbl_solicitudes', 'cod_proyecto', Proyecto::class],
            'detalle bodega' => ['tbl_solicitud_detalle', 'cod_bodega', Bodega::class],
            'detalle motivo' => ['tbl_solicitud_detalle', 'cod_motivo', Motivo::class],
            'detalle unidad de medida' => ['tbl_solicitud_detalle', 'cod_unidad_medida', UnidadMedida::class],
            'detalle centro de operacion' => ['tbl_solicitud_detalle', 'cod_centro_operacion', CentroOperacion::class],
            'detalle centro de costo' => ['tbl_solicitud_detalle', 'cod_centro_de_costo', CentroDeCosto::class],
            'detalle proyecto' => ['tbl_solicitud_detalle', 'cod_proyecto', Proyecto::class],
        ];
    }

    private function columna(string $tabla, string $columna): object
    {
        return DB::selectOne(
            'select type_name(user_type_id) as tipo, max_length as largo, collation_name as collation, is_nullable as nulable
               from sys.columns where object_id = object_id(?) and name = ?',
            ['dbo.'.$tabla, $columna]
        );
    }

    #[DataProvider('referencias')]
    public function test_la_columna_tiene_el_tipo_largo_y_collation_de_la_clave_de_su_maestra(string $tabla, string $columna, string $modelo): void
    {
        $maestra = new $modelo;
        $actual = $this->columna($tabla, $columna);
        $clave = $this->columna($maestra->getTable(), $maestra->getKeyName());

        $this->assertSame(
            [$clave->tipo, (int) $clave->largo, $clave->collation],
            [$actual->tipo, (int) $actual->largo, $actual->collation]
        );
        $this->assertSame('varchar', $actual->tipo);
        $this->assertTrue((bool) $actual->nulable, "{$tabla}.{$columna} debe admitir NULL.");
    }

    #[DataProvider('referencias')]
    public function test_existe_la_fk_con_su_nombre_destino_y_no_action(string $tabla, string $columna, string $modelo): void
    {
        $maestra = new $modelo;

        $fk = DB::selectOne(
            'select object_name(fk.referenced_object_id) as maestra, rc.name as clave, pc.name as columna,
                    fk.delete_referential_action_desc as al_borrar, fk.update_referential_action_desc as al_actualizar,
                    fk.is_disabled as deshabilitada, fk.is_not_trusted as no_confiable,
                    (select count(*) from sys.foreign_key_columns x where x.constraint_object_id = fk.object_id) as columnas
               from sys.foreign_keys fk
               join sys.foreign_key_columns fkc on fkc.constraint_object_id = fk.object_id
               join sys.columns pc on pc.object_id = fkc.parent_object_id and pc.column_id = fkc.parent_column_id
               join sys.columns rc on rc.object_id = fkc.referenced_object_id and rc.column_id = fkc.referenced_column_id
              where fk.name = ? and fk.parent_object_id = object_id(?)',
            ["fk_{$tabla}_{$columna}", 'dbo.'.$tabla]
        );

        $this->assertNotNull($fk, "Falta la FK fk_{$tabla}_{$columna}.");
        $this->assertSame(
            [$maestra->getTable(), $maestra->getKeyName(), $columna, 'NO_ACTION', 'NO_ACTION', 1],
            [$fk->maestra, $fk->clave, $fk->columna, $fk->al_borrar, $fk->al_actualizar, (int) $fk->columnas]
        );
        $this->assertFalse((bool) $fk->deshabilitada, 'La FK no debe estar deshabilitada.');
        $this->assertFalse((bool) $fk->no_confiable, 'La FK debe estar verificada (WITH CHECK).');
    }

    #[DataProvider('referencias')]
    public function test_existe_el_indice_no_agrupado_de_la_fk(string $tabla, string $columna): void
    {
        $filas = DB::select(
            'select c.name as columna, i.type_desc as tipo, i.is_unique as unico
               from sys.indexes i
               join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
               join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
              where i.object_id = object_id(?) and i.name = ?',
            ['dbo.'.$tabla, "ix_{$tabla}_{$columna}"]
        );

        $this->assertCount(1, $filas, "El indice ix_{$tabla}_{$columna} debe existir y cubrir solo la columna.");
        $this->assertSame([$columna, 'NONCLUSTERED', false], [$filas[0]->columna, $filas[0]->tipo, (bool) $filas[0]->unico]);
    }

    public function test_el_largo_maximo_del_item_coincide_con_la_columna(): void
    {
        foreach (array_keys(SolicitudItem::REFERENCIAS_DE_MAESTRA) as $columna) {
            $this->assertSame(
                (int) $this->columna('tbl_solicitud_detalle', $columna)->largo,
                SolicitudItem::LARGO_MAXIMO[$columna],
                "SolicitudItem::LARGO_MAXIMO[{$columna}] no coincide con la base."
            );
        }
    }

    #[DataProvider('referencias')]
    public function test_la_base_rechaza_un_codigo_que_no_existe_en_la_maestra_y_acepta_uno_que_si(string $tabla, string $columna, string $modelo): void
    {
        $id = $this->filaDe($tabla);
        $libre = $this->codigoLibre($modelo);

        $this->assertSame(self::ERROR_FK, $this->errorAlEscribir($tabla, $id, $columna, $libre), "{$tabla}.{$columna} debio rechazar '{$libre}'.");
        $this->assertNull(DB::table($tabla)->where('id', $id)->value($columna));

        $valido = $this->maestra($modelo);
        DB::table($tabla)->where('id', $id)->update([$columna => $valido]);
        $this->assertSame($valido, DB::table($tabla)->where('id', $id)->value($columna));
    }

    #[DataProvider('referencias')]
    public function test_no_se_puede_borrar_un_codigo_de_la_maestra_en_uso(string $tabla, string $columna, string $modelo): void
    {
        $id = $this->filaDe($tabla);
        $codigo = $this->maestra($modelo);
        DB::table($tabla)->where('id', $id)->update([$columna => $codigo]);

        $maestra = new $modelo;

        try {
            DB::table($maestra->getTable())->where($maestra->getKeyName(), $codigo)->delete();
            $this->fail('Debio rechazar el borrado de un codigo en uso (NO ACTION).');
        } catch (QueryException $e) {
            $this->assertSame(self::ERROR_FK, (int) ($e->errorInfo[1] ?? 0));
        }

        $this->assertTrue($modelo::query()->whereKey($codigo)->exists());
    }

    /* --- Ayudantes ------------------------------------------------------ */

    private function errorAlEscribir(string $tabla, int $id, string $columna, string $valor): ?int
    {
        try {
            DB::table($tabla)->where('id', $id)->update([$columna => $valor]);
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }

        return null;
    }

    /** Una fila propia de la prueba en la tabla pedida (cabecera o detalle). */
    private function filaDe(string $tabla): int
    {
        $solicitud = new Solicitud([
            'numero' => 'TST-FK-'.substr(uniqid(), -8),
            'solicitante_nombre' => 'Prueba FK',
        ]);
        $solicitud->forceFill(['estado' => Solicitud::ESTADO_POR_APROBAR])->save();

        if ($tabla === 'tbl_solicitudes') {
            return $solicitud->id;
        }

        $repuesto = Repuesto::create([
            'codigo' => (string) random_int(950000000, 999999999),
            'nombre' => 'Repuesto prueba FK',
            'unidad_medida' => 'UND',
            'existencia' => 1,
            'stock_minimo' => 0,
            'stock_maximo' => 0,
            'estado' => Repuesto::ESTADO_ACTIVO,
        ]);

        return SolicitudItem::create([
            'solicitud_id' => $solicitud->id,
            'repuesto_id' => $repuesto->id,
            'codigo' => 'FK'.substr(uniqid(), -6),
            'nombre' => 'Item prueba FK',
            'cantidad_solicitada' => 1,
        ])->id;
    }

    /** Cinco caracteres: cabe en todas las claves (las mas cortas son varchar(5)). */
    private function codigoLibre(string $modelo): string
    {
        do {
            $codigo = 'Z'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
        } while ($modelo::query()->whereKey($codigo)->exists());

        return $codigo;
    }

    private function maestra(string $modelo): string
    {
        $codigo = $this->codigoLibre($modelo);

        $modelo::create([
            (new $modelo)->getKeyName() => $codigo,
            'col_nombre' => 'Maestra prueba FK '.$codigo,
            'col_activo' => true,
        ]);

        return $codigo;
    }
}
