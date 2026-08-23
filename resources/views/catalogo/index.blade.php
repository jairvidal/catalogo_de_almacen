@extends('layouts.app')

@section('titulo', 'Catalogo de repuestos')

@section('contenido')

    @php
        $hayFiltros = $termino !== '' || $categoriaActiva > 0 || $tipoActivo !== '' || $soloDisponibles;
    @endphp

    <div class="row align-items-end g-3 mb-4">
        <div class="col-lg-7">
            <h1 class="h3 mb-1">Catalogo de repuestos</h1>
            <p class="text-secondary mb-0">
                Busque los repuestos que necesita, agreguelos a su solicitud y enviela al almacen.
            </p>
        </div>
        <div class="col-lg-5 text-lg-end">
            <span class="badge text-bg-light border fs-6 fw-normal">
                <i class="bi bi-box-seam text-marca me-1"></i>
                {{ number_format($repuestos->total()) }}
                {{ Str::plural('repuesto', $repuestos->total()) }}
                @if ($hayFiltros)
                    encontrados
                @else
                    en el catalogo
                @endif
            </span>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filtros                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <form method="GET" action="{{ route('catalogo.index') }}" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-center">
                <div class="col-lg-4">
                    <label for="q" class="form-label small text-secondary mb-1">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="q" name="q" value="{{ $termino }}"
                               placeholder="Codigo, nombre o descripcion del repuesto" autocomplete="off">
                    </div>
                </div>

                <div class="col-lg-3 col-sm-6">
                    <label for="categoria_id" class="form-label small text-secondary mb-1">Categoria</label>
                    <select class="form-select" id="categoria_id" name="categoria_id" data-autoenviar>
                        <option value="">Todas las categorias</option>
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria->id }}" @selected($categoriaActiva === $categoria->id)>
                                {{ $categoria->col_nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Agrupacion generica heredada; se mantiene aparte de la
                     categoria para no perder el filtro que ya existia. --}}
                <div class="col-lg-3 col-sm-6">
                    <label for="categoria" class="form-label small text-secondary mb-1">Tipo de repuesto</label>
                    <select class="form-select" id="categoria" name="categoria" data-autoenviar>
                        <option value="">Todos los tipos</option>
                        @foreach ($tipos as $tipo)
                            <option value="{{ $tipo }}" @selected($tipoActivo === $tipo)>{{ $tipo }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-lg-2 col-sm-6">
                    <label class="form-label small text-secondary mb-1 d-none d-lg-block">&nbsp;</label>
                    <div class="form-check form-switch pt-lg-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="disponibles"
                               name="disponibles" value="1" @checked($soloDisponibles) data-autoenviar>
                        <label class="form-check-label small" for="disponibles">Solo con existencias</label>
                    </div>
                </div>

                <div class="col-lg-12 col-sm-6">
                    <div class="d-flex gap-2 mt-lg-2">
                        <button type="submit" class="btn btn-marca">Buscar</button>
                        @if ($hayFiltros)
                            <a href="{{ route('catalogo.index') }}" class="btn btn-outline-secondary" title="Limpiar filtros">
                                <i class="bi bi-x-lg me-1"></i>Limpiar filtros
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Resultados                                                         --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($repuestos->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-search fs-1 text-secondary opacity-50"></i>
                <h2 class="h5 mt-3">No se encontraron repuestos</h2>
                <p class="text-secondary mb-3">
                    Pruebe con otro termino de busqueda o quite los filtros aplicados.
                </p>
                <a href="{{ route('catalogo.index') }}" class="btn btn-outline-marca">Ver todo el catalogo</a>
            </div>
        </div>
    @else
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">
            @foreach ($repuestos as $repuesto)
                @php $enCarrito = $seleccionados[$repuesto->id] ?? 0; @endphp

                <div class="col">
                    <div class="card card-repuesto">
                        <a href="{{ route('catalogo.show', $repuesto) }}" class="repuesto-foto">
                            <img src="{{ $repuesto->foto_url }}" alt="{{ $repuesto->nombre }}" loading="lazy">

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
            @endforeach
        </div>

        <div class="mt-4 d-flex justify-content-center">
            {{ $repuestos->links('pagination::bootstrap-5') }}
        </div>
    @endif

@endsection
