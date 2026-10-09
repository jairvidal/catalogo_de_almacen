{{-- Resultado de la busqueda por imagen. Lo devuelve BusquedaImagenController
     como HTML dentro del JSON y app.js lo pinta en [data-resultados-imagen].
     Variables: $resultado (ResultadoBusquedaImagen), $seleccionados (carrito). --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
        <div>
            <h2 class="h6 mb-1">
                <i class="bi bi-camera text-marca me-1" aria-hidden="true"></i>Busqueda por imagen
            </h2>
            <p class="small text-secondary mb-0">{{ $resultado->mensaje() }}</p>
        </div>
        <button type="button" class="btn btn-outline-secondary flex-shrink-0" data-quitar-busqueda-imagen>
            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Quitar filtro por imagen
        </button>
    </div>
</div>

@if ($resultado->total() === 0)
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-image fs-1 text-secondary opacity-50" aria-hidden="true"></i>
            <h2 class="h5 mt-3">Ningun repuesto coincide con la foto</h2>
            <p class="text-secondary mb-0">
                Pruebe con otra foto: la pieza sola, centrada, bien iluminada y sobre un fondo liso.
            </p>
        </div>
    </div>
@else
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">
        @foreach ($resultado->repuestos as $repuesto)
            @include('catalogo.partials.tarjeta-repuesto', [
                'repuesto' => $repuesto,
                'enCarrito' => $seleccionados[$repuesto->id] ?? 0,
                'porcentaje' => $resultado->porcentajeDe($repuesto),
            ])
        @endforeach
    </div>
@endif
