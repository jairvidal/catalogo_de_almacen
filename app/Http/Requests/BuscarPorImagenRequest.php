<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto que sube el catalogo publico para buscar por imagen. Publico: no hay
 * nada que autorizar; el freno de gasto es el limitador busqueda-imagen.
 *
 * `image` + `mimes` validan el tipo REAL del contenido (no solo la extension).
 */
class BuscarPorImagenRequest extends FormRequest
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
            'imagen' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:'.max(1, (int) config('services.anthropic.imagen.max_kb', 10240))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megas = round(max(1, (int) config('services.anthropic.imagen.max_kb', 10240)) / 1024, 1);

        return [
            'imagen.required' => 'Seleccione o tome una foto del repuesto.',
            'imagen.file' => 'No se pudo recibir la imagen. Intente de nuevo.',
            'imagen.uploaded' => 'No se pudo recibir la imagen. Intente de nuevo.',
            'imagen.image' => 'El archivo debe ser una imagen JPG o PNG.',
            'imagen.mimes' => 'El archivo debe ser una imagen JPG o PNG.',
            'imagen.max' => "La imagen no puede pesar mas de {$megas} MB.",
        ];
    }
}
