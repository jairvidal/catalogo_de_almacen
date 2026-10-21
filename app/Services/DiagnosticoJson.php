<?php

namespace App\Services;

use UnexpectedValueException;

/**
 * Dice DONDE y POR QUE un texto no es JSON valido.
 *
 * json_decode() solo responde "Syntax error", sin posicion, y con una
 * estructura de cientos de lineas eso no le sirve a quien la pego. Este
 * recorrido sigue la gramatica estricta de JSON (RFC 8259) solo para ubicar el
 * primer error; la decision de si es valido la sigue tomando json_decode(), asi
 * que el recorrido nunca puede aceptar algo que PHP rechace.
 *
 * Nombra los errores tipicos de un ejemplo copiado de una documentacion:
 * ceros a la izquierda (001), comas finales, comentarios, comillas simples o
 * tipograficas y espacios invisibles (NBSP, BOM) que traen Word y los PDF.
 */
class DiagnosticoJson
{
    private string $texto;

    private int $pos = 0;

    private int $largo;

    private function __construct(string $texto)
    {
        $this->texto = $texto;
        $this->largo = strlen($texto);
    }

    /**
     * null si el texto es JSON valido; si no, "linea L, columna C: causa".
     */
    public static function problema(string $texto): ?string
    {
        json_decode($texto);

        if (json_last_error() === JSON_ERROR_NONE) {
            return null;
        }

        $error = json_last_error_msg();

        try {
            (new self($texto))->recorrer();
        } catch (UnexpectedValueException $e) {
            return $e->getMessage();
        }

        // El recorrido no encontro nada que senalar (p. ej. anidamiento mayor
        // que el que admite json_decode): se devuelve lo que dijo PHP.
        return $error;
    }

    private function recorrer(): void
    {
        if (str_starts_with($this->texto, "\xEF\xBB\xBF")) {
            $this->fallar('el texto empieza con una marca BOM invisible; vuelva a pegarlo desde un editor de texto plano');
        }

        $this->valor();
        $this->espacios();

        if ($this->pos < $this->largo) {
            $this->fallar('sobra texto despues del final de la estructura ('.$this->describir().')');
        }
    }

    private function valor(): void
    {
        $this->espacios();

        if ($this->pos >= $this->largo) {
            $this->fallar('el texto termina antes de completar la estructura (falta cerrar una llave, un corchete o un valor)');
        }

        $c = $this->texto[$this->pos];

        match (true) {
            $c === '{' => $this->objeto(),
            $c === '[' => $this->arreglo(),
            $c === '"' => $this->cadena(),
            $c === '-' || ctype_digit($c) => $this->numero(),
            default => $this->literal(),
        };
    }

    private function objeto(): void
    {
        $this->pos++;
        $this->espacios();

        if ($this->siguienteEs('}')) {
            $this->pos++;

            return;
        }

        while (true) {
            $this->espacios();

            if (! $this->siguienteEs('"')) {
                $this->fallar('se esperaba el nombre de una propiedad entre comillas dobles ('.$this->describir().')');
            }

            $this->cadena();
            $this->espacios();

            if (! $this->siguienteEs(':')) {
                $this->fallar('falta ":" despues del nombre de la propiedad ('.$this->describir().')');
            }

            $this->pos++;
            $this->valor();
            $this->espacios();

            if ($this->siguienteEs(',')) {
                $coma = $this->pos++;
                $this->espacios();

                if ($this->siguienteEs('}')) {
                    $this->pos = $coma;
                    $this->fallar('sobra la coma antes de "}" (JSON no admite coma final)');
                }

                continue;
            }

            if ($this->siguienteEs('}')) {
                $this->pos++;

                return;
            }

            $this->fallar('falta una coma o el cierre "}" ('.$this->describir().')');
        }
    }

    private function arreglo(): void
    {
        $this->pos++;
        $this->espacios();

        if ($this->siguienteEs(']')) {
            $this->pos++;

            return;
        }

        while (true) {
            $this->valor();
            $this->espacios();

            if ($this->siguienteEs(',')) {
                $coma = $this->pos++;
                $this->espacios();

                if ($this->siguienteEs(']')) {
                    $this->pos = $coma;
                    $this->fallar('sobra la coma antes de "]" (JSON no admite coma final)');
                }

                continue;
            }

            if ($this->siguienteEs(']')) {
                $this->pos++;

                return;
            }

            $this->fallar('falta una coma o el cierre "]" ('.$this->describir().')');
        }
    }

