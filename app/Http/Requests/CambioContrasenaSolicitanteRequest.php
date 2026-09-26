<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cambio de contrasena desde la pagina de ingreso del portal: correo +
 * contrasena actual + nueva + confirmacion.
 *
 * Reglas de la nueva: 10 caracteres minimo, mayusculas y minusculas y al menos
 * un numero, distinta de la actual. No se usa uncompromised(): consulta un
 * servicio externo y el servidor corre en la red interna.
 */
class CambioContrasenaSolicitanteRequest extends FormRequest
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
            'contrasena_actual' => ['required', 'string', 'max:255'],
            'contrasena' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                'different:contrasena_actual',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'correo' => 'correo',
            'contrasena_actual' => 'contrasena actual',
            'contrasena' => 'contrasena nueva',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contrasena.different' => 'La contrasena nueva tiene que ser distinta de la actual.',
            'contrasena.confirmed' => 'La confirmacion no coincide con la contrasena nueva.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['correo' => mb_strtolower(trim((string) $this->input('correo')))]);
    }
}
