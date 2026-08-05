<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolRequest;
use App\Models\Rol;
use App\Services\RolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RolAdminController extends Controller
{
    public function __construct(private readonly RolService $roles) {}

    public function index(Request $request): View
    {
        $termino = $request->string('q')->toString();
        $filtro = $request->string('filtro')->toString();

        $roles = Rol::query()
            ->buscar($termino)
            ->when($filtro === 'activos', fn ($q) => $q->where('col_activo', true))
            ->when($filtro === 'anulados', fn ($q) => $q->where('col_activo', false))
            ->withCount(['usuarios', 'usuarios as usuarios_activos_count' => fn ($q) => $q->where('activo', true)])
            ->orderByDesc('col_sistema')
            ->orderBy('col_nombre')
            ->paginate(20)
            ->withQueryString();

        return view('admin.roles.index', [
            'roles' => $roles,
            'termino' => $termino,
            'filtro' => $filtro,
            'totales' => [
                'todos' => Rol::count(),
                'activos' => Rol::where('col_activo', true)->count(),
                'anulados' => Rol::where('col_activo', false)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'rol' => new Rol(['col_activo' => true, 'col_gestiona_catalogo' => false]),
        ]);
    }

    public function store(RolRequest $request): RedirectResponse
    {
        $rol = Rol::create($request->validated());

        return redirect()
            ->route('admin.roles.index')
            ->with('exito', "Rol {$rol->col_nombre} creado.");
    }

    public function edit(Rol $rol): View
    {
        return view('admin.roles.form', ['rol' => $rol]);
    }

    public function update(RolRequest $request, Rol $rol): RedirectResponse
    {
        $rol->update($request->validated());

        return redirect()
            ->route('admin.roles.index')
            ->with('exito', "Rol {$rol->col_nombre} actualizado.");
    }

    /**
     * Anular no borra: los usuarios historicos apuntan al registro por rol_id.
     */
    public function destroy(Rol $rol): RedirectResponse
    {
        try {
            $this->roles->anular($rol);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('exito', "Rol {$rol->col_nombre} anulado; ya no se puede asignar a un usuario.");
    }
}
