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

    /**
     * Columnas de las bandejas del panel que se pueden ordenar y filtrar: la
     * LISTA BLANCA de los parametros `orden` y los filtros por columna.
     * RECIBIDA y ACCION quedan fuera a proposito.
     *
     * @var list<string>
     */
    public const COLUMNAS_BANDEJA = ['numero', 'solicitante', 'items', 'estado', 'atendida'];

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
     * Filtros por columna de las bandejas del panel (la fila de cuadros de
     * texto bajo los encabezados). Cada clave es un filtro independiente y
     * todos se combinan con AND.
     *
     * El estado NO esta aqui a proposito: lo aplica scopeEstado(), porque en
     * "Listos para reclamar" lo fija la ruta y ningun filtro puede saltarselo.
     *
     * @param  array{numero?: string, solicitante?: string, items?: string, atendida?: string}  $filtros
     */
    public function scopeFiltrarPorColumnas(Builder $query, array $filtros): Builder
    {
        $numero = trim((string) ($filtros['numero'] ?? ''));

        if ($numero !== '') {
            // Numero reconocible (000004, 4, SOL-2026-000004) -> igualdad
            // exacta contra el formato guardado; cualquier otro texto cae a la
            // coincidencia parcial.
            $normalizado = self::normalizarNumero($numero);

            $normalizado !== null
                ? $query->where('numero', $normalizado)
                : $query->where('numero', 'like', self::patronLike($numero));
        }

        $solicitante = trim((string) ($filtros['solicitante'] ?? ''));

        if ($solicitante !== '') {
            $like = self::patronLike($solicitante);

            $query->where(function (Builder $q) use ($like) {
                $q->where('solicitante_nombre', 'like', $like)
                    ->orWhere('solicitante_cedula', 'like', $like)
                    ->orWhere('solicitante_area', 'like', $like);
            });
        }

        // Cantidad de items: solo un entero. Un texto no numerico se ignora en
        // vez de convertirse en 0, que filtraria por "sin items".
        $items = trim((string) ($filtros['items'] ?? ''));

        if ($items !== '' && ctype_digit($items)) {
            $query->has('items', '=', (int) $items);
        }

        $atendida = trim((string) ($filtros['atendida'] ?? ''));

        if ($atendida !== '') {
            $like = self::patronLike($atendida);

            $query->whereHas('atendidaPor', fn (Builder $q) => $q->where('name', 'like', $like));
        }

        return $query;
    }

    /**
     * Orden de las bandejas del panel. $orden tiene que ser una clave de
     * COLUMNAS_BANDEJA: nada del request llega al ORDER BY sin pasar por ese
     * match. Sin orden (o con uno desconocido) queda el de siempre: por avance
     * del flujo y luego lo mas reciente primero.
     */
    public function scopeOrdenarBandeja(Builder $query, ?string $orden, string $direccion): Builder
    {
        $direccion = $direccion === 'desc' ? 'desc' : 'asc';

        match ($orden) {
            'numero' => $query->orderBy('numero', $direccion),
            'solicitante' => $query->orderBy('solicitante_nombre', $direccion),
            'items' => $query->orderBy(
                SolicitudItem::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('solicitud_items.solicitud_id', 'solicitudes.id'),
                $direccion
            ),
            'estado' => $query->orderByRaw(self::sqlOrdenEstado().' '.$direccion, array_keys(self::ESTADOS)),
            'atendida' => $query->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'solicitudes.atendida_por'),
                $direccion
            ),
            default => $query->orderByRaw("CASE estado
                WHEN 'pendiente' THEN 1
                WHEN 'en_proceso' THEN 2
                WHEN 'listo' THEN 3
                ELSE 4 END"),
        };

        // Desempate estable: sin el, dos filas con el mismo valor podrian
        // saltar de pagina entre una carga y la siguiente.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * CASE que ordena por el avance del flujo (el orden de ESTADOS), no por el
     * texto de la clave: alfabeticamente "entregada" quedaria antes que
     * "pendiente". Las claves viajan como parametros enlazados.
     */
    private static function sqlOrdenEstado(): string
    {
        $casos = collect(array_keys(self::ESTADOS))
            ->map(fn ($clave, $posicion) => 'WHEN ? THEN '.($posicion + 1))
            ->implode(' ');

        return "CASE estado {$casos} ELSE ".(count(self::ESTADOS) + 1).' END';
    }

    /**
     * Patron de coincidencia parcial con el escape de LIKE de SQL Server.
     */
    private static function patronLike(string $termino): string
    {
        return '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';
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
