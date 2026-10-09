<?php

namespace App\Http\Controllers;

use App\Exceptions\ClaudeApiException;
use App\Http\Requests\BuscarPorImagenRequest;
use App\Services\BuscadorRepuestosPorImagen;
use App\Services\Carrito;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Busqueda de repuestos por imagen del catalogo publico (botones camara y
 * subir foto junto al buscador). Solo responde JSON: lo llama app.js
 * (form de data-busqueda-imagen) y pinta el HTML que devuelve.
 *
 * Es aditiva: no toca CatalogoController ni sus filtros. La imagen se lee del
 * temporal de PHP y nunca se guarda en disco publico.
 */
class BusquedaImagenController extends Controller
{
    public function buscar(BuscarPorImagenRequest $request, BuscadorRepuestosPorImagen $buscador, Carrito $carrito): JsonResponse
    {
        // Dos llamadas a la API dentro de la peticion: se da margen a PHP. El
        // servidor web (IIS/FastCGI) tiene su propio tiempo maximo.
        @set_time_limit(max(60, (int) config('services.anthropic.timeout', 90) * 2 + 30));

        try {
            $resultado = $buscador->buscar($request->file('imagen')->getRealPath());
        } catch (InvalidArgumentException) {
            return response()->json(['ok' => false, 'mensaje' => 'No se pudo leer la imagen. Use una foto JPG o PNG.'], 422);
        } catch (ClaudeApiException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::error('Fallo inesperado en la busqueda por imagen.', ['excepcion' => $e->getMessage()]);

            return response()->json(['ok' => false, 'mensaje' => 'No se pudo completar la busqueda por imagen. Intente de nuevo.'], 500);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => $resultado->mensaje(),
            'total' => $resultado->total(),
            'umbral' => $resultado->umbral,
            'html' => view('catalogo.partials.resultados-imagen', [
                'resultado' => $resultado,
                'seleccionados' => $carrito->contenido(),
            ])->render(),
        ]);
    }
}
