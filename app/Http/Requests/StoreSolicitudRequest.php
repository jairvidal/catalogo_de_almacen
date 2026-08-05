<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'solicitante_nombre' => ['required', 'string', 'min:5', 'max:150'],
            'solicitante_cedula' => ['required', 'string', 'min:5', 'max:30', 'regex:/^[0-9.\-]+$/'],
            'solicitante_email' => ['required', 'email:rfc', 'max:150'],
            'solicitante_telefono' => ['nullable', 'string', 'max:30'],
            'solicitante_area' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'solicitante_nombre' => 'nombre completo',
            'solicitante_cedula' => 'cedula',
            'solicitante_email' => 'correo electronico',
            'solicitante_telefono' => 'telefono',
            'solicitante_area' => 'area o dependencia',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'solicitante_cedula.regex' => 'La cedula solo puede contener numeros, puntos o guiones.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'solicitante_nombre' => trim((string) $this->input('solicitante_nombre')),
            'solicitante_cedula' => trim((string) $this->input('solicitante_cedula')),
            'solicitante_email' => strtolower(trim((string) $this->input('solicitante_email'))),
        ]);
    }
}
