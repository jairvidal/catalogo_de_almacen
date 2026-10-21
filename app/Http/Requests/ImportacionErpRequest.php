<?php

namespace App\Http\Requests;

use App\Models\Funcionalidad;
use App\Services\DiagnosticoJson;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Seccion "Importacion ERP" de /admin/parametros (V 1.4.33): los dos
 * parametros de Parametro::IMPORTACION_ERP.
 *
 * Los dos son opcionales y pueden quedar vacios. Los extremos SI se recortan
 * (los campos se llaman url_ws y estructura_json, no col_valor, asi que el
 * middleware TrimStrings los alcanza y ConvertEmptyStringsToNull deja el vacio
 * en null): un espacio al principio o al final no es significativo ni en una
 * URL ni en un JSON, y en la URL haria fallar la regla. El contenido interno se
 * guarda literal: el JSON no se reformatea ni se vuelve a codificar.
 */
class ImportacionErpRequest extends FormRequest
{
    /** Largo maximo de la URL: el habitual que aceptan navegadores y servidores. */
    public const URL_LARGO_MAXIMO = 2048;

    /**
     * Tope de la estructura JSON. La columna es nvarchar(max); el tope es
     * contra un envio desmedido, no contra la columna.
     */
    public const JSON_LARGO_MAXIMO = 100000;

    public function authorize(): bool
    {
        // La ruta ya exige permiso:parametros,editar; esta es la segunda barrera.
        return $this->user()?->puede(Funcionalidad::PARAMETROS, Funcionalidad::ACCION_EDITAR) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url_ws' => ['nullable', 'string', 'max:'.self::URL_LARGO_MAXIMO, 'url:http,https'],
            // Solo la sintaxis (json_decode sin error): un objeto, un arreglo o
            // incluso un escalar valido pasan. La forma de la estructura no se
            // valida porque no esta definida. No se usa la regla 'json' porque
            // no dice donde esta el error, y en una estructura larga pegada
            // desde una documentacion eso es lo unico que sirve.
            'estructura_json' => ['nullable', 'string', 'max:'.self::JSON_LARGO_MAXIMO,
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    if (is_string($valor) && ($problema = DiagnosticoJson::problema($valor)) !== null) {
                        $fallar('La Estructura JSON no tiene una sintaxis JSON valida: '.$problema.'.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'url_ws' => 'URL WS',
            'estructura_json' => 'Estructura JSON',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url_ws.url' => 'La URL WS debe ser una direccion completa que empiece por http:// o https://.',
        ];
    }

    /** URL validada; vacio = sin configurar. */
    public function url(): string
    {
        return (string) $this->validated('url_ws');
    }

    /** Estructura JSON validada, tal como se escribio; vacio = sin configurar. */
    public function estructuraJson(): string
    {
        return (string) $this->validated('estructura_json');
    }
}
