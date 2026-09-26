<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Persona del ERP que puede pedir repuestos al almacen.
 *
 * Es la lista cerrada que alimenta el cuadro "Solicitante" del formulario
 * publico. La escribe UNICAMENTE App\Services\ImportadorSolicitantesErp (hoy
 * desde un CSV, manana desde la API del ERP); no hay CRUD en el panel.
 *
 * La solicitud copia nombre, cedula, correo, area y telefono al crearse
 * (snapshot): corregir aqui a una persona no altera el historico.
 */
class SolicitanteErp extends Model
{
    protected $table = 'tbl_solicitante_erp';

    /**
     * Minimo de caracteres para buscar y maximo de resultados que devuelve el
     * buscador publico.
     */
    public const MINIMO_BUSQUEDA = 2;

    public const MAXIMO_RESULTADOS = 20;

    /**
     * Palabras del termino que se tienen en cuenta: cada una agrega un LIKE,
     * asi que se topan para que un texto largo no arme una consulta enorme.
     */
    private const MAXIMO_PALABRAS = 5;

    protected $fillable = [
        'col_codigo_erp',
        'col_nombre',
        'col_cedula',
        'col_correo',
        'col_area',
        'col_telefono',
        'col_activo',
    ];

    protected function casts(): array
    {
        return [
            'col_activo' => 'boolean',
        ];
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(Solicitud::class, 'solicitante_erp_id');
    }

    /**
     * Busca solicitantes ACTIVOS por nombre para el cuadro combinado publico.
     *
     * Cada palabra del termino tiene que aparecer en el nombre (AND), asi
     * "perez juan" encuentra a "JUAN CARLOS PEREZ". Primero salen los que
     * empiezan por el termino, luego el resto en orden alfabetico.
     *
     * Devuelve SOLO id, nombre y area. El endpoint que lo usa no tiene
     * autenticacion: la cedula y el correo nunca salen de aqui. Tampoco se
     * busca por cedula, porque eso permitiria confirmar a quien pertenece un
     * numero de documento.
     *
     * @return list<array{id: int, nombre: string, area: ?string}>
     */
    public static function buscarActivos(string $termino): array
    {
        $palabras = array_slice(
            preg_split('/\s+/', trim($termino), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            0,
            self::MAXIMO_PALABRAS
        );

        if (mb_strlen(implode(' ', $palabras)) < self::MINIMO_BUSQUEDA) {
            return [];
        }

        $condiciones = implode(' and ', array_fill(0, count($palabras), '[col_nombre] like ?'));
        $parametros = array_map(fn (string $palabra) => '%'.self::escaparLike($palabra).'%', $palabras);

        $filas = DB::select(
            'select top ('.self::MAXIMO_RESULTADOS.') [id], [col_nombre], [col_area]
               from [tbl_solicitante_erp]
              where [col_activo] = 1 and '.$condiciones.'
              order by case when [col_nombre] like ? then 0 else 1 end, [col_nombre], [id]',
            [...$parametros, self::escaparLike(implode(' ', $palabras)).'%']
        );

        return array_map(fn ($fila) => [
            'id' => (int) $fila->id,
            'nombre' => $fila->col_nombre,
            'area' => $fila->col_area,
        ], $filas);
    }

    /**
     * Escape de LIKE de SQL Server: % y _ literales van entre corchetes. El
     * corchete de apertura tambien, porque abriria una clase de caracteres.
     */
    public static function escaparLike(string $termino): string
    {
        return str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $termino);
    }
}
