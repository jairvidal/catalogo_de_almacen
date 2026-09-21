<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Repuesto del catalogo, con la estructura que trae el ERP.
 *
 * Dos columnas de cantidad que NO son lo mismo y no deben confundirse:
 *  - stock      existencia que reporta el ERP. La escribe la sincronizacion
 *               por API y nada mas.
 *  - existencia saldo operativo del sistema (el antiguo cantidad_disponible):
 *               lo topa el Carrito y lo descuenta SolicitudService::marcarListo().
 *
 * Si el ERP pisara `existencia` borraria lo ya despachado y el almacen
 * entregaria contra un saldo fantasma.
 *
 * Y dos marcas de tiempo que TAMPOCO son lo mismo:
 *  - fecha_actual_ERP    cuando el ERP confirmo por ultima vez este repuesto en
 *                        la sincronizacion de inventario. La escribe SOLO
 *                        SincronizadorStockRepuestos. Un NULL o una fecha vieja
 *                        significan "el ERP dejo de reportar este item".
 *  - fecha_actualizacion cuando alguien lo edito en el panel (nombre, foto,
 *                        ajuste de existencia). Es el UPDATED_AT del modelo.
 *
 * Hasta el 2026-09-21 la sincronizacion movia fecha_actualizacion en cada
 * corrida y las dos preguntas —"quien toco esto" y "hace cuanto que el ERP no
 * lo reporta"— se respondian con la misma columna, o sea que no se respondian.
 */
class Repuesto extends Model
{
    use HasFactory;

    /** El repuesto se ve en el catalogo publico. */
    public const ESTADO_ACTIVO = 1;

    /** Anulado: sigue en la tabla porque los items historicos lo apuntan. */
    public const ESTADO_INACTIVO = 0;

    protected $table = 'repuestos';

    /**
     * La tabla del ERP trae sus propias marcas de tiempo en vez de las
     * created_at / updated_at de Laravel. Sin estas dos constantes, cualquier
     * create() o update() fallaria con "Invalid column name 'created_at'".
     */
    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'codigo',
        'cod_referencia',
        'nombre',
        'unidad_medida',
        'ubicacion',
        'cod_cat_1',
        'desc_cat_1',
        'cod_cat_2',
        'desc_cat_2',
        'stock',
        'stock_minimo',
        'stock_maximo',
        'existencia',
        'abastacimiento_alm',
        'tiene_plano',
        'url_plano',
        'tamanio',
        'id_categoria',
        'tiene_foto',
        'foto',
        'estado',
        // fecha_actual_ERP NO es fillable a proposito: la escribe unicamente la
        // sincronizacion con el ERP y jamas un formulario del panel.
    ];

    protected function casts(): array
    {
        return [
            'codigo' => 'integer',
            'estado' => 'integer',
            // El driver de SQL Server devuelve los bigint como cadena; sin el
            // cast, comparar id_categoria con un id ya leido falla por tipo.
            'id_categoria' => 'integer',
            // Las cantidades son decimal(12,3): el almacen mide en KG y hay
            // items con fraccion. Castearlas a integer las truncaba en silencio.
            // Se usa float y no decimal:3 para que la vista imprima "1500" y no
            // "1500.000", y para que las comparaciones en PHP sean numericas.
            'stock' => 'float',
            'stock_minimo' => 'float',
            'stock_maximo' => 'float',
            'existencia' => 'float',
            'abastacimiento_alm' => 'boolean',
            'tiene_plano' => 'boolean',
            'tiene_foto' => 'boolean',
            'fecha_creacion' => 'datetime',
            'fecha_actualizacion' => 'datetime',
            // El nombre lleva ERP en mayusculas por peticion del usuario: SQL
            // Server no distingue la caja del identificador, pero Eloquent si la
            // distingue al leer el atributo y al casear.
            'fecha_actual_ERP' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SolicitudItem::class);
    }

    /**
     * Categoria por color de marco (tbl_categoria).
     *
     * Conserva el nombre categoriaAsignada() aunque la columna de texto
     * `categoria` ya no exista: la taxonomia del ERP (desc_cat_1 / desc_cat_2)
     * sigue siendo otra cosa distinta del color del marco, y renombrar la
     * relacion obligaria a tocar todas las vistas sin ganar nada.
     */
    public function categoriaAsignada(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    /**
     * Busca por codigo, referencia, nombre o taxonomia del ERP.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $like = '%'.str_replace(['%', '_'], ['[%]', '[_]'], $termino).'%';

            $q->where('nombre', 'like', $like)
                ->orWhere('cod_referencia', 'like', $like)
                ->orWhere('desc_cat_1', 'like', $like)
                ->orWhere('desc_cat_2', 'like', $like);

            // codigo es int: un LIKE lo obligaria a convertirse a texto fila por
            // fila y anularia el indice unico. Con termino numerico se compara
            // por igualdad, que si lo aprovecha.
            if (ctype_digit($termino)) {
                $q->orWhere('codigo', (int) $termino);
            }
        });
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    /**
     * Con saldo operativo, que es lo unico que se puede pedir.
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->where('existencia', '>', 0);
    }

    /**
     * Ruta publica de la foto, con imagen generica cuando el item no tiene una.
     */
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto && is_file(public_path('img/'.$this->foto))) {
            return asset('img/'.$this->foto);
        }

        return asset('assets/img/sin-foto.svg');
    }

    public function getSinStockAttribute(): bool
    {
        return $this->existencia <= 0;
    }

    public function getStockBajoAttribute(): bool
    {
        return ! $this->sin_stock && $this->existencia <= $this->stock_minimo;
    }
}
