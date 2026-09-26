<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Diez columnas nuevas en tbl_solicitud_detalle, todas NULL, con los nombres y
 * tipos que pidio el usuario. Solo esquema: ningun formulario, vista ni
 * servicio las escribe todavia, y su significado de negocio esta pendiente de
 * definir (ver CLAUDE.md). Mismo patron que 2026_09_26_100500 en
 * tbl_solicitudes.
 *
 * Sin prefijo col_ porque la tabla es anterior a la convencion.
 *
 *  - `cantidad` es decimal(12,3), como repuestos.stock / existencia. El
 *    usuario pidio decimal(4,4), que solo admite valores menores que 1, y
 *    eligio decimal(12,3) al explicarselo. NO es cantidad_solicitada ni
 *    cantidad_entregada: su relacion con ellas esta pendiente de definir.
 *  - `descripcion_item` va sin tilde por la convencion del proyecto (el
 *    usuario la escribio con tilde y eligio sin ella).
 *  - `notas_item` es otra columna distinta de la `nota` que ya existe.
 *
 * VARCHAR, no NVARCHAR: SQL nativo porque $table->string() genera nvarchar en
 * sqlsrv. La pagina de codigos es la de la collation por defecto de la base
 * (Modern_Spanish_CI_AS = Windows-1252).
 *
 * Si alguna de las columnas ya existe, la migracion aborta ANTES de escribir
 * y dice cuales; down() suelta las que encuentre.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_solicitud_detalle';

    /** nombre => definicion SQL Server. */
    private const COLUMNAS = [
        'cod_bodega' => 'varchar(10) null',
        'cod_motivo' => 'varchar(50) null',
        'cantidad' => 'decimal(12,3) null',
        'cod_unidad_medida' => 'varchar(5) null',
        'cod_unidad_negocio' => 'varchar(5) null',
        'cod_centro_operacion' => 'varchar(5) null',
        'cod_centro_de_costo' => 'varchar(10) null',
        'cod_proyecto' => 'varchar(100) null',
        'notas_item' => 'varchar(500) null',
        'descripcion_item' => 'varchar(500) null',
    ];

    public function up(): void
    {
        $existentes = $this->columnasExistentes();

        if ($existentes !== []) {
            throw new RuntimeException(
                'Ya existen en '.self::TABLA.' las columnas: '.implode(', ', $existentes)
                .'. No se cambio nada: revise su tipo a mano antes de migrar.'
            );
        }

        foreach (self::COLUMNAS as $nombre => $definicion) {
            DB::statement('alter table ['.self::TABLA.'] add ['.$nombre.'] '.$definicion);
        }
    }

    public function down(): void
    {
        $existentes = $this->columnasExistentes();

        if ($existentes !== []) {
            DB::statement(
                'alter table ['.self::TABLA.'] drop column '
                .implode(', ', array_map(fn (string $nombre) => '['.$nombre.']', $existentes))
            );
        }
    }

    /**
     * Columnas de COLUMNAS que ya estan en la tabla, con el nombre tal como
     * lo guarda el catalogo.
     *
     * @return list<string>
     */
    private function columnasExistentes(): array
    {
        $nombres = array_keys(self::COLUMNAS);
        $marcas = implode(', ', array_fill(0, count($nombres), '?'));

        $filas = DB::select(
            "select c.name as nombre
               from sys.columns c
              where c.object_id = object_id(?) and c.name in ({$marcas})
              order by c.column_id",
            ['dbo.'.self::TABLA, ...$nombres]
        );

        return array_map(fn (object $fila) => $fila->nombre, $filas);
    }
};
