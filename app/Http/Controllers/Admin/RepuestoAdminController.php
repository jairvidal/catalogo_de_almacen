<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RepuestoRequest;
use App\Models\Repuesto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RepuestoAdminController extends Controller
{
    public function index(Request $request): View
    {
        $termino = $request->string('q')->toString();
        $filtro = $request->string('filtro')->toString();

        $repuestos = Repuesto::query()
            ->buscar($termino)
            ->when($filtro === 'agotados', fn ($q) => $q->where('cantidad_disponible', '<=', 0))
            ->when($filtro === 'bajos', fn ($q) => $q->whereColumn('cantidad_disponible', '<=', 'stock_minimo')
                ->where('cantidad_disponible', '>', 0))
            ->when($filtro === 'inactivos', fn ($q) => $q->where('activo', false))
            ->orderBy('codigo')
            ->paginate(20)
            ->withQueryString();

        return view('admin.repuestos.index', [
            'repuestos' => $repuestos,
            'termino' => $termino,
            'filtro' => $filtro,
            'totales' => [
                'todos' => Repuesto::count(),
                'agotados' => Repuesto::where('cantidad_disponible', '<=', 0)->count(),
                'bajos' => Repuesto::whereColumn('cantidad_disponible', '<=', 'stock_minimo')
                    ->where('cantidad_disponible', '>', 0)->count(),
                'inactivos' => Repuesto::where('activo', false)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.repuestos.form', [
            'repuesto' => new Repuesto(['unidad_medida' => 'UND', 'activo' => true]),
            'categorias' => $this->categorias(),
        ]);
    }

    public function store(RepuestoRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $datos['foto'] = $this->guardarImagen($request->file('imagen'), $datos['codigo']);
        unset($datos['imagen']);

        $repuesto = Repuesto::create($datos);

        return redirect()
            ->route('admin.repuestos.index')
            ->with('exito', "Repuesto {$repuesto->codigo} creado.");
    }

    public function edit(Repuesto $repuesto): View
    {
        return view('admin.repuestos.form', [
            'repuesto' => $repuesto,
            'categorias' => $this->categorias(),
        ]);
    }

    public function update(RepuestoRequest $request, Repuesto $repuesto): RedirectResponse
    {
        $datos = $request->validated();

        if ($nuevaFoto = $this->guardarImagen($request->file('imagen'), $datos['codigo'])) {
            $datos['foto'] = $nuevaFoto;
        }

        unset($datos['imagen']);

        $repuesto->update($datos);

        return redirect()
            ->route('admin.repuestos.index')
            ->with('exito', "Repuesto {$repuesto->codigo} actualizado.");
    }

    /**
     * Ajuste rapido de existencias desde el listado.
     */
    public function ajustarStock(Request $request, Repuesto $repuesto): RedirectResponse
    {
        $datos = $request->validate([
            'cantidad_disponible' => ['required', 'integer', 'min:0', 'max:999999'],
        ], [], ['cantidad_disponible' => 'cantidad disponible']);

        $repuesto->update($datos);

        return back()->with('exito', "Existencias de {$repuesto->codigo} actualizadas a {$datos['cantidad_disponible']}.");
    }

    /**
     * No se borra fisicamente: los items historicos apuntan al repuesto.
     */
    public function destroy(Repuesto $repuesto): RedirectResponse
    {
        $repuesto->update(['activo' => false]);

        return back()->with('exito', "Repuesto {$repuesto->codigo} desactivado; ya no aparece en el catalogo publico.");
    }

    /**
     * Guarda la imagen en public/img con el codigo del repuesto como nombre.
     */
    private function guardarImagen(?UploadedFile $imagen, string $codigo): ?string
    {
        if (! $imagen) {
            return null;
        }

        $nombre = Str::slug($codigo).'-'.now()->format('YmdHis').'.'.$imagen->getClientOriginalExtension();
        $imagen->move(public_path('img'), $nombre);

        return $nombre;
    }

    /**
     * @return Collection<int, string>
     */
    private function categorias()
    {
        return Repuesto::whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria');
    }
}
