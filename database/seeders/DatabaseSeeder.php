<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // CategoriaSeeder va antes que RepuestoSeeder para que las categorias ya
        // existan cuando se corra despues `php artisan repuestos:clasificar`.
        // FuncionalidadSeeder va justo despues de los usuarios: necesita los
        // roles y no depende del catalogo.
        $this->call([
            UsuarioSeeder::class,
            FuncionalidadSeeder::class,
            CategoriaSeeder::class,
            RepuestoSeeder::class,
            ParametroSeeder::class,
        ]);
    }
}
