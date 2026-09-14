<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermisoRolRequest;
use App\Models\Funcionalidad;
use App\Models\Rol;
use App\Services\PermisoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PermisoAdminController extends Controller
{
    public function __construct(private readonly PermisoService $permisos) {}

    /**
     * Pantalla Funciones por perfil: select de rol y matriz del rol elegido.
     */
    public function index(Request $request): View
    {
        $roles = Rol::query()->activos()->orderBy('col_nombre')->get();
        $rol = $this->rolElegido($roles, $request->query('rol_id'));

        return view('admin.permisos.index', [
            'roles' => $roles,
            'rol' => $rol,
            'matriz' => $rol ? $this->permisos->matriz($rol) : ['configurada' => false, 'secciones' => []],
            'puedeGuardar' => (bool) $request->user()?->puede(Funcionalidad::PERMISOS, Funcionalidad::ACCION_EDITAR),
        ]);
    }

    public function update(PermisoRolRequest $request, Rol $rol): RedirectResponse
    {
        $resultado = $this->permisos->guardar($rol, $request->validated('permisos') ?? [], $request->user());

        $mensaje = $resultado['admin_forzado']
            ? "El perfil {$rol->col_nombre} es el administrador del sistema y conserva todos los permisos."
            : "Permisos del perfil {$rol->col_nombre} guardados.";

        return redirect()
            ->route('admin.permisos.index', ['rol_id' => $rol->id])
            ->with('exito', $mensaje);
    }

    /**
     * El rol pedido por querystring, si es un rol activo. Si no llega o no
     * sirve, el primero que no sea el administrador: su matriz esta bloqueada
     * y abrir la pantalla en ella no deja hacer nada.
     *
     * @param  Collection<int, Rol>  $roles
     */
    private function rolElegido(Collection $roles, mixed $rolId): ?Rol
    {
        if (is_string($rolId) && ctype_digit($rolId)) {
            $pedido = $roles->firstWhere('id', (int) $rolId);

            if ($pedido) {
                return $pedido;
            }
        }

        return $roles->first(fn (Rol $rol) => ! $rol->es_admin_del_sistema) ?? $roles->first();
    }
}
