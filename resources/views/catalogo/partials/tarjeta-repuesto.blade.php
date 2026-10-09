{{-- Tarjeta de un repuesto del catalogo publico. La usan el listado normal y
     los resultados de la busqueda por imagen (que ademas pasa $porcentaje).
     Variables: $repuesto, $enCarrito (unidades en la solicitud), $porcentaje opcional. --}}
<div class="col">
    <div class="card card-repuesto">
        <a href="{{ route('catalogo.show', $repuesto) }}" class="repuesto-foto">
            <img src="{{ $repuesto->foto_url }}" alt="{{ $repuesto->nombre }}" loading="lazy">

            @isset($porcentaje)
                <span class="coincidencia-imagen">
                    <span class="badge text-bg-dark fw-semibold">
                        <i class="bi bi-camera me-1" aria-hidden="true"></i>{{ $porcentaje }}% coincidencia
                    </span>
                </span>
            @endisset

            @if ($repuesto->sin_stock)
                <span class="cinta-agotado">
                    <span class="badge text-bg-secondary">Sin existencias</span>
                </span>
            @endif
        </a>

        <div class="card-body d-flex flex-column p-3">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                <span class="repuesto-codigo">{{ $repuesto->codigo }}</span>
                @if ($repuesto->categoriaAsignada)
                    {{-- El color de relleno sale de tbl_categoria: es el color
                         real del marco de la foto, o sea contenido, no diseno.
                         Es la unica excepcion a "no hex sueltos en las vistas". --}}
                    <span class="badge text-bg-light border fw-normal text-secondary d-inline-flex align-items-center gap-1"
                          style="font-size:.68rem">
                        <span class="chip-color chip-color-sm"
                              style="background-color: {{ $repuesto->categoriaAsignada->col_color_hex }}"></span>
                        {{ $repuesto->categoriaAsignada->col_nombre }}
                    </span>
                @endif
            </div>

            <a href="{{ route('catalogo.show', $repuesto) }}"
               class="repuesto-nombre text-decoration-none text-dark mb-2">
                {{ $repuesto->nombre }}
            </a>

            <div class="mb-3">
                @if ($repuesto->sin_stock)
                    <span class="badge text-bg-danger-subtle text-danger-emphasis border border-danger-subtle fw-normal">
                        <i class="bi bi-x-circle me-1"></i>Agotado
                    </span>
                @elseif ($repuesto->stock_bajo)
                    <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-normal">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Ultimas {{ $repuesto->existencia }} {{ $repuesto->unidad_medida }}
                    </span>
                @else
                    <span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle fw-normal">
                        <i class="bi bi-check-circle me-1"></i>
                        {{ $repuesto->existencia }} {{ $repuesto->unidad_medida }} disponibles
                    </span>
                @endif
            </div>

            <div class="mt-auto">
                @if ($repuesto->sin_stock)
                    <button type="button" class="btn btn-outline-secondary w-100" disabled>
                        No disponible
                    </button>
                @else
                    <form method="POST" action="{{ route('carrito.store', $repuesto) }}" data-agregar-carrito>
                        @csrf
                        <input type="hidden" name="cantidad" value="1">
                        <button type="submit" class="btn btn-marca w-100">
                            <i class="bi bi-plus-lg me-1"></i>Agregar
                        </button>
                    </form>

                    @if ($enCarrito > 0)
                        <p class="small text-success mb-0 mt-2 text-center">
                            <i class="bi bi-cart-check me-1"></i>{{ $enCarrito }} en su solicitud
                        </p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
