<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Funcionalidad del panel sobre la que se conceden permisos por rol.
 *
 * Las claves son las que piden las rutas (middleware permiso:clave,accion) y
 * las vistas (@puede). Las siembra FuncionalidadSeeder; el panel no las crea
 * ni las edita, solo marca que puede hacer cada rol sobre ellas.
 */
class Funcionalidad extends Model
{
    protected $table = 'tbl_funcionalidad';

    public const SOLICITUDES = 'solicitudes';

    public const REPUESTOS = 'repuestos';

    public const CATEGORIAS = 'categorias';

    public const ROLES = 'roles';

    public const PERMISOS = 'permisos';

    public const PARAMETROS = 'parametros';

    public const ACCION_VER = 'ver';

    public const ACCION_EDITAR = 'editar';

    public const ACCION_ELIMINAR = 'eliminar';

    /** @var array<string, string> accion => etiqueta, en el orden en que se pintan */
    public const ACCIONES = [
        self::ACCION_VER => 'Ver',
        self::ACCION_EDITAR => 'Editar',
        self::ACCION_ELIMINAR => 'Eliminar',
    ];

    /**
     * Funcionalidades que antes de este modulo cualquier usuario del panel
     * podia usar: el almacenista despachaba solicitudes sin permiso especial.
     *
     * @var list<string>
     */
    private const HEREDADAS_PARA_TODOS = [
        self::SOLICITUDES,
    ];

    /**
     * Funcionalidades que antes protegia el middleware es.admin, es decir, las
     * que concedia col_gestiona_catalogo. Permisos entra aqui porque quien
     * gestionaba los roles ya decidia que podia hacer cada uno.
     *
     * @var list<string>
     */
    private const HEREDADAS_DE_GESTIONA_CATALOGO = [
        self::REPUESTOS,
        self::CATEGORIAS,
        self::ROLES,
        self::PERMISOS,
        self::PARAMETROS,
    ];

    protected $fillable = [
        'col_clave',
        'col_nombre',
        'col_seccion',
        'col_icono',
        'col_orden',
        'col_activo',
    ];

    protected function casts(): array
    {
        return [
            'col_orden' => 'integer',
            'col_activo' => 'boolean',
        ];
    }

    /**
     * Permiso que tenia un rol antes de que existiera la matriz. Es el UNICO
     * sitio que lo reproduce: lo usan User::puede() para un rol que todavia no
     * tiene su matriz guardada, la pantalla para mostrarle lo que hoy puede
     * hacer, y FuncionalidadSeeder para sembrar la matriz inicial.
     *
     * Las tres acciones valen lo mismo porque antes no se distinguian. Una
     * clave que no esta en ninguna lista da false: una funcionalidad nueva no
     * se concede por herencia a nadie.
     */
    public static function permisoHeredado(string $clave, bool $gestionaCatalogo): bool
    {
        if (in_array($clave, self::HEREDADAS_PARA_TODOS, true)) {
            return true;
        }

        return $gestionaCatalogo && in_array($clave, self::HEREDADAS_DE_GESTIONA_CATALOGO, true);
    }

    /**
     * Una accion desconocida es un error de programacion (una ruta mal
     * escrita), no un permiso denegado: se falla en voz alta.
     */
    public static function validarAccion(string $accion): void
    {
        if (! array_key_exists($accion, self::ACCIONES)) {
            throw new InvalidArgumentException(
                "Accion de permiso desconocida: \"{$accion}\". Use ver, editar o eliminar."
            );
        }
    }
}
