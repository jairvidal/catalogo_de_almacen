<?php

namespace Tests\Feature;

use App\Services\DetectorColorMarco;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un archivo real de public/img por cada uno de los once colores de marco.
 * Si alguno cambia de clasificacion, `repuestos:clasificar` empieza a mover
 * repuestos de categoria en silencio, asi que estos casos son el candado.
 */
class DetectorColorMarcoTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function fotosPorColor(): array
    {
        return [
            'rojo' => ['0005467_v2.png', 'rojo', '#D81818'],
            'celeste' => ['0017970.jpg', 'celeste', '#00A8F0'],
            'morado' => ['0006727.png', 'morado', '#603090'],
            'negro' => ['0003729.jpg', 'negro', '#000000'],
            'verde' => ['0010964.jpg', 'verde', '#00A848'],
            'durazno' => ['0012801.jpg', 'durazno', '#F0C0A8'],
            'azul' => ['0008159.jpg', 'azul', '#000090'],
            'rosa' => ['0003961.jpg', 'rosa', '#D890D8'],
            'fucsia' => ['0003507.jpg', 'fucsia', '#A80078'],
            'gris' => ['0001220.jpg', 'gris', '#A8A8A8'],
            'amarillo' => ['0017074.jpg', 'amarillo', '#F0F000'],
        ];
    }

    #[DataProvider('fotosPorColor')]
    public function test_detecta_el_color_del_marco(string $archivo, string $slug, string $hex): void
    {
        $ruta = public_path('img/'.$archivo);

        if (! is_file($ruta)) {
            $this->markTestSkipped("No esta la foto de muestra {$archivo} en public/img.");
        }

        $marco = (new DetectorColorMarco)->detectar($ruta);

        $this->assertNotNull($marco, "No se pudo leer el marco de {$archivo}.");
        $this->assertSame($slug, $marco->slug);
        $this->assertSame($hex, $marco->hex);
    }

    public function test_el_azul_marino_y_el_celeste_no_se_confunden(): void
    {
        $detector = new DetectorColorMarco;

        $azul = $detector->detectar(public_path('img/0008159.jpg'));
        $celeste = $detector->detectar(public_path('img/0017970.jpg'));

        $this->assertNotNull($azul);
        $this->assertNotNull($celeste);
        $this->assertNotSame($azul->slug, $celeste->slug);
    }

    /**
     * El rosa claro y el fucsia comparten el tramo de matiz >= 290 y solo los
     * separa la luminosidad. Si el corte se mueve, los 41 repuestos de esos dos
     * marcos se mezclan en una sola categoria.
     */
    public function test_el_rosa_claro_y_el_fucsia_no_se_confunden(): void
    {
        $detector = new DetectorColorMarco;

        $rosa = $detector->detectar(public_path('img/0003961.jpg'));
        $fucsia = $detector->detectar(public_path('img/0003507.jpg'));

        $this->assertNotNull($rosa);
        $this->assertNotNull($fucsia);
        $this->assertNotSame($rosa->slug, $fucsia->slug);
    }

    public function test_devuelve_null_cuando_el_archivo_no_existe(): void
    {
        $this->assertNull((new DetectorColorMarco)->detectar(public_path('img/no-existe-jamas.jpg')));
    }
}
