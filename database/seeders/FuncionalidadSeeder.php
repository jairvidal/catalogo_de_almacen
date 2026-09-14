<?php

namespace Database\Seeders;

use App\Models\Funcionalidad;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Funcionalidades del panel y matriz inicial de Funciones por perfil.
 *
 * Idempotente, y se puede volver a correr cada vez que se agregue una
 * funcionalidad a la lista:
 * - Funcionalidades: MERGE por col_clave que refresca nombre, seccion, icono y
 *   orden (el panel no los edita) y NUNCA col_activo, para no revivir una
 *   funcionalidad que alguien anulo a mano.
 * - Matriz: solo se siembra a los roles que todavia no tienen NINGUNA fila, con
 *   el permiso heredado de col_gestiona_catalogo (lo que ya podian hacer). Un
 *   rol que ya tiene su matriz no se toca: lo que marco el administrador manda,
 *   y una funcionalidad nueva le aparece desmarcada.
 */
class FuncionalidadSeeder extends Seeder
{
    /**
     * Las funcionalidades que existen hoy en el panel. No hay modulo de
     * usuarios: cuando exista, se agrega aqui y en sus rutas.
     *
     * @var list<array{clave: string, nombre: string, seccion: string, icono: string, orden: int}>
     */
    private const FUNCIONALIDADES = [
        ['clave' => Funcionalidad::SOLICITUDES, 'nombre' => 'Solicitudes', 'seccion' => 'Operacion', 'icono' => 'inbox', 'orden' => 10],
        ['clave' => Funcionalidad::REPUESTOS, 'nombre' => 'Catalogo e inventario', 'seccion' => 'Catalogos', 'icono' => 'box-seam', 'orden' => 20],
        ['clave' => Funcionalidad::CATEGORIAS, 'nombre' => 'Categorias', 'seccion' => 'Catalogos', 'icono' => 'tags', 'orden' => 30],
        ['clave' => Funcionalidad::ROLES, 'nombre' => 'Roles', 'seccion' => 'Parametrizacion', 'icono' => 'person-badge', 'orden' => 40],
        ['clave' => Funcionalidad::PERMISOS, 'nombre' => 'Funciones por perfil', 'seccion' => 'Parametrizacion', 'icono' => 'ui-checks-grid', 'orden' => 50],
        ['clave' => Funcionalidad::PARAMETROS, 'nombre' => 'Parametros', 'seccion' => 'Parametrizacion', 'icono' => 'sliders', 'orden' => 60],
    ];

    /** 7 parametros por fila: 250 filas son 1.750, bajo el limite de 2.100. */
    private const LOTE = 250;

    public function run(): void
    {
        DB::transaction(function () {
            $this->sembrarFuncionalidades();
            $roles = $this->sembrarMatrizInicial();

            $this->command?->info('Funcionalidades sembradas: '.count(self::FUNCIONALIDADES)
                .". Roles con matriz inicial nueva: {$roles}.");
        });
    }

    private function sembrarFuncionalidades(): void
    {
        $valores = implode(', ', array_fill(0, count(self::FUNCIONALIDADES), '(?, ?, ?, ?, ?)'));
        $parametros = [];

        foreach (self::FUNCIONALIDADES as $funcionalidad) {
            array_push(
                $parametros,
                $funcionalidad['clave'],
                $funcionalidad['nombre'],
                $funcionalidad['seccion'],
                $funcionalidad['icono'],
                $funcionalidad['orden']
            );
        }

        $ahora = now();

        DB::statement(
            "merge [tbl_funcionalidad] with (holdlock) as destino
             using (values {$valores})
                as origen ([col_clave], [col_nombre], [col_seccion], [col_icono], [col_orden])
                on destino.[col_clave] = origen.[col_clave]
             when matched then update set
                  destino.[col_nombre] = origen.[col_nombre],
                  destino.[col_seccion] = origen.[col_seccion],
                  destino.[col_icono] = origen.[col_icono],
                  destino.[col_orden] = origen.[col_orden],
                  destino.[updated_at] = ?
             when not matched then insert
                  ([col_clave], [col_nombre], [col_seccion], [col_icono], [col_orden], [col_activo], [created_at], [updated_at])
                  values (origen.[col_clave], origen.[col_nombre], origen.[col_seccion], origen.[col_icono],
                          origen.[col_orden], 1, ?, ?);",
            [...$parametros, $ahora, $ahora, $ahora]
        );
    }

    /**
     * @return int roles a los que se les sembro la matriz en esta corrida
     */
    private function sembrarMatrizInicial(): int
    {
        // Se hidratan como Rol para reusar es_admin_del_sistema en vez de
        // repetir aqui como se reconoce al administrador.
        $roles = Rol::hydrate(DB::select(
            'select r.id, r.col_clave, r.col_gestiona_catalogo, r.col_sistema
               from tbl_rol r
              where not exists (select 1 from tbl_rol_funcionalidad rf where rf.col_rol_id = r.id)'
        ));

        $funcionalidades = DB::select('select id, col_clave from tbl_funcionalidad where col_activo = 1');
        $ahora = now();
        $filas = [];

        foreach ($roles as $rol) {
            foreach ($funcionalidades as $funcionalidad) {
                $concede = $rol->es_admin_del_sistema
                    || Funcionalidad::permisoHeredado($funcionalidad->col_clave, $rol->col_gestiona_catalogo);

                $filas[] = [(int) $rol->id, (int) $funcionalidad->id, (int) $concede, (int) $concede, (int) $concede, $ahora, $ahora];
            }
        }

        foreach (array_chunk($filas, self::LOTE) as $lote) {
            DB::insert(
                'insert into [tbl_rol_funcionalidad]
                    ([col_rol_id], [col_funcionalidad_id], [col_ver], [col_editar], [col_eliminar], [created_at], [updated_at])
                 values '.implode(', ', array_fill(0, count($lote), '(?, ?, ?, ?, ?, ?, ?)')),
                array_merge(...$lote)
            );
        }

        return $roles->count();
    }
}