    private function cadena(): void
    {
        $inicio = $this->pos++;

        while ($this->pos < $this->largo) {
            $c = $this->texto[$this->pos];

            if ($c === '"') {
                $this->pos++;

                return;
            }

            if ($c === '\\') {
                $escape = $this->texto[$this->pos + 1] ?? '';

                if ($escape === 'u') {
                    if (! preg_match('/[0-9a-fA-F]{4}/A', $this->texto, $m, 0, $this->pos + 2)) {
                        $this->fallar('"\\u" debe ir seguido de 4 digitos hexadecimales');
                    }
                    $this->pos += 6;

                    continue;
                }

                if (! str_contains('"\\/bfnrt', $escape) || $escape === '') {
                    $this->fallar('barra invertida "\\" no valida; para escribir una barra use "\\\\"');
                }

                $this->pos += 2;

                continue;
            }

            if (ord($c) < 0x20) {
                $this->fallar('hay un salto de linea o una tabulacion dentro de un texto entre comillas; escribalo como \\n o \\t, o cierre la comilla');
            }

            $this->pos++;
        }

        $this->pos = $inicio;
        $this->fallar('esta comilla nunca se cierra');
    }

    private function numero(): void
    {
        if (preg_match('/-?0[0-9]/A', $this->texto, $m, 0, $this->pos)) {
            $this->fallar('numero con cero a la izquierda; JSON no lo admite: si es un codigo (001) escribalo entre comillas, "001"');
        }

        if (! preg_match('/-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/A', $this->texto, $m, 0, $this->pos)) {
            $this->fallar('numero mal escrito ('.$this->describir().')');
        }

        $this->pos += strlen($m[0]);
    }

    private function literal(): void
    {
        foreach (['true', 'false', 'null'] as $palabra) {
            if (substr_compare($this->texto, $palabra, $this->pos, strlen($palabra)) === 0) {
                $this->pos += strlen($palabra);

                return;
            }
        }

        $this->fallar('valor no valido ('.$this->describir().'); los textos van entre comillas dobles y true, false y null en minusculas');
    }

    /** Solo espacio, tabulacion y saltos de linea: lo demas (NBSP) no es espacio en JSON. */
    private function espacios(): void
    {
        $this->pos += strspn($this->texto, " \t\r\n", $this->pos);
    }

    private function siguienteEs(string $caracter): bool
    {
        return ($this->texto[$this->pos] ?? null) === $caracter;
    }

    /** Nombra el caracter en la posicion actual, con la causa probable si es tipica. */
    private function describir(): string
    {
        if ($this->pos >= $this->largo) {
            return 'el texto se acabo';
        }

        $caracter = mb_substr(substr($this->texto, $this->pos, 4), 0, 1, 'UTF-8');

        return match ($caracter) {
            "\u{00A0}" => 'hay un espacio invisible de no separacion (NBSP), tipico al copiar de Word, un PDF o una pagina web; borrelo y escriba un espacio normal',
            "\u{FEFF}" => 'hay una marca BOM invisible',
            "\u{201C}", "\u{201D}" => 'hay comillas tipograficas “ ”; use comillas dobles rectas "',
            "\u{2018}", "\u{2019}", "'" => 'hay comillas simples; JSON exige comillas dobles "',
            '/' => 'hay un comentario; JSON no admite comentarios',
            default => 'se encontro «'.$caracter.'»',
        };
    }

    private function fallar(string $causa): never
    {
        $antes = substr($this->texto, 0, $this->pos);
        $linea = substr_count($antes, "\n") + 1;
        $inicioLinea = strrpos($antes, "\n");
        $columna = mb_strlen($inicioLinea === false ? $antes : substr($antes, $inicioLinea + 1), 'UTF-8') + 1;

        throw new UnexpectedValueException("linea {$linea}, columna {$columna}: {$causa}");
    }
}
