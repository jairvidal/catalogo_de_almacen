<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use App\Models\Funcionalidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Crear y modificar es la accion editar de la matriz Funciones por
        // perfil; la ruta ya lo exige y esta es la segunda barrera.
        return $this->user()?->puede(Funcionalidad::CATEGORIAS, Funcionalidad::ACCION_EDITAR) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoriaId = $this->categoriaEnEdicion()?->id;

        return [
            // El nombre es unico para que dos categorias no se confundan en el
            // filtro del catalogo publico.
            'col_nombre' => [
                'required', 'string', 'max:60',
                Rule::unique('tbl_categoria', 'col_nombre')->ignore($categoriaId),
            ],
            'col_color_hex' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'col_descripcion' => ['nullable', 'string', 'max:255'],
            'col_activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'col_nombre' => 'nombre',
            'col_color_hex' => 'color',
            'col_descripcion' => 'descripcion',
            'col_activo' => 'estado activo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'col_color_hex.regex' => 'El color debe ir en formato hexadecimal de seis digitos, por ejemplo #D81818.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'col_nombre' => trim((string) $this->input('col_nombre')),
            'col_color_hex' => strtoupper(trim((string) $this->input('col_color_hex'))),
            'col_activo' => $this->boolean('col_activo'),
        ]);
    }

    private function categoriaEnEdicion(): ?Categoria
    {
        $categoria = $this->route('categoria');

        return $categoria instanceof Categoria ? $categoria : null;
    }
}
