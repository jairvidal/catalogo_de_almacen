<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        // users.rol (texto) y users.rol_id conviven: se escriben los dos.
        $roles = Rol::whereIn('col_clave', [User::ROL_ADMIN, User::ROL_ALMACENISTA])
            ->pluck('id', 'col_clave');

        User::updateOrCreate(
            ['email' => 'admin@sidocsa.com'],
            [
                'name' => 'Administrador de Almacen',
                'password' => Hash::make('Almacen2026*'),
                'rol' => User::ROL_ADMIN,
                'rol_id' => $roles->get(User::ROL_ADMIN),
                'activo' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'almacenista@sidocsa.com'],
            [
                'name' => 'Almacenista',
                'password' => Hash::make('Almacen2026*'),
                'rol' => User::ROL_ALMACENISTA,
                'rol_id' => $roles->get(User::ROL_ALMACENISTA),
                'activo' => true,
            ]
        );

        $this->command?->info('Usuarios internos listos (admin@sidocsa.com / almacenista@sidocsa.com).');
    }
}
