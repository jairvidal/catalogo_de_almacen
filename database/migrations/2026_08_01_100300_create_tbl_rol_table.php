<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_rol', function (Blueprint $table) {
            $table->id();
            // Identificador estable del rol. Es lo que espeja la columna
            // historica users.rol ('admin', 'almacenista').
            $table->string('col_clave', 20)->unique();
            $table->string('col_nombre', 60);
            $table->string('col_descripcion', 255)->nullable();
            // Unico permiso que hoy discrimina el panel: gestionar el catalogo.
            $table->boolean('col_gestiona_catalogo')->default(false);
            // Los roles del sistema no se anulan ni cambian de clave o permiso:
            // son los que sostienen el acceso al panel.
            $table->boolean('col_sistema')->default(false);
            $table->boolean('col_activo')->default(true);
            $table->timestamps();

            $table->index('col_activo');
        });

        // Los dos roles que ya existian como texto en users.rol. Se crean aqui
        // porque la migracion siguiente los necesita para rellenar users.rol_id.
        $ahora = now();

        DB::table('tbl_rol')->insert([
            [
                'col_clave' => 'admin',
                'col_nombre' => 'Administrador',
                'col_descripcion' => 'Despacha solicitudes y gestiona el catalogo, el inventario y los roles.',
                'col_gestiona_catalogo' => true,
                'col_sistema' => true,
                'col_activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'col_clave' => 'almacenista',
                'col_nombre' => 'Almacenista',
                'col_descripcion' => 'Entra al panel y despacha solicitudes; no gestiona el catalogo.',
                'col_gestiona_catalogo' => false,
                'col_sistema' => true,
                'col_activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_rol');
    }
};
