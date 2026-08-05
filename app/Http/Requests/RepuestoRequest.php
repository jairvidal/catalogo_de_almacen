<?php

namespace App\Http\Requests;

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
            'codigo' => [
                'required', 'string', 'max:40',
                Rule::unique('repuestos', 'codigo')->ignore($repuestoId),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'ubicacion' => ['nullable', 'string', 'max:100'],
            'unidad_medida' => ['required', 'string', 'max:20'],
            'cantidad_disponible' => ['required', 'integer', 'min:0', 'max:999999'],
            'stock_minimo' => ['required', 'integer', 'min:0', 'max:999999'],
            'activo' => ['nullable', 'boolean'],
            'imagen' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cantidad_disponible' => 'cantidad disponible',
            'stock_minimo' => 'stock minimo',
            'unidad_medida' => 'unidad de medida',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => trim((string) $this->input('codigo')),
            'activo' => $this->boolean('activo'),
        ]);
    }
}
