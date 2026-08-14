<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriaSeeder extends Seeder
{
    /**
     * Las once categorias que trae el catalogo, una por color de marco de la
     * foto. Se nombran por su color porque es lo unico que se sabe de cierto;
     * el administrador les pone el nombre real desde /admin/categorias.
     *
     * El slug es la clave estable: lo devuelve DetectorColorMarco y es lo que
     * amarra la foto con la categoria, asi que renombrar no rompe nada.
     *
     * Las siete primeras salieron de la carga inicial de 489 fotos; rosa,
     * fucsia, gris y amarillo aparecieron en la segunda carga de 102.
     *
     * @var list<array{slug: string, nombre: string, hex: string, cantidad: int}>
     */
    private const CATEGORIAS = [
        ['slug' => 'rojo', 'nombre' => 'Rojo', 'hex' => '#D81818', 'cantidad' => 153],
        ['slug' => 'celeste', 'nombre' => 'Celeste', 'hex' => '#00A8F0', 'cantidad' => 102],
        ['slug' => 'morado', 'nombre' => 'Morado', 'hex' => '#603090', 'cantidad' => 83],
        ['slug' => 'negro', 'nombre' => 'Negro', 'hex' => '#000000', 'cantidad' => 56],
        ['slug' => 'verde', 'nombre' => 'Verde', 'hex' => '#00A848', 'cantidad' => 42],
        ['slug' => 'durazno', 'nombre' => 'Durazno', 'hex' => '#F0C0A8', 'cantidad' => 30],
        ['slug' => 'azul', 'nombre' => 'Azul', 'hex' => '#000090', 'cantidad' => 23],
        ['slug' => 'rosa', 'nombre' => 'Rosa', 'hex' => '#D890D8', 'cantidad' => 33],
        ['slug' => 'gris', 'nombre' => 'Gris', 'hex' => '#A8A8A8', 'cantidad' => 13],
        ['slug' => 'fucsia', 'nombre' => 'Fucsia', 'hex' => '#A80078', 'cantidad' => 8],
        ['slug' => 'amarillo', 'nombre' => 'Amarillo', 'hex' => '#F0F000', 'cantidad' => 3],
    ];

    public function run(): void
    {
        $ahora = now();

        $filas = array_map(fn (array $categoria) => [
            'col_nombre' => $categoria['nombre'],
            'col_slug' => $categoria['slug'],
            'col_color_hex' => $categoria['hex'],
            'col_descripcion' => "Repuestos cuya foto lleva el marco {$categoria['hex']}: "
                ."{$categoria['cantidad']} al sembrar la categoria.",
            'col_activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], self::CATEGORIAS);

        // Solo se refresca el color: el nombre y la descripcion son del
        // administrador y volver a correr el seeder no debe deshacer sus cambios.
        Categoria::upsert($filas, ['col_slug'], ['col_color_hex', 'updated_at']);

        $this->command?->info('Categorias sembradas: '.count($filas));
    }
}
