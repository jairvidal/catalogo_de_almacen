<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siete columnas nuevas en tbl_solicitudes, todas NULL, con los nombres y
 * tipos EXACTOS que pidio el usuario. Solo esquema: ningun formulario, vista
 * ni servicio las escribe todavia, y su significado de negocio esta
 * pendiente de definir (ver CLAUDE.md).
 *
 * Sin prefijo col_ porque tbl_solicitudes es anterior a la convencion.
 *
 * VARCHAR, no NVARCHAR: se usa SQL nativo porque $table->string() genera
 * nvarchar en sqlsrv. La pagina de codigos la da la collation por defecto de
 * la base (Modern_Spanish_CI_AS = Windows-1252): un caracter fuera de ella se
 * guarda como '?'.
 *
 * `cod_OC` conserva la mayuscula a peticion del usuario. SQL Server no
 * distingue la caja del identificador, pero Eloquent si: el atributo y el cast
 * se llaman `cod_OC`, nunca `cod_oc`.
 *
 * Si alguna de las columnas ya existe, la migracion aborta ANTES de escribir
 * y dice cuales: no se asume que una columna creada a mano tenga el tipo
 * pedido ni se la suelta en el down().
 */
return new class extends Migration
{
    private const TABLA = 'tbl_solicitudes';

    /** nombre => definicion SQL Server. */
    private const COLUMNAS = [
        'cod_referencia' => 'varchar(50) null',
        'cod_OC' => 'varchar(50) null',
        'dias_entrega' => 'int null',
        'centro_operacion' => 'varchar(50) null',
        'cod_centro_de_costo' => 'varchar(50) null',
        'proyecto' => 'varchar(100) null',
        'enviado' => 'varchar(1000) null',
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
