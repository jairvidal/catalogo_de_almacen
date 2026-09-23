<?php

namespace App\Services;

use App\Models\Funcionalidad;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Matriz de permisos por rol del modulo Funciones por perfil.
 *
 * La DECISION de si un usuario puede algo vive en User::puede(); este servicio
 * solo arma la matriz que se pinta y guarda la que llega del formulario.
 */
class PermisoService
{
    /**
     * Filas por sentencia MERGE. Cada fila son 5 parametros mas 3 fijos de
     * fecha: 300 filas son 1.503 parametros, holgado contra el limite de 2.100
     * de SQL Server. Hoy hay seis funcionalidades; el lote existe para que el
     * dia que sean muchas no reviente sin avisar.
     */
    private const LOTE = 300;

    /**
     * Funcionalidades activas agrupadas por seccion, con lo que el rol puede
     * hacer en cada una tal como se debe pintar.
     *
     * - Funcionalidad reservada y rol fuera de la lista blanca: todo
     *   desmarcado y "reservada" en true, para que la vista bloquee esas
     *   casillas. Marcarlas no concederia nada (User::puede() las niega
     *   primero), asi que dejarlas editables mentiria sobre su efecto.
     * - Rol admin del sistema: todo marcado (y la vista lo bloquea).
     * - Rol con matriz guardada: lo guardado; sin fila, desmarcado.
     * - Rol sin matriz guardada: el permiso heredado, que es lo que hoy puede
     *   hacer de verdad. Pintarlo desmarcado mentiria sobre su acceso real.
     *
     * @return array{
     *     configurada: bool,
     *     secciones: array<string, list<array{id: int, clave: string, nombre: string, icono: string, reservada: bool, permisos: array<string, bool>}>>
     * }
     */
    public function matriz(Rol $rol): array
    {
        $guardados = $rol->permisosGuardados();
        $secciones = [];

        foreach ($this->funcionalidadesActivas() as $funcionalidad) {
            $vetada = ! Funcionalidad::rolAutorizado($funcionalidad->col_clave, $rol->col_clave);
            $permisos = [];

            foreach (array_keys(Funcionalidad::ACCIONES) as $accion) {
                $permisos[$accion] = match (true) {
                    $vetada => false,
                    $rol->es_admin_del_sistema => true,
                    $guardados === null => Funcionalidad::permisoHeredado($funcionalidad->col_clave, $rol->col_gestiona_catalogo),
                    default => $guardados[$funcionalidad->col_clave][$accion] ?? false,
                };
            }

            // Las secciones salen en el orden de la primera funcionalidad que
            // las trae; por eso la consulta ordena por col_orden.
            $secciones[$funcionalidad->col_seccion][] = [
                'id' => (int) $funcionalidad->id,
                'clave' => $funcionalidad->col_clave,
                'nombre' => $funcionalidad->col_nombre,
                'icono' => $funcionalidad->col_icono,
                'reservada' => $vetada,
                'permisos' => $permisos,
            ];
        }

        return [
            'configurada' => $guardados !== null,
            'secciones' => $secciones,
        ];
    }

