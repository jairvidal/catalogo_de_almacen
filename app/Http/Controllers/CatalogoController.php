<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Repuesto;
use App\Services\Carrito;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogoController extends Controller
{
    /**
     * Catalogo publico con busqueda por texto, filtro por categoria
     * (tbl_categoria), filtro por el tipo historico de texto y opcion de ver
     * solo lo que tiene existencias.
     */
    public function index(Request $request, Carrito $carrito): View
    {
        $termino = $request->string('q')->toString();
        $categoriaId = $request->integer('categoria_id');
        $tipo = $request->string('categoria')->toString();
        $soloDisponibles = $request->boolean('disponibles');

        $repuestos = Repuesto::query()
            ->activos()
            ->buscar($termino)
            ->when($categoriaId > 0, fn ($q) => $q->where('categoria_id', $categoriaId))
            ->when($tipo !== '', fn ($q) => $q->where('categoria', $tipo))
            ->when($soloDisponibles, fn ($q) => $q->disponibles())
            ->with('categoriaAsignada')
            ->orderBy('nombre')
            ->orderBy('codigo')
            ->paginate(24)
            ->withQueryString();

        // Solo las categorias que hoy tienen algo publicable: un filtro que
        // devuelve cero resultados no le sirve a nadie.
        $categorias = Categoria::query()
            ->activas()
            ->whereHas('repuestos', fn ($q) => $q->where('activo', true))
            ->orderBy('col_nombre')
            ->get();

        $tipos = Repuesto::query()
            ->activos()
            ->whereNotNull('categoria')
            ->distinct()
            ->orderBy('categoria')
            ->pluck('categoria');

        return view('catalogo.index', [
            'repuestos' => $repuestos,
            'categorias' => $categorias,
            'tipos' => $tipos,
            'termino' => $termino,
            'categoriaActiva' => $categoriaId,
            'tipoActivo' => $tipo,
            'soloDisponibles' => $soloDisponibles,
            'seleccionados' => $carrito->contenido(),
        ]);
    }

    public function show(Repuesto $repuesto, Carrito $carrito): View
    {
        abort_unless($repuesto->activo, 404);

        // Los relacionados siguen saliendo del tipo historico de texto y no de
        // la categoria por color: el color agrupa mas de cien items y como
        // "otros repuestos parecidos" no dice nada.
        $relacionados = Repuesto::query()
            ->activos()
            ->where('categoria', $repuesto->categoria)
            ->whereKeyNot($repuesto->id)
            ->inRandomOrder()
            ->limit(4)
            ->get();

        $repuesto->load('categoriaAsignada');

        return view('catalogo.show', [
            'repuesto' => $repuesto,
            'relacionados' => $relacionados,
            'enCarrito' => $carrito->cantidadDe($repuesto->id),
        ]);
    }
}
