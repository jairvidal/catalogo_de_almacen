<?php

namespace App\Http\Requests;

use App\Models\Funcionalidad;
use App\Models\Parametro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParametroRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Crear y modificar es la accion editar de la matriz Funciones por
        // perfil; la ruta ya lo exige y esta es la segunda barrera.
        return $this->user()?->puede(Funcionalidad::PARAMETROS, Funcionalidad::ACCION_EDITAR) ?? false;
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
        $esListaEntera = Parametro::esListaEntera((string) $this->input('col_nombre'));

        return [
            'col_nombre' => [
                'required', 'string', 'max:100',
                // Es la clave con la que el codigo pide el valor
                // (Parametro::valor('api.id_bod')): minusculas, numeros, punto
                // y guion bajo, sin espacios.
                'regex:/^[a-z][a-z0-9_.]*$/',
                Rule::unique('tbl_parametro', 'col_nombre')->ignore($parametroId),
            ],
            // Tres casos, y los tres se deciden EN EL SERVIDOR; lo que hace el
            // formulario (radios, ayuda del campo) es comodidad, no la barrera:
            //  - lista cerrada (inv.actualizar): solo sus opciones.
            //  - lista de IDs numericos (api.criterio, api.criterio_2): digitos
            //    separados por coma, con espacios alrededor permitidos porque
            //    Parametro::listaEnteros() recorta cada elemento. El vacio pasa:
            //    significa "sin filtro".
            //  - cualquier otro: texto libre, con 'present' y no 'required'
            //    porque un valor de solo espacios es legitimo y 'required' lo
            //    rechazaria (Laravel le hace trim antes de comparar contra vacio).
            'col_valor' => match (true) {
                $opciones !== [] => ['required', 'string', Rule::in(array_keys($opciones))],
                $esListaEntera => ['present', 'string', 'max:255', 'regex:/^\s*\d*\s*(,\s*\d*\s*)*$/'],
                default => ['present', 'string', 'max:255'],
            },
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
            'col_valor.regex' => 'Este parametro es una lista de IDs numericos separados por coma (ej: 3038,1230). '
                .'Los nombres de grupo o subgrupo ya no los acepta la API de inventario.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $parametro = $this->parametroEnEdicion();
        $valor = $this->input('col_valor');

        $this->merge([
            'col_nombre' => strtolower(trim((string) $this->input('col_nombre'))),
            // El valor NO se recorta: hay parametros cuyo espacio final es
            // significativo y recortarlo cambiaria la consulta en silencio. Los
            // criterios de la API ya no son de esos (son IDs y listaEnteros()
            // los recorta), pero la regla general se mantiene.
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
