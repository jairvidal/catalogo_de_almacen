<?php

namespace App\Http\Controllers;

use App\Models\SolicitanteErp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buscador publico del cuadro combinado "Solicitante".
 *
 * No tiene autenticacion (el catalogo es publico), asi que la respuesta lleva
 * UNICAMENTE id, nombre y area: nunca cedula ni correo. La ruta va con
 * throttle para que no se pueda descargar la lista de un tiron.
 */
class SolicitanteController extends Controller
{
    /** Tope del termino: nadie escribe un nombre de mas de esto. */
    private const LARGO_MAXIMO_TERMINO = 100;

    public function buscar(Request $request): JsonResponse
    {
        $termino = mb_substr(trim((string) $request->query('q')), 0, self::LARGO_MAXIMO_TERMINO);

        return response()->json([
            'datos' => SolicitanteErp::buscarActivos($termino),
        ]);
    }
}
