<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El consecutivo de la solicitud pasa de SOL-{anio}-{6 digitos} a solo los seis
 * digitos (000001). Se cambia el DATO GUARDADO, no lo que se muestra, asi que
 * las filas que ya existen tienen que perder el prefijo aqui.
 *
 * Es idempotente: el UPDATE se acota a las filas que todavia traen el prefijo,
 * de modo que volver a correrla no toca nada. Y sirve para cualquier anio del
 * prefijo, no solo 2026, porque el contador era por anio.
 */
return new class extends Migration
{
    /**
     * Digitos del consecutivo. Va escrito aqui y no tomado de
     * Solicitud::LONGITUD_NUMERO a proposito: una migracion describe el estado
     * de la base en su fecha y no puede cambiar de significado el dia que ese
     * formato cambie.
     */
    private const DIGITOS = 6;

    /**
     * LIKE de SQL Server, sin comodines: exige tambien la longitud exacta.
     */
    private const PATRON_HISTORICO = 'SOL-[0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]';

    private const PATRON_NUEVO = '[0-9][0-9][0-9][0-9][0-9][0-9]';

    public function up(): void
    {
        $this->abortarSiHayColisiones();

        DB::statement(
            'update solicitudes
                set numero = right(numero, '.self::DIGITOS.')
              where numero like ?',
            [self::PATRON_HISTORICO]
        );
    }

    /**
     * Repone el prefijo con el anio en que se creo la solicitud, que es el que
     * usaba el generador anterior (tomaba el anio del momento de la creacion).
     * Es una reconstruccion, no una copia del valor original: el anio no quedo
     * guardado en ninguna otra columna.
     */
    public function down(): void
    {
        DB::statement(
            "update solicitudes
                set numero = 'SOL-' + cast(year(coalesce(created_at, getdate())) as varchar(4)) + '-' + numero
              where numero like ?",
            [self::PATRON_NUEVO]
        );
    }

    /**
     * Dos filas de anios distintos con el mismo consecutivo (SOL-2025-000001 y
     * SOL-2026-000001) quedarian con el mismo numero y reventarian contra el
     * indice unico con un error de SQL Server que no dice nada util. Se detecta
     * antes y se aborta con el detalle, sin haber escrito una sola fila.
     */
    private function abortarSiHayColisiones(): void
    {
        $colisiones = DB::select(
            'select right(numero, '.self::DIGITOS.') as consecutivo, count(*) as filas
               from solicitudes
              where numero like ? or numero like ?
              group by right(numero, '.self::DIGITOS.')
             having count(*) > 1',
            [self::PATRON_HISTORICO, self::PATRON_NUEVO]
        );

        if ($colisiones === []) {
            return;
        }

        $detalle = collect($colisiones)
            ->map(fn ($fila) => $fila->consecutivo.' ('.$fila->filas.' filas)')
            ->implode(', ');

        throw new RuntimeException(
            'No se puede quitar el prefijo del consecutivo: quedarian numeros repetidos y '.
            'solicitudes.numero tiene indice unico. Consecutivos en conflicto: '.$detalle.'. '.
            'Renumere esas solicitudes a mano antes de volver a correr la migracion.'
        );
    }
};
