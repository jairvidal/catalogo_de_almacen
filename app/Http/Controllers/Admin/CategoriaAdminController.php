<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Models\Repuesto;
use App\Services\CategoriaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoriaAdminController extends Controller
{
    public function __construct(private readonly CategoriaService $categorias) {}

    public function index(Request $request): View
    {
        $termino = $request->string('q')->toString();
        $filtro = $request->string('filtro')->toString();

        $categorias = Categoria::query()
            ->buscar($termino)
            ->when($filtro === 'activas', fn ($q) => $q->where('col_activo', true))
            ->when($filtro === 'anuladas', fn ($q) => $q->where('col_activo', false))
            ->withCount([
                'repuestos',
                'repuestos as repuestos_activos_count' => fn ($q) => $q->where('activo', true),
            ])
            ->orderBy('col_nombre')
            ->paginate(20)
            ->withQueryString();

        return view('admin.categorias.index', [
            'categorias' => $categorias,
            'termino' => $termino,
            'filtro' => $filtro,
            'sinCategoria' => Repuesto::whereNull('categoria_id')->where('activo', true)->count(),
            'totales' => [
                'todas' => Categoria::count(),
                'activas' => Categoria::where('col_activo', true)->count(),
                'anuladas' => Categoria::where('col_activo', false)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.categorias.form', [
            'categoria' => new Categoria(['col_activo' => true, 'col_color_hex' => '#D81818']),
        ]);
    }

    public function store(CategoriaRequest $request): RedirectResponse
    {
        $categoria = $this->categorias->crear($request->validated());

        return redirect()
            ->route('admin.categorias.index')
            ->with('exito', "Categoria {$categoria->col_nombre} creada.");
    }

    public function edit(Categoria $categoria): View
    {
        return view('admin.categorias.form', ['categoria' => $categoria]);
    }

    /**
     * col_slug no se toca: es la clave con la que la deteccion por color y el
     * seeder reconocen el registro. Solo cambian nombre, color, descripcion y estado.
     */
    public function update(CategoriaRequest $request, Categoria $categoria): RedirectResponse
    {
        $categoria->update($request->validated());

        return redirect()
            ->route('admin.categorias.index')
            ->with('exito', "Categoria {$categoria->col_nombre} actualizada.");
    }

    /**
     * Anular no borra: los repuestos apuntan al registro por categoria_id.
     */
    public function destroy(Categoria $categoria): RedirectResponse
    {
        try {
            $this->categorias->anular($categoria);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('exito', "Categoria {$categoria->col_nombre} anulada; ya no se puede asignar a un repuesto.");
    }
}
