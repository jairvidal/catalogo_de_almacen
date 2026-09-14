<?php

namespace App\Http\Requests;

use App\Models\Funcionalidad;
use App\Models\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RolRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Crear y modificar es la accion editar de la matriz Funciones por
        // perfil; la ruta ya lo exige y esta es la segunda barrera.
        return $this->user()?->puede(Funcionalidad::ROLES, Funcionalidad::ACCION_EDITAR) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rolId = $this->rolEnEdicion()?->id;

        return [
            'col_clave' => [
                'required', 'string', 'max:20',
                // Se usa como identificador estable y espeja users.rol.
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('tbl_rol', 'col_clave')->ignore($rolId),
            ],
            'col_nombre' => ['required', 'string', 'max:60'],
            'col_descripcion' => ['nullable', 'string', 'max:255'],
            'col_gestiona_catalogo' => ['nullable', 'boolean'],
            'col_activo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'col_clave' => 'clave',
            'col_nombre' => 'nombre',
            'col_descripcion' => 'descripcion',
            'col_gestiona_catalogo' => 'permiso de gestionar el catalogo',
            'col_activo' => 'estado activo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'col_clave.regex' => 'La clave solo admite minusculas, numeros y guion bajo, y debe empezar por letra.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $rol = $this->rolEnEdicion();

        $this->merge([
            'col_clave' => strtolower(trim((string) $this->input('col_clave'))),
            'col_nombre' => trim((string) $this->input('col_nombre')),
            'col_gestiona_catalogo' => $this->boolean('col_gestiona_catalogo'),
            'col_activo' => $this->boolean('col_activo'),
        ]);

        // Un rol del sistema conserva su clave, su permiso y su estado: son los
        // que sostienen el acceso al panel y editarlos deja gente por fuera.
        if ($rol?->es_del_sistema) {
            $this->merge([
                'col_clave' => $rol->col_clave,
                'col_gestiona_catalogo' => $rol->col_gestiona_catalogo,
                'col_activo' => true,
            ]);
        }
    }

    private function rolEnEdicion(): ?Rol
    {
        $rol = $this->route('rol');

        return $rol instanceof Rol ? $rol : null;
    }
}
