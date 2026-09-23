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
     * Digitos del consecutivo que se guarda en `numero`: 000001, 000002...
     *
     * El numero dejo de llevar el prefijo SOL-{anio}- y por eso el contador es
     * GLOBAL y no se reinicia cada anio: sin el anio adentro, volver a 1 en
     * enero chocaria contra el indice unico de la columna.
     */
    public const LONGITUD_NUMERO = 6;

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
     * Lleva al formato guardado hoy (6 digitos) lo que una persona escriba como
     * numero de solicitud. Es el UNICO sitio que traduce formatos de numero.
     *
     * Acepta tres formas y devuelve null para cualquier otra cosa:
     *  - el formato vigente, con o sin los ceros de la izquierda: 000004 o 4;
     *  - el formato historico con prefijo por anio: SOL-2026-000004. Se admite
     *    CUALQUIER anio porque ese prefijo dejo de generarse, pero quedo en los
     *    correos ya enviados y en los numeros que la gente anoto.
     *
     * Devolver null en vez de la cadena original es a proposito: quien llama
     * tiene que poder distinguir "esto no es un numero de solicitud" de un
     * numero valido, sin volver a mirar el texto.
     */
    public static function normalizarNumero(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        $digitos = '\d{1,'.self::LONGITUD_NUMERO.'}';

        if (preg_match('/^(?:SOL-\d{4}-)?('.$digitos.')$/i', $texto, $partes) !== 1) {
            return null;
        }

        return str_pad($partes[1], self::LONGITUD_NUMERO, '0', STR_PAD_LEFT);
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

        // Quien pegue en el buscador un numero del formato historico
        // (SOL-2026-000004) no encontraria nada con el LIKE: en la columna hoy
        // solo estan los 6 digitos. Se agrega una igualdad exacta contra el
        // numero normalizado, no un LIKE, para no ensuciar el resultado cuando
        // el termino es en realidad una cedula corta.
        $numero = self::normalizarNumero($termino);

        return $query->where(function (Builder $q) use ($like, $numero) {
            $q->where('numero', 'like', $like)
                ->orWhere('solicitante_nombre', 'like', $like)
                ->orWhere('solicitante_cedula', 'like', $like)
                ->orWhere('solicitante_email', 'like', $like);

            if ($numero !== null) {
                $q->orWhere('numero', $numero);
            }
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
