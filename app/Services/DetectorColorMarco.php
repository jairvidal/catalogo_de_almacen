<?php

namespace App\Services;

/**
 * Deduce la categoria de un repuesto a partir del color del marco impreso en el
 * borde de su foto. Los nombres de los repuestos no correlacionan con el color:
 * el marco es el unico dato de categoria que traen las 489 imagenes del catalogo.
 *
 * Umbrales calibrados contra las 489 fotos de public/img. Cada grupo esta
 * dominado por un solo hex, asi que no hace falta agrupar por cercania:
 *   rojo #D81818 | celeste #00A8F0 y #00A8D8 | morado #603090 | negro #000000
 *   verde #00A848 | durazno #F0C0A8 | azul #000090, #0030A8, #183090, #1830A8
 *
 * Azul marino y celeste son categorias distintas del almacen y no se fusionan:
 * los separa el corte de matiz en 200 grados.
 */
class DetectorColorMarco
{
    /** Claves de color que corresponden a una categoria del almacen. */
    public const SLUGS_CONOCIDOS = [
        'rojo', 'celeste', 'morado', 'negro', 'verde', 'durazno', 'azul',
    ];

    /** Ancho de la banda exterior que se muestrea, como fraccion del lado menor. */
    private const BANDA = 0.08;

    /**
     * Umbral de casi-blanco. El margen de papel alrededor del marco entra en la
     * banda y hay que descartarlo o gana el conteo en todas las fotos.
     */
    private const CASI_BLANCO = 235;

    /** Tamano del bloque de cuantizacion: absorbe el ruido de compresion JPEG. */
    private const BLOQUE = 24;

    /** Por debajo de esta saturacion el color es acromatico y el matiz no aplica. */
    private const SATURACION_MINIMA = 0.20;

    /**
     * Luminosidad desde la cual un matiz naranja (0-45 grados) se lee como el
     * durazno claro #F0C0A8 y no como un naranja saturado.
     */
    private const LUMINOSIDAD_DURAZNO = 180.0;

    /** Un color muy oscuro es negro aunque su matiz haya salido saturado. */
    private const LUMINOSIDAD_NEGRO = 45.0;

    public function disponible(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Analiza la imagen y devuelve el color dominante de su marco.
     *
     * @param  string  $ruta  Ruta absoluta del archivo.
     * @return MarcoDetectado|null null si el archivo no existe, no se pudo leer
     *                             o el marco quedo sin un solo pixel util.
     */
    public function detectar(string $ruta): ?MarcoDetectado
    {
        $imagen = $this->abrir($ruta);

        if ($imagen === null) {
            return null;
        }

        try {
            return $this->analizar($imagen);
        } finally {
            imagedestroy($imagen);
        }
    }

    private function abrir(string $ruta): ?\GdImage
    {
        if (! is_file($ruta)) {
            return null;
        }

        $imagen = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($ruta),
            'png' => @imagecreatefrompng($ruta),
            'webp' => @imagecreatefromwebp($ruta),
            'gif' => @imagecreatefromgif($ruta),
            default => false,
        };

        if ($imagen === false) {
            return null;
        }

        // En una imagen con paleta imagecolorat devuelve el indice, no el RGB.
        if (! imageistruecolor($imagen)) {
            imagepalettetotruecolor($imagen);
        }

        return $imagen;
    }

    private function analizar(\GdImage $imagen): ?MarcoDetectado
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = min($ancho, $alto);

        $banda = max(2, (int) round($lado * self::BANDA));
        // Se muestrea a lo sumo ~200 puntos por lado: el color del marco es
        // plano, leer cada pixel de una foto grande solo cuesta tiempo.
        $paso = max(1, (int) round($lado / 200));

        $conteo = [];
        $total = 0;

        for ($y = 0; $y < $alto; $y += $paso) {
            for ($x = 0; $x < $ancho; $x += $paso) {
                $enBanda = $x < $banda || $x >= $ancho - $banda
                    || $y < $banda || $y >= $alto - $banda;

                if (! $enBanda) {
                    continue;
                }

                $rgb = imagecolorat($imagen, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r > self::CASI_BLANCO && $g > self::CASI_BLANCO && $b > self::CASI_BLANCO) {
                    continue;
                }

                $clave = intdiv($r, self::BLOQUE).','
                    .intdiv($g, self::BLOQUE).','
                    .intdiv($b, self::BLOQUE);

                $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
                $total++;
            }
        }

        if ($total === 0) {
            return null;
        }

        arsort($conteo);
        $dominante = array_key_first($conteo);
        $porcentaje = round($conteo[$dominante] / $total * 100, 1);

        [$r, $g, $b] = array_map(
            fn (string $bloque) => (int) $bloque * self::BLOQUE,
            explode(',', $dominante)
        );

        return $this->clasificar($r, $g, $b, $porcentaje);
    }

    private function clasificar(int $r, int $g, int $b, float $porcentaje): MarcoDetectado
    {
        $hex = sprintf('#%02X%02X%02X', $r, $g, $b);

        $maximo = max($r, $g, $b);
        $minimo = min($r, $g, $b);
        $luminosidad = ($maximo + $minimo) / 2;
        $saturacion = $maximo === 0 ? 0.0 : ($maximo - $minimo) / $maximo;

        if ($saturacion < self::SATURACION_MINIMA) {
            $slug = match (true) {
                $luminosidad < 70 => 'negro',
                $luminosidad < 180 => 'gris',
                default => 'blanco',
            };

            return new MarcoDetectado($slug, $hex, $porcentaje, null, $luminosidad);
        }

        $matiz = $this->matiz($r, $g, $b);

        $slug = match (true) {
            $matiz < 15 || $matiz >= 340 => 'rojo',
            // El durazno #F0C0A8 cae en matiz 20, igual que un naranja fuerte;
            // lo que los separa es que el durazno es claro (luminosidad ~204).
            $matiz < 45 => $luminosidad >= self::LUMINOSIDAD_DURAZNO ? 'durazno' : 'naranja',
            $matiz < 70 => 'amarillo',
            $matiz < 165 => 'verde',
            $matiz < 200 => 'celeste',
            $matiz < 260 => 'azul',
            $matiz < 290 => 'morado',
            default => 'rosa',
        };

        // Un marco casi negro puede dar un matiz saturado por el ruido del JPEG.
        if ($luminosidad < self::LUMINOSIDAD_NEGRO) {
            $slug = 'negro';
        }

        return new MarcoDetectado($slug, $hex, $porcentaje, $matiz, $luminosidad);
    }

    /**
     * Matiz en grados (0-360) del modelo HSV.
     */
    private function matiz(int $r, int $g, int $b): float
    {
        $rn = $r / 255;
        $gn = $g / 255;
        $bn = $b / 255;

        $maximo = max($rn, $gn, $bn);
        $minimo = min($rn, $gn, $bn);
        $delta = $maximo - $minimo;

        if ($delta === 0.0) {
            return 0.0;
        }

        $matiz = match ($maximo) {
            $rn => 60 * fmod(($gn - $bn) / $delta, 6),
            $gn => 60 * ((($bn - $rn) / $delta) + 2),
            default => 60 * ((($rn - $gn) / $delta) + 4),
        };

        return $matiz < 0 ? $matiz + 360 : $matiz;
    }
}
