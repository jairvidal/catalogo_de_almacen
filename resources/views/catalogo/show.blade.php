@extends('layouts.app')

@section('titulo', $repuesto->nombre)

@section('contenido')

    <nav aria-label="Ruta de navegacion" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="{{ route('catalogo.index') }}" class="text-decoration-none">Catalogo</a></li>
            @if ($repuesto->categoriaAsignada)
                <li class="breadcrumb-item">
                    <a href="{{ route('catalogo.index', ['categoria_id' => $repuesto->categoriaAsignada->id]) }}"
                       class="text-decoration-none">{{ $repuesto->categoriaAsignada->col_nombre }}</a>
                </li>
            @endif
            @if ($repuesto->desc_cat_1)
                <li class="breadcrumb-item">
                    <a href="{{ route('catalogo.index', ['categoria' => $repuesto->desc_cat_1]) }}"
                       class="text-decoration-none">{{ $repuesto->desc_cat_1 }}</a>
                </li>
            @endif
            <li class="breadcrumb-item active" aria-current="page">{{ $repuesto->codigo }}</li>
        </ol>
    </nav>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="row g-4">

                <div class="col-md-5">
                    <img src="{{ $repuesto->foto_url }}" alt="{{ $repuesto->nombre }}" class="foto-detalle">
                </div>

                <div class="col-md-7">
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                        <span class="badge text-bg-dark font-monospace">{{ $repuesto->codigo }}</span>
                        @if ($repuesto->categoriaAsignada)
                            {{-- El hex es dato de tbl_categoria (el color del marco impreso en
                                 la foto): es contenido, no diseno, por eso va en linea. --}}
                            <a href="{{ route('catalogo.index', ['categoria_id' => $repuesto->categoriaAsignada->id]) }}"
                               class="badge text-bg-light border text-secondary fw-normal text-decoration-none d-inline-flex align-items-center gap-1">
                                <span class="chip-color chip-color-sm"
                                      style="background-color: {{ $repuesto->categoriaAsignada->col_color_hex }}"></span>
                                {{ $repuesto->categoriaAsignada->col_nombre }}
                            </a>
                        @endif
                        @if ($repuesto->desc_cat_1)
                            <span class="badge text-bg-light border text-secondary fw-normal">{{ $repuesto->desc_cat_1 }}</span>
                        @endif
                        @if ($repuesto->desc_cat_2)
                            <span class="badge text-bg-light border text-secondary fw-normal">{{ $repuesto->desc_cat_2 }}</span>
                        @endif
                    </div>

                    <h1 class="h3 mb-3">{{ $repuesto->nombre }}</h1>

                    @if ($repuesto->cod_referencia)
                        <p class="text-secondary small mb-3">
                            Referencia: <span class="font-monospace">{{ $repuesto->cod_referencia }}</span>
                        </p>
                    @endif

                    <dl class="row small mb-4">
                        <dt class="col-5 col-sm-4 text-secondary fw-normal">Id interno</dt>
                        <dd class="col-7 col-sm-8">{{ $repuesto->id }}</dd>

                        <dt class="col-5 col-sm-4 text-secondary fw-normal">Categoria</dt>
                        <dd class="col-7 col-sm-8">
                            @if ($repuesto->categoriaAsignada)
                                <span class="chip-color chip-color-sm me-1"
                                      style="background-color: {{ $repuesto->categoriaAsignada->col_color_hex }}"></span>
                                {{ $repuesto->categoriaAsignada->col_nombre }}
                            @else
                                <span class="text-secondary">Sin categoria</span>
                            @endif
                        </dd>

                        <dt class="col-5 col-sm-4 text-secondary fw-normal">Unidad de medida</dt>
                        <dd class="col-7 col-sm-8">{{ $repuesto->unidad_medida }}</dd>

                        @if ($repuesto->ubicacion)
                            <dt class="col-5 col-sm-4 text-secondary fw-normal">Ubicacion en almacen</dt>
                            <dd class="col-7 col-sm-8">{{ $repuesto->ubicacion }}</dd>
                        @endif

                        <dt class="col-5 col-sm-4 text-secondary fw-normal">Existencias</dt>
                        <dd class="col-7 col-sm-8">
                            @if ($repuesto->sin_stock)
                                <span class="text-danger fw-semibold">Sin existencias</span>
                            @else
                                <span class="fw-semibold">{{ $repuesto->existencia }}</span>
                                {{ $repuesto->unidad_medida }}
                                @if ($repuesto->stock_bajo)
                                    <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-normal ms-1">
                                        Existencias bajas
                                    </span>
                                @endif
                            @endif
                        </dd>
                    </dl>

                    @if ($enCarrito > 0)
                        <div class="alert alert-success d-flex align-items-center gap-2 py-2">
                            <i class="bi bi-cart-check-fill"></i>
                            <div class="flex-grow-1 small">
                                Ya tiene <strong>{{ $enCarrito }}</strong> {{ Str::plural('unidad', $enCarrito) }} en su solicitud.
                            </div>
                            <a href="{{ route('carrito.index') }}" class="btn btn-sm btn-outline-success">Ver solicitud</a>
                        </div>
                    @endif

                    @if ($repuesto->sin_stock)
                        <button type="button" class="btn btn-secondary btn-lg" disabled>
                            <i class="bi bi-x-circle me-1"></i>No disponible en este momento
                        </button>
                        <p class="small text-secondary mt-2 mb-0">
                            Consulte con el almacen la fecha estimada de reposicion.
                        </p>
                    @else
                        <form method="POST" action="{{ route('carrito.store', $repuesto) }}" class="row g-2 align-items-end">
                            @csrf

                            <div class="col-auto">
                                <label for="cantidad" class="form-label small text-secondary mb-1">Cantidad</label>
                                <div class="input-group" style="width:9.5rem">
                                    <button class="btn btn-outline-secondary" type="button"
                                            data-paso="-1" data-objetivo="cantidad" aria-label="Disminuir">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" class="form-control text-center" id="cantidad" name="cantidad"
                                           value="1" min="1" max="{{ $repuesto->existencia }}" required>
                                    <button class="btn btn-outline-secondary" type="button"
                                            data-paso="1" data-objetivo="cantidad" aria-label="Aumentar">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-auto">
                                <button type="submit" class="btn btn-marca btn-lg">
                                    <i class="bi bi-cart-plus me-1"></i>Agregar a mi solicitud
                                </button>
                            </div>
                        </form>
                    @endif
                </div>

            </div>
        </div>
    </div>

    @if ($relacionados->isNotEmpty())
        <h2 class="h5 mt-5 mb-3">Otros repuestos de {{ $repuesto->desc_cat_2 }}</h2>

        <div class="row row-cols-2 row-cols-md-4 g-3">
            @foreach ($relacionados as $otro)
                <div class="col">
                    <div class="card card-repuesto">
                        <a href="{{ route('catalogo.show', $otro) }}" class="repuesto-foto">
                            <img src="{{ $otro->foto_url }}" alt="{{ $otro->nombre }}" loading="lazy">
                        </a>
                        <div class="card-body p-3">
                            <span class="repuesto-codigo d-block mb-1">{{ $otro->codigo }}</span>
                            <a href="{{ route('catalogo.show', $otro) }}"
                               class="repuesto-nombre text-decoration-none text-dark">{{ $otro->nombre }}</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

@endsection
