<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Descripcion visual de una foto de public/img (tbl_imagen_indice).
 *
 * La escribe UNICAMENTE App\Services\IndexadorImagenesCatalogo y la lee
 * App\Services\BuscadorRepuestosPorImagen. Una fila por archivo; el repuesto
 * se resuelve al consultar (ver la migracion 2026_09_30_100000).
 */
class ImagenIndice extends Model
{
    protected $table = 'tbl_imagen_indice';

    /** Digitos iniciales con sufijo opcional _vN: 0003729.jpg, 0015040_v2.png. */
    public const PATRON_CODIGO = '/^(\d+)(?:_v\d+)?$/';

    protected $fillable = [
        'col_archivo',
        'col_hash',
        'col_codigo',
        'col_descripcion',
        'col_modelo',
        'col_indexado_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'col_codigo' => 'integer',
            'col_indexado_at' => 'datetime',
        ];
    }

    /**
     * Codigo del repuesto que se deduce del nombre del archivo, o null si el
     * nombre no sigue el patron (activo_fijo_*.png). Mismo criterio que
     * repuestos:vincular-fotos.
     */
    public static function codigoDeArchivo(string $archivo): ?int
    {
        if (! preg_match(self::PATRON_CODIGO, pathinfo($archivo, PATHINFO_FILENAME), $partes)) {
            return null;
        }

        // int de SQL Server: un nombre con mas digitos no es un codigo real.
        return strlen(ltrim($partes[1], '0')) <= 9 ? (int) $partes[1] : null;
    }
}
