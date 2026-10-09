<?php

namespace App\Http\Requests;

use App\Models\SolicitudItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aprobacion de una solicitud desde el portal del solicitante, con las
 * columnas de referencia que se diligencian en el encabezado.
 *
 * Todas son opcionales. Esta validacion es la cara amable: la pertenencia, el
 * estado por_aprobar y que cada codigo siga activo en su maestra los vuelve a
 * comprobar AprobacionSolicitudService dentro de la transaccion.
 *
 * El formulario envia `cod_oc` en minusculas; la columna es `cod_OC` y la
 * traduccion ocurre solo en referencias().
 *
 * Por item (tabla REPUESTOS) llegan items[{id del detalle}][campo]; items()
 * entrega lo validado. cod_unidad_negocio no se acepta: la impone el servicio.
 */
class AprobarSolicitudRequest extends FormRequest
{
    /** Tope de dias_entrega: diez anios. Es un plazo, no un contador libre. */
    public const DIAS_ENTREGA_MAXIMO = 3650;

    /** Campo del formulario => columna de tbl_solicitudes. */
    private const CAMPOS = [
        'cod_referencia' => 'cod_referencia',
        'cod_oc' => 'cod_OC',
        'dias_entrega' => 'dias_entrega',
        'cod_centro_operacion' => 'cod_centro_operacion',
        'cod_centro_de_costo' => 'cod_centro_de_costo',
        'cod_proyecto' => 'cod_proyecto',
    ];

    /** Tope de renglones por peticion: una solicitud real no se acerca. */
    public const ITEMS_MAXIMO = 500;

    /**
     * Campos por item que llegan del formulario (items[{id del detalle}][...]).
     * cod_unidad_negocio NO esta: la impone el servicio y lo que llegue se
     * ignora.
     */
    private const CAMPOS_ITEM = [
        'cod_motivo',
        'cod_bodega',
        'cod_unidad_medida',
        'cod_centro_operacion',
        'cod_centro_de_costo',
        'cod_proyecto',
        'cantidad',
        'notas_item',
        'descripcion_item',
    ];

