<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * tbl_parametro.col_valor pasa de nvarchar(255) a nvarchar(max) (V 1.4.33).
 *
 * Lo pide la seccion "Importacion ERP" de /admin/parametros: la "Estructura
 * JSON" no cabe en 255 caracteres. Los demas parametros conservan su
 * validacion (ParametroRequest sigue topando a 255): solo cambia lo que la
 * columna admite.
 *
 * En SQL Server la columna lleva un DEFAULT ('') con nombre generado por el
 * motor (DF__tbl_param__col_v__...), que hay que soltar para alterar la columna
 * y volver a crear; se rehace con nombre propio, df_tbl_parametro_col_valor,
 * para que el down() y cualquier migracion futura lo encuentren por nombre.
 *
 * Aborta ANTES de escribir si el catalogo no es el esperado: tipo distinto de
 * nvarchar, collation distinta de la del resto de la tabla, nulabilidad, un
 * DEFAULT distinto de '' (o mas de uno), o cualquier indice, CHECK, estadistica
 * manual u objeto que dependa de la columna. Las estadisticas automaticas
 * (_WA_Sys_...) no bloquean el ALTER y no se cuentan, igual que en la
 * migracion 2026_09_27_100000.
 *
 * Idempotente: si la columna ya es nvarchar(max) (up) o nvarchar(255) (down),
 * no hace nada.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_parametro';

    private const COLUMNA = 'col_valor';

    private const DEFAULT_PROPIO = 'df_tbl_parametro_col_valor';

    /** Largo anterior, en caracteres. */
    private const LARGO_ANTERIOR = 255;

    public function up(): void
    {
        $columna = $this->columna();

        if ($columna->largo === -1) {
            return; // ya es nvarchar(max)
        }

        $this->exigirForma($columna, self::LARGO_ANTERIOR * 2);
        $default = $this->defaultUnico();
        $this->abortarSiHay($this->dependencias(), 'No se amplio tbl_parametro.col_valor');

        $this->soltarDefault($default);
        DB::statement(sprintf(
            'alter table [%s] alter column [%s] nvarchar(max) collate %s not null',
            self::TABLA, self::COLUMNA, $columna->collation
        ));
        $this->crearDefault();
    }

    public function down(): void
    {
        $columna = $this->columna();

        if ($columna->largo === self::LARGO_ANTERIOR * 2) {
            return; // ya es nvarchar(255)
        }

        $this->exigirForma($columna, -1);

        // Un valor que no quepa se perderia truncado: se aborta y se reporta
        // cuales, para que alguien decida que hacer con ellos.
        $largos = DB::select(
            'select col_nombre as nombre, len(col_valor) as largo from tbl_parametro where len(col_valor) > ? order by col_nombre',
            [self::LARGO_ANTERIOR]
        );
        $this->abortarSiHay(
            array_map(fn ($fila) => "{$fila->nombre} tiene {$fila->largo} caracteres", $largos),
            'No se devolvio tbl_parametro.col_valor a nvarchar(255): hay valores que no caben'
        );

        $default = $this->defaultUnico();
        $this->abortarSiHay($this->dependencias(), 'No se devolvio tbl_parametro.col_valor a nvarchar(255)');

        $this->soltarDefault($default);
        DB::statement(sprintf(
            'alter table [%s] alter column [%s] nvarchar(%d) collate %s not null',
            self::TABLA, self::COLUMNA, self::LARGO_ANTERIOR, $columna->collation
        ));
        $this->crearDefault();
    }

    private function columna(): object
    {
        $columna = DB::selectOne(
            'select type_name(c.user_type_id) as tipo, c.max_length as largo, c.is_nullable as nula,
                    c.collation_name as collation,
                    (select top 1 o.collation_name from sys.columns o
                      where o.object_id = c.object_id and o.name = ?) as collation_tabla
               from sys.columns c
              where c.object_id = object_id(?) and c.name = ?',
            ['col_nombre', 'dbo.'.self::TABLA, self::COLUMNA]
        );

        if ($columna === null) {
            throw new RuntimeException('No existe tbl_parametro.col_valor; no se cambio nada.');
        }

        $columna->largo = (int) $columna->largo;

        return $columna;
    }

    /** Tipo, largo (en bytes, -1 = max), nulabilidad y collation esperados. */
    private function exigirForma(object $columna, int $largoEsperado): void
    {
        $problemas = [];

        if ($columna->tipo !== 'nvarchar') {
            $problemas[] = "el tipo es {$columna->tipo} y se esperaba nvarchar";
        }

        if ($columna->largo !== $largoEsperado) {
            $problemas[] = "max_length es {$columna->largo} y se esperaba {$largoEsperado}";
        }

        if ((int) $columna->nula === 1) {
            $problemas[] = 'admite NULL y se esperaba NOT NULL';
        }

        // La collation se conserva tal cual; se exige que sea la del resto de
        // la tabla para no reescribir algo que alguien cambio a proposito.
        if ($columna->collation !== $columna->collation_tabla || ! preg_match('/^[A-Za-z0-9_]+$/', (string) $columna->collation)) {
            $problemas[] = "collation inesperada ({$columna->collation})";
        }

        $this->abortarSiHay($problemas, 'tbl_parametro.col_valor no tiene la forma esperada');
    }

    /** El unico DEFAULT de la columna, que tiene que ser (''). */
    private function defaultUnico(): ?string
    {
        $defaults = DB::select(
            'select d.name as nombre, d.definition as definicion from sys.default_constraints d
               join sys.columns c on c.object_id = d.parent_object_id and c.column_id = d.parent_column_id
              where d.parent_object_id = object_id(?) and c.name = ?',
            ['dbo.'.self::TABLA, self::COLUMNA]
        );

        if (count($defaults) > 1) {
            $this->abortarSiHay(['hay '.count($defaults).' DEFAULT sobre la columna'], 'DEFAULT inesperado en tbl_parametro.col_valor');
        }

        if ($defaults === []) {
            return null;
        }

        if (! in_array($defaults[0]->definicion, ["('')", "(N'')"], true)) {
            $this->abortarSiHay(["el DEFAULT {$defaults[0]->nombre} vale {$defaults[0]->definicion} y se esperaba ''"], 'DEFAULT inesperado en tbl_parametro.col_valor');
        }

        return $defaults[0]->nombre;
    }

    /** @return list<string> */
    private function dependencias(): array
    {
        $parametros = ['dbo.'.self::TABLA, self::COLUMNA];
        $problemas = [];

        $consultas = [
            'indice' => 'select i.name as nombre from sys.indexes i
                           join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
                           join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
                          where i.object_id = object_id(?) and c.name = ?',
            'estadistica' => 'select s.name as nombre from sys.stats s
                                join sys.stats_columns sc on sc.object_id = s.object_id and sc.stats_id = s.stats_id
                                join sys.columns c on c.object_id = sc.object_id and c.column_id = sc.column_id
                               where s.object_id = object_id(?) and c.name = ? and s.user_created = 1',
            'CHECK' => 'select k.name as nombre from sys.check_constraints k
                          join sys.columns c on c.object_id = k.parent_object_id and c.column_id = k.parent_column_id
                         where k.parent_object_id = object_id(?) and c.name = ?',
            'objeto dependiente' => 'select object_name(d.referencing_id) as nombre from sys.sql_expression_dependencies d
                                       join sys.columns c on c.object_id = d.referenced_id and c.column_id = d.referenced_minor_id
                                      where d.referenced_id = object_id(?) and c.name = ?',
        ];

        foreach ($consultas as $tipo => $sql) {
            foreach (DB::select($sql, $parametros) as $fila) {
                $problemas[] = "{$tipo} {$fila->nombre} sobre la columna";
            }
        }

        return $problemas;
    }

    private function soltarDefault(?string $nombre): void
    {
        if ($nombre !== null) {
            DB::statement(sprintf('alter table [%s] drop constraint [%s]', self::TABLA, str_replace(']', ']]', $nombre)));
        }
    }

    private function crearDefault(): void
    {
        DB::statement(sprintf(
            "alter table [%s] add constraint [%s] default ('') for [%s]",
            self::TABLA, self::DEFAULT_PROPIO, self::COLUMNA
        ));
    }

    /** @param  list<string>  $problemas */
    private function abortarSiHay(array $problemas, string $titulo): void
    {
        if ($problemas !== []) {
            throw new RuntimeException($titulo.'; no se cambio nada. '.implode('; ', $problemas).'.');
        }
    }
};
