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
     */
    public function puedeGestionarCatalogo(): bool
    {
        $rol = $this->rolAsignado;

        if ($rol && $rol->col_activo) {
            return $rol->col_gestiona_catalogo;
        }

        return $this->esAdmin();
    }
}
