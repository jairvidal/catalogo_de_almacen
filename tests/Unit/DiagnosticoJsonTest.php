<?php

namespace Tests\Unit;

use App\Services\DiagnosticoJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiagnosticoJsonTest extends TestCase
{
    public function test_un_json_valido_no_tiene_problema(): void
    {
        foreach (['{}', '[]', '"x"', '12', '[12,5]', "{\n  \"a\": [1, 2.5, -3e2, true, null],\n  \"b\": \"ñandú \\\"x\\\" \\u00e1\"\n}"] as $json) {
            $this->assertNull(DiagnosticoJson::problema($json), $json);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casos(): array
    {
        return [
            'cero a la izquierda' => ["{\n  \"cia\": 001\n}", 'linea 2, columna 10: numero con cero a la izquierda'],
            'coma final' => ["{\n  \"a\": 1,\n}", 'linea 2, columna 9: sobra la coma antes de "}"'],
            'coma final en arreglo' => ['[1, 2,]', 'linea 1, columna 6: sobra la coma antes de "]"'],
            'nombre sin comillas' => ['{a: 1}', 'linea 1, columna 2: se esperaba el nombre de una propiedad'],
            'comillas simples' => ["{'a': 1}", 'comillas simples'],
            'comillas tipograficas' => ["{\u{201C}a\u{201D}: 1}", 'comillas tipograficas'],
            'nbsp' => ["{\n\u{00A0}\"a\": 1}", 'linea 2, columna 1: se esperaba el nombre de una propiedad entre comillas dobles (hay un espacio invisible'],
            'bom' => ["\u{FEFF}{}", 'marca BOM'],
            'comentario' => ["{\n  // nota\n  \"a\": 1\n}", 'linea 2, columna 3: se esperaba el nombre de una propiedad entre comillas dobles (hay un comentario'],
            'falta coma' => ["{\"a\": 1\n \"b\": 2}", 'linea 2, columna 2: falta una coma o el cierre "}"'],
            'sin cerrar' => ['{"a": 1', 'falta una coma o el cierre "}" (el texto se acabo)'],
            'comilla sin cerrar' => ['{"a": "hola}', 'linea 1, columna 7: esta comilla nunca se cierra'],
            'mayusculas' => ['{"a": True}', 'valor no valido'],
            'texto sobrante' => ['{} x', 'sobra texto despues del final'],
        ];
    }

    #[DataProvider('casos')]
    public function test_ubica_y_explica_el_error(string $json, string $esperado): void
    {
        $this->assertStringContainsString($esperado, (string) DiagnosticoJson::problema($json));
    }
}
