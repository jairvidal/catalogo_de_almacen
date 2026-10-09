<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Llaves foraneas desde las columnas de referencia de tbl_solicitudes y
 * tbl_solicitud_detalle hacia las maestras del ERP, igualando antes cada
 * columna al tipo, largo y collation de la clave de su maestra (SQL Server
 * exige que coincidan para crear la FK).
 *
 * El largo destino se LEE de la clave de la maestra en sys.columns, no se
 * escribe aqui: tbl_bodega y tbl_unidad_medida las crearon por fuera de la
 * aplicacion y su clave ya cambio una vez (col_cod_bod paso de varchar(5) a
 * varchar(10)). Lo unico fijo es que la clave sea varchar; si no, se aborta.
 *
 * Todas las precondiciones se comprueban para las nueve columnas ANTES de
 * escribir nada, y cualquier fallo aborta con el detalle:
 *  - un valor guardado mas largo que el largo nuevo (no se recorta nada);
 *  - un valor no nulo que no existe en la maestra (huerfano, no se borra);
 *  - una FK con el nombre que se va a crear, o un indice con ese nombre que
 *    no sea exactamente el de esta migracion (si lo es, se suelta y rehace);
 *  - una FK, indice, estadistica manual, CHECK, DEFAULT u objeto dependiente
 *    sobre la columna que impida el ALTER y que no sea de esta migracion.
 *
 * Por cada columna: ALTER COLUMN (solo si difiere de la maestra), indice no
 * agrupado ix_<tabla>_<columna> (SQL Server no indexa las FK solo, y un
 * borrado en la maestra o el importador futuro harian scans) y la FK
 * fk_<tabla>_<columna> con ON DELETE / ON UPDATE NO ACTION: en las maestras
 * anular no es borrar, y una clave que se usa no se debe poder borrar.
 *
 * Las columnas siguen admitiendo NULL. El migrador corre up() y down() dentro
 * de una transaccion (en SQL Server el DDL es transaccional): si algo falla a
 * medias, no queda nada a medias.
 *
 * down() suelta FK e indices y devuelve cada columna a su largo original,
 * abortando antes de tocar nada si algun valor ya no cabe.
 */
