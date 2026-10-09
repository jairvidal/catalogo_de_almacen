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
            {{-- align-items-start y no center: el texto de ayuda de un filtro
                 bloqueado agrega altura a su columna y, centrado, desplazaria
                 hacia abajo el buscador y el interruptor de las demas. --}}
            <div class="row g-2 align-items-start">
                <div class="col-lg-4">
                    <label for="q" class="form-label small text-secondary mb-1">Buscar</label>
                    {{-- Busqueda por imagen (camara o archivo): la maneja app.js
                         por data-busqueda-imagen y va por fetch a su propio
                         endpoint. Los <input type="file"> NO llevan name a
                         proposito: asi nunca viajan con este formulario GET. --}}
                    <div data-busqueda-imagen
                         data-url="{{ route('catalogo.buscar-imagen') }}"
                         data-max-kb="{{ (int) config('services.anthropic.imagen.max_kb', 10240) }}">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="q" name="q" value="{{ $termino }}"
                               placeholder="Codigo, nombre o descripcion del repuesto" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary boton-busqueda-imagen"
                                data-abrir-imagen="camara"
                                title="Buscar con la camara" aria-label="Tomar una foto del repuesto para buscarlo">
                            <i class="bi bi-camera" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary boton-busqueda-imagen"
                                data-abrir-imagen="archivo"
                                title="Buscar con una foto (JPG o PNG)" aria-label="Subir una foto JPG o PNG del repuesto para buscarlo">
                            <span class="icono-subir-imagen" aria-hidden="true">
                                <i class="bi bi-image"></i><i class="bi bi-arrow-up-circle-fill"></i>
                            </span>
                        </button>
                    </div>
                    {{-- Fuera del input-group: Bootstrap redondea la esquina del
                         ultimo hijo y un input oculto al final se la quitaria al boton. --}}
                    <input type="file" accept="image/*" capture="environment" hidden data-entrada-imagen="camara">
                    <input type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden data-entrada-imagen="archivo">
                    </div>
                </div>

                {{-- Categoria y Tipo de repuesto son excluyentes: mientras uno
                     tiene valor el otro va deshabilitado, y un select deshabilitado
                     no viaja en el formulario. data-excluye (app.js) repite la
                     regla al cambiar sin recargar; el controlador la sostiene si
                     llegan los dos por URL. --}}
                <div class="col-lg-3 col-sm-6">
                    <label for="categoria_id" class="form-label small text-secondary mb-1">Categoria</label>
                    <select class="form-select" id="categoria_id" name="categoria_id"
                            aria-describedby="categoria_id_ayuda"
                            data-excluye="categoria" data-autoenviar
                            @disabled($tipoActivo !== '')>
                        <option value="">Todas las categorias</option>
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria->id }}" @selected($categoriaActiva === $categoria->id)>
                                {{ $categoria->col_nombre }}
                            </option>
                        @endforeach
                    </select>
                    <div id="categoria_id_ayuda" class="form-text small mt-1" @if ($tipoActivo === '') hidden @endif>
                        No se combina con el tipo de repuesto. Elija "Todos los tipos" para usarla.
                    </div>
                </div>

                {{-- Agrupacion generica heredada; se mantiene aparte de la
                     categoria para no perder el filtro que ya existia. --}}
                <div class="col-lg-3 col-sm-6">
                    <label for="categoria" class="form-label small text-secondary mb-1">Tipo de repuesto</label>
                    <select class="form-select" id="categoria" name="categoria"
                            aria-describedby="categoria_ayuda"
                            data-excluye="categoria_id" data-autoenviar
                            @disabled($categoriaActiva > 0)>
                        <option value="">Todos los tipos</option>
                        @foreach ($tipos as $tipo)
                            <option value="{{ $tipo }}" @selected($tipoActivo === $tipo)>{{ $tipo }}</option>
                        @endforeach
                    </select>
                    <div id="categoria_ayuda" class="form-text small mt-1" @if ($categoriaActiva <= 0) hidden @endif>
                        No se combina con la categoria. Elija "Todas las categorias" para usarlo.
                    </div>
                </div>

                <div class="col-lg-2 col-sm-6">
                    <label class="form-label small text-secondary mb-1 d-none d-lg-block">&nbsp;</label>
                    <div class="form-check form-switch pt-sm-2">
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

    {{-- Resultados de la busqueda por imagen: los pinta app.js con el HTML que
         devuelve el servidor. Mientras se ven, el listado normal queda oculto
         (no se descarta), y "Quitar filtro por imagen" lo vuelve a mostrar. --}}
    <div data-resultados-imagen aria-live="polite" hidden></div>

    <div data-resultados-catalogo>
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
                @include('catalogo.partials.tarjeta-repuesto', [
                    'repuesto' => $repuesto,
                    'enCarrito' => $seleccionados[$repuesto->id] ?? 0,
                ])
            @endforeach
        </div>

        <div class="mt-4 d-flex justify-content-center">
            {{ $repuestos->links('pagination::bootstrap-5') }}
        </div>
    @endif
    </div>

@endsection