    /**
     * Guarda la matriz completa de un rol: una fila por cada funcionalidad
     * activa, marcada o no. Guardar tambien las desmarcadas es lo que convierte
     * al rol en "configurado" y apaga el permiso heredado.
     *
     * @param  array<int|string, array<string, mixed>>  $marcas  funcionalidad_id => [ver|editar|eliminar => bool]
     * @return array{funcionalidades: int, admin_forzado: bool}
     *
     * @throws ValidationException si el rol fue anulado mientras se editaba.
     */
    public function guardar(Rol $rol, array $marcas, ?User $autor = null): array
    {
        $resultado = DB::transaction(function () use ($rol, $marcas) {
            // Se relee con bloqueo: serializa dos guardados del mismo rol y
            // confirma que no lo anularon entre abrir la pantalla y guardar.
            $actual = Rol::query()->lockForUpdate()->findOrFail($rol->id);

            if (! $actual->col_activo) {
                throw ValidationException::withMessages([
                    'rol_id' => "El perfil \"{$actual->col_nombre}\" esta anulado; no se le pueden asignar permisos.",
                ]);
            }

            $filas = [];

            foreach ($this->funcionalidadesActivas() as $funcionalidad) {
                $filas[] = $this->normalizar(
                    (int) $actual->id,
                    (int) $funcionalidad->id,
                    $marcas[$funcionalidad->id] ?? [],
                    // El admin del sistema se guarda completo, llegue lo que
                    // llegue: la vista bloquea sus casillas, pero un formulario
                    // manipulado no puede quitarle nada.
                    $actual->es_admin_del_sistema,
                    // Y una funcionalidad reservada se guarda en cero para el
                    // rol que no esta en su lista blanca, llegue marcada o no:
                    // la base no debe conservar una concesion que User::puede()
                    // niega, porque el dia que alguien quite la lista blanca
                    // reviviria sola.
                    ! Funcionalidad::rolAutorizado($funcionalidad->col_clave, $actual->col_clave)
                );
            }

            foreach (array_chunk($filas, self::LOTE) as $lote) {
                $this->escribirLote($lote);
            }

            return [
                'funcionalidades' => count($filas),
                'admin_forzado' => $actual->es_admin_del_sistema,
                'rol_clave' => $actual->col_clave,
            ];
        });

        // Si el autor edito su propio perfil, lo que queda de esta peticion no
        // debe seguir leyendo los permisos viejos.
        $autor?->olvidarPermisos();

        Log::info('Permisos por perfil actualizados.', [
            'usuario_id' => $autor?->id,
            'rol_id' => $rol->id,
            'rol_clave' => $resultado['rol_clave'],
            'funcionalidades' => $resultado['funcionalidades'],
        ]);

        return [
            'funcionalidades' => $resultado['funcionalidades'],
            'admin_forzado' => $resultado['admin_forzado'],
        ];
    }

    /**
     * @return list<object{id: int|string, col_clave: string, col_nombre: string, col_seccion: string, col_icono: string}>
     */
    private function funcionalidadesActivas(): array
    {
        return DB::select(
            'select id, col_clave, col_nombre, col_seccion, col_icono
               from tbl_funcionalidad
              where col_activo = 1
              order by col_orden, col_nombre'
        );
    }

    /**
     * Editar o eliminar implican ver: no se edita lo que no se puede abrir.
     * La base lo sostiene con el CHECK ck_tbl_rol_funcionalidad_ver.
     *
     * $vetada gana sobre $todo: si algun dia una funcionalidad reservada
     * excluyera al admin del sistema, no debe colarse por la puerta del "todo".
     *
     * @param  array<string, mixed>  $marca
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
     */
    private function normalizar(int $rolId, int $funcionalidadId, array $marca, bool $todo, bool $vetada = false): array
    {
        if ($vetada) {
            return [$rolId, $funcionalidadId, 0, 0, 0];
        }

        $editar = $todo || filter_var($marca[Funcionalidad::ACCION_EDITAR] ?? false, FILTER_VALIDATE_BOOLEAN);
        $eliminar = $todo || filter_var($marca[Funcionalidad::ACCION_ELIMINAR] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ver = $todo || $editar || $eliminar || filter_var($marca[Funcionalidad::ACCION_VER] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [$rolId, $funcionalidadId, (int) $ver, (int) $editar, (int) $eliminar];
    }

    /**
     * MERGE nativo con HOLDLOCK: inserta la fila que falta y actualiza la que
     * existe en una sola sentencia, sin la carrera de "leo si existe y luego
     * inserto". El indice unico rol + funcionalidad es la garantia final.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $lote
     */
    private function escribirLote(array $lote): void
    {
        $valores = implode(', ', array_fill(0, count($lote), '(?, ?, ?, ?, ?)'));
        $ahora = now();

        DB::statement(
            "merge [tbl_rol_funcionalidad] with (holdlock) as destino
             using (values {$valores})
                as origen ([col_rol_id], [col_funcionalidad_id], [col_ver], [col_editar], [col_eliminar])
                on destino.[col_rol_id] = origen.[col_rol_id]
               and destino.[col_funcionalidad_id] = origen.[col_funcionalidad_id]
             when matched then update set
                  destino.[col_ver] = origen.[col_ver],
                  destino.[col_editar] = origen.[col_editar],
                  destino.[col_eliminar] = origen.[col_eliminar],
                  destino.[updated_at] = ?
             when not matched then insert
                  ([col_rol_id], [col_funcionalidad_id], [col_ver], [col_editar], [col_eliminar], [created_at], [updated_at])
                  values (origen.[col_rol_id], origen.[col_funcionalidad_id], origen.[col_ver],
                          origen.[col_editar], origen.[col_eliminar], ?, ?);",
            [...array_merge(...$lote), $ahora, $ahora, $ahora]
        );
    }
}