return new class extends Migration
{
    /**
     * [tabla, columna, maestra, clave de la maestra, largo original de la columna].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const REFERENCIAS = [
        ['tbl_solicitudes', 'cod_centro_operacion', 'tbl_centro_operacion', 'col_cod_centro_operacion', 50],
        ['tbl_solicitudes', 'cod_centro_de_costo', 'tbl_centro_de_costo', 'col_cod_centro_de_costo', 50],
        ['tbl_solicitudes', 'cod_proyecto', 'tbl_proyecto', 'col_cod_proyecto', 100],
        ['tbl_solicitud_detalle', 'cod_bodega', 'tbl_bodega', 'col_cod_bod', 10],
        ['tbl_solicitud_detalle', 'cod_motivo', 'tbl_motivo', 'col_cod_motivo', 50],
        ['tbl_solicitud_detalle', 'cod_unidad_medida', 'tbl_unidad_medida', 'col_cod_und_med', 5],
        ['tbl_solicitud_detalle', 'cod_centro_operacion', 'tbl_centro_operacion', 'col_cod_centro_operacion', 5],
        ['tbl_solicitud_detalle', 'cod_centro_de_costo', 'tbl_centro_de_costo', 'col_cod_centro_de_costo', 10],
        ['tbl_solicitud_detalle', 'cod_proyecto', 'tbl_proyecto', 'col_cod_proyecto', 100],
    ];

    public function up(): void
    {
        $planes = [];
        $problemas = [];

        foreach (self::REFERENCIAS as [$tabla, $columna, $maestra, $clave]) {
            $actual = $this->columna($tabla, $columna);
            $destino = $this->columna($maestra, $clave);

            if ($actual === null || $destino === null) {
                $problemas[] = "no existe {$tabla}.{$columna} o {$maestra}.{$clave}";

                continue;
            }

            if ($actual->tipo !== 'varchar' || $destino->tipo !== 'varchar') {
                $problemas[] = "{$tabla}.{$columna} es {$actual->tipo} y {$maestra}.{$clave} es {$destino->tipo}; se esperaba varchar en las dos";

                continue;
            }

            $problemas = array_merge(
                $problemas,
                $this->valoresQueNoCaben($tabla, $columna, (int) $destino->largo),
                $this->huerfanos($tabla, $columna, $maestra, $clave),
                $this->nombresOcupados($tabla, $columna),
                $this->dependenciasAjenas($tabla, $columna)
            );

            $planes[] = [$tabla, $columna, $maestra, $clave, $actual, $destino];
        }

        $this->abortarSiHay($problemas, 'No se crearon las llaves foraneas');

        foreach ($planes as [$tabla, $columna, $maestra, $clave, $actual, $destino]) {
            // El indice propio hecho a mano impediria el ALTER: se rehace.
            if ($this->indicePropio($tabla, $columna)) {
                DB::statement('drop index ['.$this->indice($tabla, $columna)."] on [{$tabla}]");
            }

            if ((int) $actual->largo !== (int) $destino->largo || $actual->collation !== $destino->collation) {
                $this->alterarColumna($tabla, $columna, (int) $destino->largo, $destino->collation);
            }

            DB::statement('create nonclustered index ['.$this->indice($tabla, $columna)."] on [{$tabla}] ([{$columna}])");
            DB::statement(
                'alter table ['.$tabla.'] add constraint ['.$this->fk($tabla, $columna).']
                 foreign key (['.$columna.']) references ['.$maestra.'] (['.$clave.'])
                 on delete no action on update no action'
            );
        }
    }

    public function down(): void
    {
        $problemas = [];

        foreach (self::REFERENCIAS as [$tabla, $columna, , , $largoOriginal]) {
            $problemas = array_merge($problemas, $this->valoresQueNoCaben($tabla, $columna, $largoOriginal));
        }

        $this->abortarSiHay($problemas, 'No se revirtieron las llaves foraneas');

        foreach (self::REFERENCIAS as [$tabla, $columna, , , $largoOriginal]) {
            $fk = $this->fk($tabla, $columna);
            $indice = $this->indice($tabla, $columna);

            if (DB::selectOne('select 1 as hay from sys.foreign_keys where name = ? and parent_object_id = object_id(?)', [$fk, 'dbo.'.$tabla])) {
                DB::statement("alter table [{$tabla}] drop constraint [{$fk}]");
            }

            if (DB::selectOne('select 1 as hay from sys.indexes where name = ? and object_id = object_id(?)', [$indice, 'dbo.'.$tabla])) {
                DB::statement("drop index [{$indice}] on [{$tabla}]");
            }

            $actual = $this->columna($tabla, $columna);

            if ($actual !== null && (int) $actual->largo !== $largoOriginal) {
                $this->alterarColumna($tabla, $columna, $largoOriginal, $actual->collation);
            }
        }
    }

    private function alterarColumna(string $tabla, string $columna, int $largo, string $collation): void
    {
        // La collation sale de sys.columns, no del request: se valida igual
        // porque va dentro del DDL, que no admite parametros.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new RuntimeException("Collation inesperada en {$tabla}.{$columna}: {$collation}.");
        }

        DB::statement("alter table [{$tabla}] alter column [{$columna}] varchar({$largo}) collate {$collation} null");
    }

    /** @return list<string> */
    private function valoresQueNoCaben(string $tabla, string $columna, int $largo): array
    {
        // datalength y no len: len ignora los espacios finales, y un varchar
        // que los guarda tampoco cabria. En varchar un byte = un caracter.
        $fila = DB::selectOne(
            "select count(*) as total, max(datalength([{$columna}])) as mayor
               from [{$tabla}] where datalength([{$columna}]) > ?",
            [$largo]
        );

        return (int) $fila->total === 0 ? [] : [
            "{$tabla}.{$columna}: {$fila->total} fila(s) con un valor de mas de {$largo} caracteres (el mayor mide {$fila->mayor})",
        ];
    }

    /** @return list<string> */
    private function huerfanos(string $tabla, string $columna, string $maestra, string $clave): array
    {
        $filas = DB::select(
            "select [{$columna}] as valor, count(*) as total
               from [{$tabla}] s
              where s.[{$columna}] is not null
                and not exists (select 1 from [{$maestra}] m where m.[{$clave}] = s.[{$columna}])
              group by [{$columna}]"
        );

        if ($filas === []) {
            return [];
        }

        $total = array_sum(array_map(fn ($fila) => (int) $fila->total, $filas));
        $muestra = implode(', ', array_map(fn ($fila) => "'{$fila->valor}'", array_slice($filas, 0, 10)));

        return ["{$tabla}.{$columna}: {$total} fila(s) con codigos que no existen en {$maestra} ({$muestra})"];
    }

    /** @return list<string> */
    private function nombresOcupados(string $tabla, string $columna): array
    {
        $problemas = [];
        $fk = $this->fk($tabla, $columna);
        $indice = $this->indice($tabla, $columna);

        if (DB::selectOne('select 1 as hay from sys.objects where name = ?', [$fk])) {
            $problemas[] = "ya existe un objeto llamado {$fk}";
        }

        // Un indice con el nombre de esta migracion sobre la otra tabla o con
        // otras columnas no es nuestro: se aborta. Si es exactamente el nuestro
        // (solo esta columna, en esta tabla) lo resuelve indicePropio().
        $homonimo = DB::selectOne('select object_name(object_id) as tabla from sys.indexes where name = ?', [$indice]);

        if ($homonimo && ! $this->indicePropio($tabla, $columna)) {
            $problemas[] = "ya existe un indice llamado {$indice} (en {$homonimo->tabla}) que no es el de esta columna";
        }

        return $problemas;
    }

    /**
     * El indice ix_<tabla>_<columna> ya existe, en esta tabla, no es unico y
     * cubre solo esta columna: es el que esta migracion iba a crear (hecho a
     * mano). Se suelta y se vuelve a crear, en vez de abortar.
     */
    private function indicePropio(string $tabla, string $columna): bool
    {
        $columnas = DB::select(
            'select c.name as nombre, i.is_unique as unico
               from sys.indexes i
               join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
               join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
              where i.object_id = object_id(?) and i.name = ?',
            ['dbo.'.$tabla, $this->indice($tabla, $columna)]
        );

        return count($columnas) === 1 && $columnas[0]->nombre === $columna && ! (bool) $columnas[0]->unico;
    }

    /**
     * Cualquier cosa sobre la columna que impida el ALTER o que no sea de esta
     * migracion. Las estadisticas automaticas (_WA_Sys) no bloquean y no se
     * cuentan. Como ix_/fk_ ya se reportaron en nombresOcupados(), aqui no hay
     * nada "propio" que resolver: en una base limpia todo esto sale vacio.
     *
     * @return list<string>
     */
    private function dependenciasAjenas(string $tabla, string $columna): array
    {
        $objeto = 'dbo.'.$tabla;
        $parametros = [$objeto, $columna];
        $problemas = [];

        $consultas = [
            'FK' => 'select fk.name as nombre from sys.foreign_keys fk
                       join sys.foreign_key_columns fkc on fkc.constraint_object_id = fk.object_id
                       join sys.columns c on c.object_id = fkc.parent_object_id and c.column_id = fkc.parent_column_id
                      where fkc.parent_object_id = object_id(?) and c.name = ?',
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
            'DEFAULT' => 'select d.name as nombre from sys.default_constraints d
                            join sys.columns c on c.object_id = d.parent_object_id and c.column_id = d.parent_column_id
                           where d.parent_object_id = object_id(?) and c.name = ?',
            'objeto dependiente' => 'select object_name(d.referencing_id) as nombre from sys.sql_expression_dependencies d
                                       join sys.columns c on c.object_id = d.referenced_id and c.column_id = d.referenced_minor_id
                                      where d.referenced_id = object_id(?) and c.name = ?',
        ];

        $propio = $this->indicePropio($tabla, $columna) ? $this->indice($tabla, $columna) : null;

        foreach ($consultas as $tipo => $sql) {
            foreach (DB::select($sql, $parametros) as $fila) {
                if ($tipo === 'indice' && $fila->nombre === $propio) {
                    continue;
                }

                $problemas[] = "{$tabla}.{$columna}: {$tipo} {$fila->nombre} sobre la columna (no es de esta migracion)";
            }
        }

        return $problemas;
    }

    /** @param  list<string>  $problemas */
    private function abortarSiHay(array $problemas, string $titulo): void
    {
        if ($problemas !== []) {
            throw new RuntimeException($titulo.'; no se cambio nada. '.implode('; ', $problemas).'.');
        }
    }

    private function columna(string $tabla, string $columna): ?object
    {
        return DB::selectOne(
            'select type_name(c.user_type_id) as tipo, c.max_length as largo, c.collation_name as collation
               from sys.columns c
              where c.object_id = object_id(?) and c.name = ?',
            ['dbo.'.$tabla, $columna]
        );
    }

    private function fk(string $tabla, string $columna): string
    {
        return "fk_{$tabla}_{$columna}";
    }

    private function indice(string $tabla, string $columna): string
    {
        return "ix_{$tabla}_{$columna}";
    }
};
