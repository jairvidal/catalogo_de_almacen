<?php

namespace App\Http\Requests;

use App\Models\Repuesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarCatalogo() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $repuestoId = $this->route('repuesto')?->id;

        return [
            // codigo es entero desde que la tabla la trae el ERP. La unicidad la
            // respalda el indice unico de repuestos.codigo.
            'codigo' => [
                'required', 'integer', 'min:1',
                Rule::unique('repuestos', 'codigo')->ignore($repuestoId),
            ],
            'cod_referencia' => ['nullable', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:300'],
            'ubicacion' => ['nullable', 'string', 'max:30'],
            'unidad_medida' => ['required', 'string', 'max:10'],
            'desc_cat_1' => ['nullable', 'string', 'max:50'],
            'desc_cat_2' => ['nullable', 'string', 'max:50'],
            // Saldo operativo. `stock` no se edita a mano: lo escribe el ERP.
            'existencia' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'stock_minimo' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'estado' => ['required', Rule::in([Repuesto::ESTADO_ACTIVO, Repuesto::ESTADO_INACTIVO])],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cod_referencia' => 'referencia',
            'existencia' => 'existencia',
            'stock_minimo' => 'stock minimo',
            'unidad_medida' => 'unidad de medida',
            'desc_cat_1' => 'grupo',
            'desc_cat_2' => 'subgrupo',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => trim((string) $this->input('codigo')),
            // La casilla del formulario no viaja cuando esta desmarcada, asi que
            // su ausencia significa inactivo.
            'estado' => $this->boolean('estado') ? Repuesto::ESTADO_ACTIVO : Repuesto::ESTADO_INACTIVO,
        ]);
    }
}
