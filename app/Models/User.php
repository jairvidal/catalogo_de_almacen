<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROL_ADMIN = 'admin';

    public const ROL_ALMACENISTA = 'almacenista';

    /**
     * Cache de puede(): permisos del rol leidos de la base y el id del rol al
     * que corresponden. No son atributos del modelo, no se persisten.
     *
     * @var array<string, array{ver: bool, editar: bool, eliminar: bool}>|null
     */
    private ?array $permisosCargados = null;

    private ?int $permisosCargadosDelRol = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'rol_id',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /**
     * Rol de tbl_rol asignado al usuario.
     *
     * Se llama `rolAsignado` y no `rol` a proposito: la columna historica
     * `users.rol` guarda la clave como texto y opacaria el nombre de la relacion.
     */
    public function rolAsignado(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    /**
     * Ambos roles entran al panel; solo el admin gestiona el catalogo, los
     * usuarios y los roles.
     *
     * Manda el permiso del rol asignado cuando existe y esta activo; si el
     * usuario todavia no tiene rol_id se cae al valor historico de users.rol.
     *
     * LEGADO: desde el modulo Funciones por perfil ya no autoriza ninguna ruta
     * ni formulario; eso lo hace puede(). Se conserva porque el middleware
     * es.admin sigue registrado y porque col_gestiona_catalogo sigue siendo el
     * respaldo de un rol que todavia no tiene su matriz guardada.
     */
    public function puedeGestionarCatalogo(): bool
    {
        $rol = $this->rolAsignado;

        if ($rol && $rol->col_activo) {
            return $rol->col_gestiona_catalogo;
        }

        return $this->esAdmin();
    }

    /**
     * ¿Puede el usuario ejecutar $accion (ver, editar, eliminar) sobre la
     * funcionalidad $funcionalidad? Es el UNICO punto de decision de permisos
     * del panel: lo usan el middleware permiso:, los FormRequest, la directiva
     * Blade "puede" y el Gate "permiso".
     *
     * Orden de decision, del mas fuerte al mas debil:
     * 1. Rol del sistema admin: siempre si (no se puede dejar el panel sin
     *    nadie capaz de devolver los permisos).
     * 2. Rol asignado y activo con matriz guardada: manda la matriz; una
     *    funcionalidad sin fila no concede nada.
     * 3. Rol asignado y activo SIN matriz guardada: permiso heredado de
     *    col_gestiona_catalogo, que es exactamente lo que hacia es.admin.
     * 4. Sin rol vigente: se cae al texto historico users.rol, igual que
     *    puedeGestionarCatalogo().
     */
    public function puede(string $funcionalidad, string $accion): bool
    {
        Funcionalidad::validarAccion($accion);

        $rol = $this->rolAsignado;

        if (! $rol || ! $rol->col_activo) {
            return $this->esAdmin() || Funcionalidad::permisoHeredado($funcionalidad, false);
        }

        if ($rol->es_admin_del_sistema) {
            return true;
        }

        $permisos = $this->permisosDelRol($rol);

        if ($permisos === null) {
            return Funcionalidad::permisoHeredado($funcionalidad, $rol->col_gestiona_catalogo);
        }

        return $permisos[$funcionalidad][$accion] ?? false;
    }

    /**
     * Descarta los permisos leidos, para que la siguiente consulta vuelva a la
     * base. En una peticion normal no hace falta: el usuario autenticado se
     * carga de nuevo en cada una. Sirve cuando la MISMA instancia sobrevive a
     * un cambio de la matriz (pruebas que reutilizan el usuario de actingAs).
     */
    public function olvidarPermisos(): void
    {
        $this->permisosCargados = null;
        $this->permisosCargadosDelRol = null;
    }

    /**
     * Una sola consulta por instancia (y por lo tanto por peticion), por mucho
     * que el menu, el middleware y los botones del listado pregunten.
     *
     * @return array<string, array{ver: bool, editar: bool, eliminar: bool}>|null
     */
    private function permisosDelRol(Rol $rol): ?array
    {
        if ($this->permisosCargadosDelRol !== $rol->id) {
            $this->permisosCargados = $rol->permisosGuardados();
            $this->permisosCargadosDelRol = $rol->id;
        }

        return $this->permisosCargados;
    }
}
