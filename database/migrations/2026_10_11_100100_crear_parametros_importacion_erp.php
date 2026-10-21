<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea los dos parametros de la seccion "Importacion ERP" de /admin/parametros
 * (V 1.4.33) si todavia no existen, para que la seccion funcione sin correr
 * ParametroSeeder. Idempotente: un parametro que ya existe (porque el seeder
 * corrio antes) no se toca, ni su valor ni su descripcion.
 *
 * Los nombres van literales y no con las constantes de Parametro a proposito:
 * una migracion ya ejecutada no puede cambiar de significado si mañana alguien
 * renombra la constante.
 *
 * down() borra las dos filas (con su valor): es el inverso exacto y tiene que
 * correr antes del down() de 2026_10_11_100000, que no puede devolver
 * col_valor a nvarchar(255) mientras haya un JSON largo guardado.
 */
return new class extends Migration
{
    private const PARAMETROS = [
        'erp.importacion_url' => 'URL del servicio web (WS) de importacion desde el ERP. Se edita en la seccion '
            .'"Importacion ERP"; todavia no la usa ningun proceso.',
        'erp.importacion_json' => 'Estructura JSON de la importacion desde el ERP. Se edita en la seccion '
            .'"Importacion ERP"; todavia no la usa ningun proceso.',
    ];

    public function up(): void
    {
        foreach (self::PARAMETROS as $nombre => $descripcion) {
            // INSERT condicionado en una sola sentencia: no hay ventana entre
            // comprobar y escribir. El indice unico de col_nombre es la garantia final.
            DB::insert(
                "insert into tbl_parametro (col_nombre, col_valor, col_estado, col_descripcion, col_sistema, created_at, updated_at)
                 select ?, '', 'activo', ?, 1, getdate(), getdate()
                  where not exists (select 1 from tbl_parametro with (updlock, holdlock) where col_nombre = ?)",
                [$nombre, $descripcion, $nombre]
            );
        }
    }

    public function down(): void
    {
        DB::table('tbl_parametro')->whereIn('col_nombre', array_keys(self::PARAMETROS))->delete();
    }
};
