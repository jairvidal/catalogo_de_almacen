<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudItem extends Model
{
    use HasFactory;

    /**
     * Columnas de referencia POR ITEM que el solicitante diligencia al
     * APROBAR, con su etiqueta. Es la lista blanca de lo que
     * AprobacionSolicitudService puede escribir en el detalle y el orden en
     * que se muestran: en la aprobacion, cada una es una columna de la tabla
     * REPUESTOS, en el mismo renglon del item y en este orden.
     *
     * @var array<string, string>
     */
    public const REFERENCIAS_APROBACION = [
        'cod_motivo' => 'Motivo',
        'cod_bodega' => 'Bodega',
        'cod_unidad_medida' => 'Und. medida',
        'cod_centro_operacion' => 'Centro de op.',
        'cod_centro_de_costo' => 'Centro de costo',
        'cantidad' => 'Cantidad',
        'cod_unidad_negocio' => 'Unidad de negocio',
        'cod_proyecto' => 'Proyecto',
        'notas_item' => 'Notas',
        'descripcion_item' => 'Descripcion',
    ];

    /** Las de REFERENCIAS_APROBACION que son codigos de una maestra del ERP => relacion. */
    public const REFERENCIAS_DE_MAESTRA = [
        'cod_motivo' => 'motivo',
        'cod_bodega' => 'bodega',
        'cod_unidad_medida' => 'unidadMedida',
        'cod_centro_operacion' => 'centroOperacion',
        'cod_centro_de_costo' => 'centroDeCosto',
        'cod_proyecto' => 'proyecto',
    ];

    /**
     * Las que tienen su gemela (misma columna) en la cabecera: el formulario
     * prellena el item con lo elegido en la cabecera si el item no tiene valor.
     */
    public const HEREDADAS_DE_LA_CABECERA = [
        'cod_centro_operacion',
        'cod_centro_de_costo',
        'cod_proyecto',
    ];

    /**
     * Largo maximo admitido de cada columna. Desde la migracion
     * 2026_09_27_100000 cada columna de codigo tiene el MISMO largo que la
     * clave de su maestra (hay FK), asi que todo codigo de la maestra cabe y
     * las listas no se recortan. Lo usan la validacion, el maxlength de los
     * cuadros y la segunda barrera del servicio. SolicitudFkMaestrasErpTest
     * exige que coincida con la base.
     *
     * @var array<string, int>
     */
    public const LARGO_MAXIMO = [
        'cod_motivo' => 5,            // tbl_motivo.col_cod_motivo varchar(5)
        'cod_bodega' => 10,           // tbl_bodega.col_cod_bod varchar(10)
        'cod_unidad_medida' => 5,     // tbl_unidad_medida.col_cod_und_med varchar(5)
        'cod_centro_operacion' => 10, // tbl_centro_operacion varchar(10)
        'cod_centro_de_costo' => 10,  // tbl_centro_de_costo varchar(10)
        'cod_proyecto' => 10,         // tbl_proyecto varchar(10)
        'notas_item' => 500,
        'descripcion_item' => 500,
    ];

    /**
     * Unidad de negocio de todo item aprobado. El formulario la muestra en
     * solo lectura, pero la barrera es el servidor: la aprobacion la impone
     * siempre, llegue lo que llegue en la peticion. Decision del usuario: fija
     * por ahora, sin tabla maestra ni FK.
     */
    public const UNIDAD_NEGOCIO_POR_DEFECTO = '001';

    /** cantidad es decimal(12,3): 9 enteros y 3 decimales. */
    public const CANTIDAD_DECIMALES = 3;

    /** Forma admitida de la cantidad (ya con punto decimal): cabe en decimal(12,3), 9 enteros y 3 decimales. */
    public const PATRON_CANTIDAD = '/^\d{1,9}(\.\d{1,3})?$/';

    protected $table = 'tbl_solicitud_detalle';

    protected $fillable = [
        'solicitud_id',
        'repuesto_id',
        'codigo',
        'nombre',
        'foto',
        'cantidad_solicitada',
        'cantidad_entregada',
        'nota',
    ];

    /**
     * cod_bodega, cod_motivo, cantidad, cod_unidad_medida, cod_unidad_negocio,
     * cod_centro_operacion, cod_centro_de_costo, cod_proyecto, notas_item y
     * descripcion_item (migracion 2026_09_26_100600, REFERENCIAS_APROBACION)
     * NO son fillable: las escribe unicamente
     * AprobacionSolicitudService::aprobar(), dentro de la relectura con
     * bloqueo de la solicitud. `cantidad` es la cantidad que pide quien hace
     * la requisicion (se prellena con cantidad_solicitada y el aprobador la
     * ajusta); cantidad_solicitada y cantidad_entregada siguen siendo las del
     * flujo (carrito, marcarListo). `notas_item` no es `nota`.
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'cantidad_solicitada' => 'integer',
            'cantidad_entregada' => 'integer',
        ];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(Solicitud::class);
    }

    public function repuesto(): BelongsTo
    {
        return $this->belongsTo(Repuesto::class);
    }

    /*
     * Maestras del ERP de las columnas de referencia del item. La base tiene
     * FK fk_tbl_solicitud_detalle_<columna> hacia cada una (migracion
     * 2026_09_27_100000); la relacion sirve para mostrar "codigo - nombre".
     * cod_unidad_negocio no tiene maestra: es siempre '001'.
     */
    public function motivo(): BelongsTo
    {
        return $this->belongsTo(Motivo::class, 'cod_motivo', 'col_cod_motivo');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'cod_bodega', 'col_cod_bod');
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'cod_unidad_medida', 'col_cod_und_med');
    }

    public function centroOperacion(): BelongsTo
    {
        return $this->belongsTo(CentroOperacion::class, 'cod_centro_operacion', 'col_cod_centro_operacion');
    }

    public function centroDeCosto(): BelongsTo
    {
        return $this->belongsTo(CentroDeCosto::class, 'cod_centro_de_costo', 'col_cod_centro_de_costo');
    }

    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class, 'cod_proyecto', 'col_cod_proyecto');
    }

    /** Relaciones a precargar para las vistas de solo lectura (evita N+1). */
    public static function relacionesDeReferencia(string $prefijo = ''): array
    {
        return array_map(fn ($relacion) => $prefijo.$relacion, array_values(self::REFERENCIAS_DE_MAESTRA));
    }

    /**
     * Cantidad para mostrar: sin ceros decimales de sobra y con coma
     * ("12.500" -> "12,5", "3.000" -> "3").
     */
    public static function formatearCantidad(string|int|float|null $cantidad): string
    {
        if ($cantidad === null || $cantidad === '') {
            return '';
        }

        $texto = number_format((float) $cantidad, self::CANTIDAD_DECIMALES, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }

    /**
     * Referencias del item diligenciadas, para las vistas de solo lectura, en
     * el orden de REFERENCIAS_APROBACION. Las vacias no se devuelven; las de
     * maestra van "codigo - nombre" (solo el codigo si la fila ya no existe).
     *
     * @return list<array{columna: string, etiqueta: string, valor: string}>
     */
    public function referenciasParaMostrar(): array
    {
        $filas = [];

        foreach (self::REFERENCIAS_APROBACION as $columna => $etiqueta) {
            $valor = $this->getAttribute($columna);

            if ($valor === null || trim((string) $valor) === '') {
                continue;
            }

            $relacion = self::REFERENCIAS_DE_MAESTRA[$columna] ?? null;

            $filas[] = [
                'columna' => $columna,
                'etiqueta' => $etiqueta,
                'valor' => match (true) {
                    $relacion !== null => $this->{$relacion}?->texto_opcion ?? (string) $valor,
                    $columna === 'cantidad' => self::formatearCantidad($valor),
                    default => (string) $valor,
                },
            ];
        }

        return $filas;
    }

    /**
     * Foto congelada al momento del pedido; cae en la generica si ya no existe.
     */
    public function getFotoUrlAttribute(): string
    {
        if ($this->foto && is_file(public_path('img/'.$this->foto))) {
            return asset('img/'.$this->foto);
        }

        return asset('assets/img/sin-foto.svg');
    }
}
