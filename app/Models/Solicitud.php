<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Solicitud extends Model
{
    use HasFactory;

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_EN_PROCESO = 'en_proceso';

    public const ESTADO_LISTO = 'listo';

    public const ESTADO_ENTREGADA = 'entregada';

    public const ESTADO_RECHAZADA = 'rechazada';

    /**
     * Etiqueta, clase de color e icono de cada estado, para las vistas.
     *
     * Las clases son propias (text-bg-estado-*, definidas en app.css) y no las
     * de Bootstrap: con el rojo de marca en la paleta, 'danger' y 'primary'
     * habrian dejado 'rechazada' y 'entregada' indistinguibles del color de
     * marca. Ningun estado usa el rojo de senalizacion; ese queda para las
     * acciones. Ver el bloque "Estados de la solicitud" en app.css.
     *
     * @var array<string, array{label: string, color: string, icono: string}>
     */
    public const ESTADOS = [
        self::ESTADO_PENDIENTE => ['label' => 'Pendiente', 'color' => 'estado-pendiente', 'icono' => 'hourglass'],
        self::ESTADO_EN_PROCESO => ['label' => 'En proceso', 'color' => 'estado-en-proceso', 'icono' => 'box-seam'],
        self::ESTADO_LISTO => ['label' => 'Listo para reclamar', 'color' => 'estado-listo', 'icono' => 'check-circle'],
        self::ESTADO_ENTREGADA => ['label' => 'Entregada', 'color' => 'estado-entregada', 'icono' => 'bag-check'],
        self::ESTADO_RECHAZADA => ['label' => 'Rechazada', 'color' => 'estado-rechazada', 'icono' => 'x-circle'],
    ];

    protected $table = 'solicitudes';

    protected $fillable = [
        'numero',
        'solicitante_nombre',
        'solicitante_cedula',
        'solicitante_email',
        'solicitante_telefono',
        'solicitante_area',
        'observaciones',
        'estado',
        'nota_almacen',
        'atendida_por',
        'fecha_en_proceso',
        'fecha_listo',
        'fecha_entrega',
        'notificado_at',
        'error_notificacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_en_proceso' => 'datetime',
            'fecha_listo' => 'datetime',
            'fecha_entrega' => 'datetime',
            'notificado_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudItem::class);
    }

    public function atendidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }

    public function scopeEstado(Builder $query, ?string $estado): Builder
    {
        return $estado ? $query->where('estado', $estado) : $query;
    }

    /**
     * Busca por numero de solicitud, nombre, cedula o correo del solicitante.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('numero', 'like', $like)
                ->orWhere('solicitante_nombre', 'like', $like)
                ->orWhere('solicitante_cedula', 'like', $like)
                ->orWhere('solicitante_email', 'like', $like);
        });
    }

    public function getEstadoLabelAttribute(): string
    {
        return self::ESTADOS[$this->estado]['label'] ?? $this->estado;
    }

    public function getEstadoColorAttribute(): string
    {
        return self::ESTADOS[$this->estado]['color'] ?? 'estado-pendiente';
    }

    public function getEstadoIconoAttribute(): string
    {
        return self::ESTADOS[$this->estado]['icono'] ?? 'circle';
    }

    public function getTotalUnidadesAttribute(): int
    {
        return (int) $this->items->sum('cantidad_solicitada');
    }

    /**
     * Una solicitud ya entregada o rechazada no admite mas cambios de estado.
     */
    public function getEstaCerradaAttribute(): bool
    {
        return in_array($this->estado, [self::ESTADO_ENTREGADA, self::ESTADO_RECHAZADA], true);
    }
}
