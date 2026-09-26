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

    /**
     * Solicitantes del ERP: listado y asignacion de la contrasena del portal de
     * aprobacion. No esta en ninguna lista de herencia: solo la tiene quien la
     * reciba marcada en la matriz (y el admin del sistema, que puede todo).
     */
    public const SOLICITANTES = 'solicitantes';

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

    /**
     * Claves de rol que pueden usar las funcionalidades reservadas.
     *
     * Hoy tbl_rol solo trae "admin" (col_nombre "Administrador", rol del
     * sistema) y "almacenista": el perfil administrador de la instalacion es
     * "admin". Las otras dos claves se dejan previstas para que, el dia que
     * alguien cree un perfil llamado superadministrador o administrador, la
     * regla lo reconozca sin tocar codigo. No se renombra ni se crea ningun rol.
     *
     * @var list<string>
     */
    public const ROLES_ADMINISTRADORES = [
        User::ROL_ADMIN,
        'superadministrador',
        'administrador',
    ];

    /**
     * Funcionalidades reservadas a una lista blanca de claves de rol.
     *
     * "permisos" esta aqui porque editar la matriz equivale a poder darse
     * cualquier permiso del panel, incluido el de volver a editarla: concederlo
     * a un perfil operativo seria una escalada de privilegios en un clic. La
     * lista blanca se evalua ANTES que la matriz, de modo que marcar las
     * casillas de esta funcionalidad a un perfil no autorizado no le concede
     * nada.
     *
     * @var array<string, list<string>>
     */
    private const RESERVADAS = [
        self::PERMISOS => self::ROLES_ADMINISTRADORES,
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
     * ¿La clave de rol $claveRol puede usar la funcionalidad $claveFuncionalidad?
     *
     * Es el UNICO sitio que resuelve la lista blanca de RESERVADAS. Lo consulta
     * User::puede() antes que cualquier otra cosa, y tambien PermisoService y
     * FuncionalidadSeeder para no guardar una marca que no concede nada.
     *
     * Una funcionalidad que no esta reservada la puede usar cualquier rol: la
     * decision vuelve entonces a la matriz, como siempre.
     */
    public static function rolAutorizado(string $claveFuncionalidad, ?string $claveRol): bool
    {
        $autorizados = self::RESERVADAS[$claveFuncionalidad] ?? null;

        if ($autorizados === null) {
            return true;
        }

        return $claveRol !== null && in_array($claveRol, $autorizados, true);
    }

    /**
     * ¿La funcionalidad esta reservada a la lista blanca de roles? Lo usa la
     * pantalla para explicar por que unas casillas salen bloqueadas.
     */
    public static function esReservada(string $claveFuncionalidad): bool
    {
        return array_key_exists($claveFuncionalidad, self::RESERVADAS);
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
