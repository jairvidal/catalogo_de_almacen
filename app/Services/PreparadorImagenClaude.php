<?php

namespace App\Services;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reduce una imagen y la deja lista para un bloque `image` de la API de Claude
 * (JPEG en base64).
 *
 * Por que reducir aqui y no dejar que la API lo haga: la API cobra por parches
 * de 28x28 pixeles y recorta solo por encima de su propio maximo (2576 px de
 * lado en los modelos de alta resolucion). Una foto de celular de 4000x3000
 * costaria el tope de ~4.800 tokens; reducida a 1024 px de lado cuesta ~1.400 y
 * alcanza de sobra para reconocer una pieza. Ademas aligera la peticion.
 *
 * Siempre sale JPEG: los PNG del catalogo con transparencia se aplanan sobre
 * blanco, que es el fondo con el que se fotografian las piezas.
 */
class PreparadorImagenClaude
{
    private const CALIDAD_JPEG = 85;

    /** Tipos que se aceptan, por el tipo real que detecta getimagesize(). */
    private const TIPOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG];

    /**
     * @return array{type: string, source: array{type: string, media_type: string, data: string}}
     *
     * @throws InvalidArgumentException si el archivo no es una imagen JPG o PNG legible
     */
    public function bloque(string $ruta, int $ladoMaximo): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => 'image/jpeg',
                'data' => base64_encode($this->jpeg($ruta, $ladoMaximo)),
            ],
        ];
    }

    /**
     * Tokens de imagen que cobraria la API por este archivo una vez reducido:
     * ceil(ancho/28) x ceil(alto/28). Sirve para estimar costos sin enviar nada.
     */
    public function tokensEstimados(string $ruta, int $ladoMaximo): int
    {
        $info = @getimagesize($ruta);

        if ($info === false) {
            return 0;
        }

        [$ancho, $alto] = $this->medidasReducidas((int) $info[0], (int) $info[1], $ladoMaximo);

        return (int) (ceil($ancho / 28) * ceil($alto / 28));
    }

    /**
     * Bytes JPEG de la imagen reducida.
     */
    public function jpeg(string $ruta, int $ladoMaximo): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Falta la extension GD de PHP para preparar las imagenes.');
        }

        $info = @getimagesize($ruta);

        if ($info === false || ! in_array($info[2], self::TIPOS, true)) {
            throw new InvalidArgumentException('El archivo no es una imagen JPG o PNG valida.');
        }

        $original = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($ruta) : @imagecreatefromjpeg($ruta);

        if (! $original instanceof GdImage) {
            throw new InvalidArgumentException('No se pudo leer la imagen.');
        }

        try {
            if ($info[2] === IMAGETYPE_JPEG) {
                $original = $this->enderezar($original, $ruta);
            }

            $ancho = imagesx($original);
            $alto = imagesy($original);
            [$nuevoAncho, $nuevoAlto] = $this->medidasReducidas($ancho, $alto, $ladoMaximo);

            $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
            imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
            imagecopyresampled($lienzo, $original, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

            ob_start();
            imagejpeg($lienzo, null, self::CALIDAD_JPEG);
            $bytes = (string) ob_get_clean();
            imagedestroy($lienzo);

            return $bytes;
        } finally {
            imagedestroy($original);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function medidasReducidas(int $ancho, int $alto, int $ladoMaximo): array
    {
        $ladoMaximo = max(64, $ladoMaximo);
        $mayor = max($ancho, $alto, 1);

        if ($mayor <= $ladoMaximo) {
            return [max(1, $ancho), max(1, $alto)];
        }

        $escala = $ladoMaximo / $mayor;

        return [max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala))];
    }

    /**
     * Las fotos de celular suelen venir acostadas con una marca EXIF que dice
     * como girarlas; GD no la aplica sola. Sin EXIF disponible se deja igual.
     */
    private function enderezar(GdImage $imagen, string $ruta): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data($ruta);
        $angulo = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angulo === 0) {
            return $imagen;
        }

        $girada = imagerotate($imagen, $angulo, 0);

        if (! $girada instanceof GdImage) {
            return $imagen;
        }

        imagedestroy($imagen);

        return $girada;
    }
}
