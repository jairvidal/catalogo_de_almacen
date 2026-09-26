<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ingreso al portal del solicitante: usuario = correo del ERP.
 *
 * Solo forma; si las credenciales valen lo decide AccesoSolicitanteService.
 */
class IngresoSolicitanteRequest extends FormRequest
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
            'correo' => ['required', 'string', 'email', 'max:150'],
            'contrasena' => ['required', 'string', 'max:255'],
            'recordar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'correo' => 'correo',
            'contrasena' => 'contrasena',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['correo' => mb_strtolower(trim((string) $this->input('correo')))]);
    }
}
