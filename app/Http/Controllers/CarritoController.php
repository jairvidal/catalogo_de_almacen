<?php

namespace App\Http\Controllers;

use App\Models\Repuesto;
use App\Services\Carrito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CarritoController extends Controller
{
    public function index(Carrito $carrito): View
    {
        return view('carrito.index', [
            'lineas' => $carrito->lineas(),
            'unidades' => $carrito->cantidadUnidades(),
        ]);
    }

    /**
     * Agrega un repuesto. Responde JSON cuando la peticion viene del boton
     * del catalogo (fetch) y redirecciona cuando es un formulario normal.
     */
    public function store(Request $request, Repuesto $repuesto, Carrito $carrito): JsonResponse|RedirectResponse
    {
        $datos = $request->validate([
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        if (! $repuesto->activo || $repuesto->cantidad_disponible <= 0) {
            $mensaje = 'El repuesto no tiene existencias disponibles.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'mensaje' => $mensaje], 422)
                : back()->with('error', $mensaje);
        }

        $cantidadPedida = (int) ($datos['cantidad'] ?? 1);
        $cantidadPrevia = $carrito->cantidadDe($repuesto->id);
        $cantidadFinal = $carrito->agregar($repuesto, $cantidadPedida);

        // El carrito topa al stock disponible; si topo, hay que decirlo.
        $mensaje = $cantidadFinal < $cantidadPrevia + $cantidadPedida
            ? "Solo hay {$repuesto->cantidad_disponible} unidades de \"{$repuesto->nombre}\"; se agrego el maximo disponible."
            : "Se agrego \"{$repuesto->nombre}\" a la solicitud.";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'mensaje' => $mensaje,
                'cantidad' => $cantidadFinal,
                'referencias' => $carrito->cantidadReferencias(),
                'unidades' => $carrito->cantidadUnidades(),
            ]);
        }

        return back()->with('exito', $mensaje);
    }

    public function update(Request $request, Repuesto $repuesto, Carrito $carrito): RedirectResponse
    {
        $datos = $request->validate([
            'cantidad' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $final = $carrito->fijar($repuesto, (int) $datos['cantidad']);

        if ($final < (int) $datos['cantidad']) {
            return back()->with(
                'error',
                "Solo hay {$repuesto->cantidad_disponible} unidades de \"{$repuesto->nombre}\"; se ajusto la cantidad."
            );
        }

        return back()->with('exito', 'Cantidad actualizada.');
    }

    public function destroy(Repuesto $repuesto, Carrito $carrito): RedirectResponse
    {
        $carrito->quitar($repuesto->id);

        return back()->with('exito', "Se quito \"{$repuesto->nombre}\" de la solicitud.");
    }

    public function vaciar(Carrito $carrito): RedirectResponse
    {
        $carrito->vaciar();

        return redirect()->route('catalogo.index')->with('exito', 'Se vacio la solicitud.');
    }
}
