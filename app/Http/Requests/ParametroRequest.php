<?php

namespace App\Http\Requests;

use App\Models\Parametro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParametroRequest extends FormRequest
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
        $parametroId = $this->parametroEnEdicion()?->id;

        // prepareForValidation ya normalizo el nombre (y se lo reimpuso a los
        // parametros del sistema), asi que aqui ya se sabe si el valor es de
        // lista cerrada.
        $opciones = Parametro::opcionesDe((string) $this->input('col_nombre'));

        return [
            'col_nombre' => [
                'required', 'string', 'max:100',
                // Es la clave con la que el codigo pide el valor
                // (Parametro::valor('api.id_bod')): minusculas, numeros, punto
                // y guion bajo, sin espacios.
                'regex:/^[a-z][a-z0-9_.]*$/',
                Rule::unique('tbl_parametro', 'col_nombre')->ignore($parametroId),
            ],
            // Un parametro de lista cerrada (inv.actualizar) se valida contra
            // sus opciones EN EL SERVIDOR: el radio del formulario es comodidad
            // para el administrador, no la barrera. Cualquier otro parametro
            // admite texto libre y va con 'present' y no 'required', porque un
            // valor de solo espacios es legitimo (ver api.criterio_2) y
            // 'required' lo rechazaria: Laravel le hace trim antes de comparar
            // contra vacio.
            'col_valor' => $opciones === []
                ? ['present', 'string', 'max:255']
                : ['required', 'string', Rule::in(array_keys($opciones))],
            'col_estado' => ['required', Rule::in(array_keys(Parametro::ESTADOS))],
            'col_descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'col_nombre' => 'nombre',
            'col_valor' => 'valor',
            'col_estado' => 'estado',
            'col_descripcion' => 'descripcion',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'col_nombre.regex' => 'El nombre solo admite minusculas, numeros, punto y guion bajo, y debe empezar por letra.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $parametro = $this->parametroEnEdicion();
        $valor = $this->input('col_valor');

        $this->merge([
            'col_nombre' => strtolower(trim((string) $this->input('col_nombre'))),
            // El valor NO se recorta: hay parametros cuyo espacio final viaja a
            // la API y es significativo (ver api.criterio_2 en ParametroSeeder).
            'col_valor' => $valor === null ? '' : (string) $valor,
        ]);

        // Una credencial no viaja al navegador, asi que el formulario la manda
        // vacia mientras no la cambien: vacio significa "deje la que hay".
        if ($parametro?->es_sensible && $this->input('col_valor') === '') {
            $this->merge(['col_valor' => $parametro->col_valor]);
        }

        // Un parametro del sistema conserva su nombre y su estado: el nombre es
        // por el que lo pide el codigo y desactivarlo desde el formulario seria
        // saltarse el bloqueo de ParametroService::anular().
        if ($parametro?->es_del_sistema) {
            $this->merge([
                'col_nombre' => $parametro->col_nombre,
                'col_estado' => Parametro::ESTADO_ACTIVO,
            ]);
        }
    }

    private function parametroEnEdicion(): ?Parametro
    {
        $parametro = $this->route('parametro');

        return $parametro instanceof Parametro ? $parametro : null;
    }
}