    public function authorize(): bool
    {
        // Lo exige el middleware auth:solicitante de la ruta; la pertenencia
        // la decide el servicio (404 si la solicitud es ajena).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Largos = los de las columnas varchar(50).
            'cod_referencia' => ['nullable', 'string', 'max:50'],
            'cod_oc' => ['nullable', 'string', 'max:50'],
            'dias_entrega' => ['nullable', 'integer', 'min:0', 'max:'.self::DIAS_ENTREGA_MAXIMO],
            // max:10 = la clave varchar(10) de cada maestra.
            'cod_centro_operacion' => [
                'nullable', 'string', 'max:10',
                Rule::exists('tbl_centro_operacion', 'col_cod_centro_operacion')->where('col_activo', 1),
            ],
            'cod_centro_de_costo' => [
                'nullable', 'string', 'max:10',
                Rule::exists('tbl_centro_de_costo', 'col_cod_centro_de_costo')->where('col_activo', 1),
            ],
            'cod_proyecto' => [
                'nullable', 'string', 'max:10',
                Rule::exists('tbl_proyecto', 'col_cod_proyecto')->where('col_activo', 1),
            ],
        ] + $this->reglasDeItems();
    }

    /**
     * Reglas de items[{id}][...]. Los codigos se validan contra su maestra
     * activa con el largo de SolicitudItem::LARGO_MAXIMO (el de la clave de la
     * maestra, igual al de la columna). Que el id pertenezca a la
     * solicitud lo decide el servicio, que ignora cualquier otro.
     *
     * @return array<string, mixed>
     */
    private function reglasDeItems(): array
    {
        $reglas = [
            'items' => ['nullable', 'array', 'max:'.self::ITEMS_MAXIMO],
            'items.*' => ['array'],
        ];

        foreach (SolicitudItem::REFERENCIAS_DE_MAESTRA as $columna => $relacion) {
            $maestra = (new SolicitudItem)->{$relacion}()->getRelated();

            $reglas["items.*.{$columna}"] = [
                'nullable', 'string', 'max:'.SolicitudItem::LARGO_MAXIMO[$columna],
                Rule::exists($maestra->getTable(), $maestra->getKeyName())->where('col_activo', 1),
            ];
        }

        // Cabe en decimal(12,3). La coma ya se cambio por punto al preparar.
        $reglas['items.*.cantidad'] = [
            'bail', 'nullable', 'numeric', 'regex:'.SolicitudItem::PATRON_CANTIDAD, 'gt:0',
        ];
        $reglas['items.*.notas_item'] = ['nullable', 'string', 'max:'.SolicitudItem::LARGO_MAXIMO['notas_item']];
        $reglas['items.*.descripcion_item'] = ['nullable', 'string', 'max:'.SolicitudItem::LARGO_MAXIMO['descripcion_item']];

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = [
            'cod_referencia' => 'codigo de referencia',
            'cod_oc' => 'codigo OC',
            'dias_entrega' => 'dias de entrega',
            'cod_centro_operacion' => 'centro de operacion',
            'cod_centro_de_costo' => 'centro de costo',
            'cod_proyecto' => 'proyecto',
            'items' => 'items',
        ];

        // "bodega del item 2": la posicion del renglon en el formulario, que
        // pinta los items en orden. No se consulta el repuesto: el id podria
        // ser de otra solicitud y el mensaje no debe revelar nada de ella.
        $items = $this->input('items');
        $posicion = 0;

        foreach (is_array($items) ? array_keys($items) : [] as $id) {
            $posicion++;

            foreach (SolicitudItem::REFERENCIAS_APROBACION as $columna => $etiqueta) {
                $atributos["items.{$id}.{$columna}"] = mb_strtolower(rtrim($etiqueta, '.'))." del item {$posicion}";
            }
        }

        return $atributos;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dias_entrega.integer' => 'Los dias de entrega deben ser un numero entero, sin decimales ni letras.',
            'cod_centro_operacion.exists' => 'El centro de operacion elegido no esta disponible. Elijalo de nuevo de la lista.',
            'cod_centro_de_costo.exists' => 'El centro de costo elegido no esta disponible. Elijalo de nuevo de la lista.',
            'cod_proyecto.exists' => 'El proyecto elegido no esta disponible. Elijalo de nuevo de la lista.',
            'items.*.array' => 'Los datos de un item llegaron con un formato invalido.',
            'items.*.*.exists' => 'La opcion elegida en :attribute no esta disponible. Elijala de nuevo de la lista.',
            'items.*.cantidad.numeric' => 'La :attribute debe ser un numero mayor que cero, con hasta '.SolicitudItem::CANTIDAD_DECIMALES.' decimales.',
            'items.*.cantidad.regex' => 'La :attribute debe ser un numero mayor que cero, con hasta '.SolicitudItem::CANTIDAD_DECIMALES.' decimales.',
            'items.*.cantidad.gt' => 'La :attribute debe ser mayor que cero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Recorta y deja en null lo vacio. Solo textos: un arreglo o cualquier
        // otro tipo se deja pasar tal cual para que la regla 'string' lo rechace.
        $limpios = [];

        foreach (array_keys(self::CAMPOS) as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $valor = trim($valor);

                // "07" es 7 para una persona, pero la regla integer
                // (FILTER_VALIDATE_INT) lo rechaza por el cero a la izquierda.
                if ($campo === 'dias_entrega' && $valor !== '' && ctype_digit($valor)) {
                    $valor = ltrim($valor, '0') ?: '0';
                }

                $limpios[$campo] = $valor === '' ? null : $valor;
            }
        }

        $items = $this->input('items');

        if (is_array($items)) {
            $limpios['items'] = array_map(
                fn ($campos) => is_array($campos) ? $this->limpiarItem($campos) : $campos,
                $items
            );
        }

        $this->merge($limpios);
    }

    /**
     * Mismo criterio que la cabecera: recorta y deja en null lo vacio, y solo
     * toca textos. La cantidad admite coma decimal ("2,5" -> "2.5"); con
     * separador de miles ("1.234,5") queda con dos puntos y la regla la
     * rechaza, en vez de adivinar.
     *
     * @param  array<mixed>  $campos
     * @return array<mixed>
     */
    private function limpiarItem(array $campos): array
    {
        foreach (self::CAMPOS_ITEM as $campo) {
            $valor = $campos[$campo] ?? null;

            if (! is_string($valor)) {
                continue;
            }

            $valor = trim($valor);

            if ($campo === 'cantidad') {
                $valor = str_replace(',', '.', $valor);
            }

            $campos[$campo] = $valor === '' ? null : $valor;
        }

        return $campos;
    }

    /**
     * Valores validados por item: id del detalle => columna => valor, solo con
     * los campos de CAMPOS_ITEM (cod_unidad_negocio nunca). Un id que no sea
     * un entero positivo se descarta; que pertenezca a la solicitud lo decide
     * el servicio.
     *
     * @return array<int, array<string, string|null>>
     */
    public function items(): array
    {
        $validados = $this->validated('items') ?? [];
        $items = [];

        foreach ($validados as $id => $campos) {
            if (! ctype_digit((string) $id) || (int) $id <= 0 || ! is_array($campos)) {
                continue;
            }

            foreach (self::CAMPOS_ITEM as $campo) {
                $items[(int) $id][$campo] = $campos[$campo] ?? null;
            }
        }

        return $items;
    }

    /**
     * Valores validados con el nombre REAL de la columna (cod_OC), listos
     * para el servicio. dias_entrega va como entero o null.
     *
     * @return array<string, string|int|null>
     */
    public function referencias(): array
    {
        $validados = $this->validated();
        $referencias = [];

        foreach (self::CAMPOS as $campo => $columna) {
            $valor = $validados[$campo] ?? null;
            $referencias[$columna] = $campo === 'dias_entrega' && $valor !== null ? (int) $valor : $valor;
        }

        return $referencias;
    }
}
