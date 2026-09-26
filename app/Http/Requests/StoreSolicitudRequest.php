<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Envio del formulario publico de solicitud.
 *
 * El solicitante ya no digita nombre, cedula, correo ni telefono: los elige
 * de la lista del ERP (tbl_solicitante_erp) y aqui solo llega su id. Los
 * datos se copian en SolicitudService, que ademas vuelve a leer al
 * solicitante dentro de la transaccion (esta validacion es la cara amable; la
 * garantia es esa relectura).
 */
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
            // Texto libre que digita la persona. NO reemplaza al nombre del ERP:
            // se guarda aparte, en solicitudes.nombre_completo.
            'nombre_completo' => ['required', 'string', 'max:150'],
            'solicitante_erp_id' => [
                'required',
                'integer',
                Rule::exists('tbl_solicitante_erp', 'id')->where('col_activo', 1),
            ],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_completo' => 'nombre completo',
            'solicitante_erp_id' => 'solicitante',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'solicitante_erp_id.required' => 'Busque su nombre y seleccionelo de la lista.',
            'solicitante_erp_id.integer' => 'Busque su nombre y seleccionelo de la lista.',
            'solicitante_erp_id.exists' => 'El solicitante elegido no esta disponible. Busquelo de nuevo en la lista.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Recorta y colapsa espacios: "  Juan   Perez " se guarda "Juan Perez".
        $this->merge([
            'nombre_completo' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('nombre_completo'))),
        ]);
    }
}
