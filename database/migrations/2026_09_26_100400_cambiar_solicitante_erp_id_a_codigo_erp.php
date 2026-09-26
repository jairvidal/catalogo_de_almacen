<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tbl_solicitudes.solicitante_erp_id` deja de guardar el `id` de
 * tbl_solicitante_erp y pasa a guardar su CODIGO DEL ERP (`col_codigo_erp`):
 * de bigint a nvarchar(30), con la misma collation que col_codigo_erp.
 *
 * La columna conserva su nombre (no se pidio renombrarla).
 *
 * SQL Server no convierte un bigint en otro dato distinto con ALTER COLUMN,
 * asi que se hace en pasos: columna temporal, se puebla cruzando cada id con
 * su codigo, se sueltan el indice y la FK que dependan de la vieja (leidos del
 * catalogo: en desarrollo la FK no existe, en otra base puede que si), se
 * suelta la vieja, se renombra la temporal y se recrean indice y FK.
 *
 * FK hacia col_codigo_erp: SI. El codigo es unico (indice
 * tbl_solicitante_erp_col_codigo_erp_unique) y estable: el MERGE de
 * ImportadorSolicitantesErp empareja POR codigo, nunca lo actualiza y nunca
 * borra filas, y no hay CRUD en el panel. Un codigo que el ERP cambiara
 * entraria como una persona nueva, no pisaria la vieja. Con la FK, la base
 * garantiza que ninguna solicitud apunte a un codigo inexistente aunque la
 * escriba otro cliente o un script manual.
 *
 * Una solicitud con id que no tenga solicitante aborta la migracion ANTES de
 * escribir nada, con la lista de las afectadas: no se dejan nulos en silencio.
 * Las historicas (null) siguen null. El migrador corre todo en una transaccion
 * y en SQL Server el DDL es transaccional.
 *
 * down() hace el camino inverso (codigo -> id) y recrea la FK hacia `id`, que
 * es lo que declara la migracion 2026_09_25_100100 y lo que su down() espera
 * encontrar. Ojo: en la base de desarrollo esa FK no existia antes de esta
 * migracion, asi que un rollback la deja creada.
 */
return new class extends Migration
{
    private const TABLA = 'tbl_solicitudes';

    private const COLUMNA = 'solicitante_erp_id';

    private const TEMPORAL = 'solicitante_erp_id_tmp';

    private const TABLA_ERP = 'tbl_solicitante_erp';

    private const INDICE = 'tbl_solicitudes_solicitante_erp_id_index';

    private const FK = 'tbl_solicitudes_solicitante_erp_id_foreign';

    /** Largo en caracteres de col_codigo_erp (y de la columna nueva). */
    private const LARGO_CODIGO = 30;

    public function up(): void
    {
        $this->exigirTipoActual('bigint');

        $codigo = $this->columna(self::TABLA_ERP, 'col_codigo_erp');

        if ($codigo === null || $codigo->tipo !== 'nvarchar' || (int) $codigo->largo !== self::LARGO_CODIGO * 2) {
            throw new RuntimeException(
                'tbl_solicitante_erp.col_codigo_erp no es nvarchar('.self::LARGO_CODIGO.'): '
                .'revise el tipo antes de migrar, no se trunca ni se improvisa.'
            );
        }

        $this->convertir(
            definicionNueva: 'nvarchar('.self::LARGO_CODIGO.') collate '.$codigo->collation.' null',
            cruceActual: 'id',
            cruceNuevo: 'col_codigo_erp'
        );
    }

    public function down(): void
    {
        $this->exigirTipoActual('nvarchar');

        $this->convertir(
            definicionNueva: 'bigint null',
            cruceActual: 'col_codigo_erp',
            cruceNuevo: 'id'
        );
    }

    /**
     * Reescribe la columna cruzando por tbl_solicitante_erp: el valor actual
     * se busca en $cruceActual y se reemplaza por el de $cruceNuevo, que es
     * tambien la columna a la que apunta la FK recreada.
     */
    private function convertir(string $definicionNueva, string $cruceActual, string $cruceNuevo): void
    {
        $this->abortarSiHayHuerfanas($cruceActual);

        $this->soltarDependencias();

        DB::statement('alter table ['.self::TABLA.'] add ['.self::TEMPORAL.'] '.$definicionNueva);

        DB::update(
            'update s set s.['.self::TEMPORAL.'] = e.['.$cruceNuevo.']
               from ['.self::TABLA.'] s
               join ['.self::TABLA_ERP.'] e on e.['.$cruceActual.'] = s.['.self::COLUMNA.']'
        );

        // Segunda comprobacion, ya con los datos escritos: cada valor viejo
        // tiene que haber producido uno nuevo. Si no, la transaccion revierte.
        $sinConvertir = DB::selectOne(
            'select count(*) as total from ['.self::TABLA.']
              where ['.self::COLUMNA.'] is not null and ['.self::TEMPORAL.'] is null'
        )->total;

        if ((int) $sinConvertir > 0) {
            throw new RuntimeException("{$sinConvertir} solicitud(es) quedaron sin convertir; no se cambio nada.");
        }

        DB::statement('alter table ['.self::TABLA.'] drop column ['.self::COLUMNA.']');
        DB::statement('exec sp_rename ?, ?, ?', ['dbo.'.self::TABLA.'.'.self::TEMPORAL, self::COLUMNA, 'COLUMN']);

        DB::statement('create index ['.self::INDICE.'] on ['.self::TABLA.'] (['.self::COLUMNA.'])');
        DB::statement(
            'alter table ['.self::TABLA.'] add constraint ['.self::FK.']
             foreign key (['.self::COLUMNA.']) references ['.self::TABLA_ERP.'] (['.$cruceNuevo.'])'
        );
    }

    /**
     * Aborta antes de escribir si alguna solicitud apunta a un solicitante que
     * no existe, y dice cuales para que se corrijan a mano.
     */
    private function abortarSiHayHuerfanas(string $cruceActual): void
    {
        $huerfanas = DB::select(
            'select top 20 s.[id], s.[numero], s.['.self::COLUMNA.'] as valor
               from ['.self::TABLA.'] s
              where s.['.self::COLUMNA.'] is not null
                and not exists (select 1 from ['.self::TABLA_ERP.'] e where e.['.$cruceActual.'] = s.['.self::COLUMNA.'])
              order by s.[id]'
        );

        if ($huerfanas !== []) {
            $detalle = implode(', ', array_map(
                fn (object $fila) => "id {$fila->id} (numero {$fila->numero}, {$cruceActual} {$fila->valor})",
                $huerfanas
            ));

            throw new RuntimeException(
                'Hay solicitudes cuyo solicitante_erp_id no tiene solicitante en tbl_solicitante_erp.'
                ." No se cambio nada. Primeras afectadas: {$detalle}."
            );
        }
    }

    /**
     * Suelta las FK y los indices que incluyan la columna, sea cual sea su
     * nombre: las bases no son identicas.
     */
    private function soltarDependencias(): void
    {
        $fks = DB::select(
            'select distinct fk.name as nombre
               from sys.foreign_keys fk
               join sys.foreign_key_columns fkc on fkc.constraint_object_id = fk.object_id
               join sys.columns c on c.object_id = fkc.parent_object_id and c.column_id = fkc.parent_column_id
              where fkc.parent_object_id = object_id(?) and c.name = ?',
            ['dbo.'.self::TABLA, self::COLUMNA]
        );

        foreach ($fks as $fk) {
            DB::statement('alter table ['.self::TABLA.'] drop constraint ['.$fk->nombre.']');
        }

        $indices = DB::select(
            'select distinct i.name as nombre
               from sys.indexes i
               join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
               join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
              where i.object_id = object_id(?) and c.name = ? and i.is_primary_key = 0',
            ['dbo.'.self::TABLA, self::COLUMNA]
        );

        foreach ($indices as $indice) {
            DB::statement('drop index ['.$indice->nombre.'] on ['.self::TABLA.']');
        }
    }

    private function exigirTipoActual(string $tipo): void
    {
        $columna = $this->columna(self::TABLA, self::COLUMNA);

        if ($columna === null) {
            throw new RuntimeException('No existe la columna '.self::TABLA.'.'.self::COLUMNA.'.');
        }

        if ($columna->tipo !== $tipo) {
            throw new RuntimeException(
                self::TABLA.'.'.self::COLUMNA." es {$columna->tipo} y se esperaba {$tipo}: "
                .'la migracion ya se aplico o la base no esta en el estado esperado.'
            );
        }

        if (Schema::hasColumn(self::TABLA, self::TEMPORAL)) {
            throw new RuntimeException('Ya existe la columna temporal '.self::TEMPORAL.': revise una corrida anterior a medias.');
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
};
