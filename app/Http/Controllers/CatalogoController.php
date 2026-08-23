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
     * (tbl_categoria, el color del marco), filtro por el grupo del ERP y opcion
     * de ver solo lo que tiene existencias.
     */
    public function index(Request $request, Carrito $carrito): View
    {
        $termino = $request->string('q')->toString();
        $categoriaId = $request->integer('categoria_id');
        $grupo = $request->string('categoria')->toString();
        $soloDisponibles = $request->boolean('disponibles');

        $repuestos = Repuesto::query()
            ->activos()
            ->buscar($termino)
            ->when($categoriaId > 0, fn ($q) => $q->where('id_categoria', $categoriaId))
            ->when($grupo !== '', fn ($q) => $q->where('desc_cat_1', $grupo))
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
            ->whereHas('repuestos', fn ($q) => $q->where('estado', Repuesto::ESTADO_ACTIVO))
            ->orderBy('col_nombre')
            ->get();

        // El "Tipo de repuesto" pasa a ser el GRUPO del ERP (desc_cat_1), que
        // reemplazo a la columna de texto `categoria` del esquema anterior.
        $tipos = Repuesto::query()
            ->activos()
            ->whereNotNull('desc_cat_1')
            ->distinct()
            ->orderBy('desc_cat_1')
            ->pluck('desc_cat_1');

        return view('catalogo.index', [
            'repuestos' => $repuestos,
            'categorias' => $categorias,
            'tipos' => $tipos,
            'termino' => $termino,
            'categoriaActiva' => $categoriaId,
            'tipoActivo' => $grupo,
            'soloDisponibles' => $soloDisponibles,
            'seleccionados' => $carrito->contenido(),
        ]);
    }

    public function show(Repuesto $repuesto, Carrito $carrito): View
    {
        abort_unless($repuesto->estaActivo(), 404);

        // Los relacionados salen del SUBGRUPO del ERP (desc_cat_2) y no de la
        // categoria por color: el color agrupa mas de cien items y como "otros
        // repuestos parecidos" no dice nada. El subgrupo si es especifico.
        $relacionados = Repuesto::query()
            ->activos()
            ->where('desc_cat_2', $repuesto->desc_cat_2)
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
