<?php

namespace App\Http\Requests;

use App\Models\Funcionalidad;
use App\Models\Rol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Matriz de un rol que llega de la pantalla Funciones por perfil.
 *
 * Forma esperada: permisos[<id de funcionalidad>][ver|editar|eliminar] = 1.
 * Una casilla desmarcada no viaja, asi que lo ausente se guarda como false.
 */
class PermisoRolRequest extends FormRequest
{
    /**
     * Solo guarda quien puede EDITAR el modulo de permisos. Ver la pantalla no
     * alcanza: la ruta ya lo exige, y esto es la segunda barrera.
     */
    public function authorize(): bool
    {
        return $this->user()?->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_EDITAR) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rol_id' => [
                'required', 'integer',
                Rule::exists('tbl_rol', 'id')->where('col_activo', true),
            ],
            'permisos' => ['nullable', 'array'],
            // array:ver,editar,eliminar rechaza cualquier otra llave dentro.
            'permisos.*' => ['array:'.implode(',', array_keys(Funcionalidad::ACCIONES))],
            'permisos.*.*' => ['boolean'],
        ];
    }

    /**
     * Las llaves de permisos son ids: tienen que ser de funcionalidades
     * existentes y activas. Se valida aqui y no con una regla por campo porque
     * lo que se valida es la llave, no el valor.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $permisos = $this->input('permisos');

                if (! is_array($permisos) || $permisos === []) {
                    return;
                }

                $validas = array_map(
                    fn (object $fila) => (int) $fila->id,
                    DB::select('select id from tbl_funcionalidad where col_activo = 1')
                );

                foreach (array_keys($permisos) as $llave) {
                    if (! ctype_digit((string) $llave) || ! in_array((int) $llave, $validas, true)) {
                        $validator->errors()->add('permisos', 'Una de las funcionalidades enviadas no existe o esta anulada.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rol_id' => 'perfil',
            'permisos' => 'permisos',
            'permisos.*' => 'permisos de la funcionalidad',
            'permisos.*.*' => 'permiso',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rol_id.exists' => 'El perfil no existe o esta anulado.',
            'permisos.*.array' => 'Solo se admiten los permisos ver, editar y eliminar.',
        ];
    }

    /**
     * El rol viaja en la URL (PUT admin/permisos/{rol}); se copia a la entrada
     * para que la regla exists confirme que sigue activo.
     */
    protected function prepareForValidation(): void
    {
        $rol = $this->route('rol');

        $this->merge([
            'rol_id' => $rol instanceof Rol ? $rol->id : null,
        ]);
    }
}
