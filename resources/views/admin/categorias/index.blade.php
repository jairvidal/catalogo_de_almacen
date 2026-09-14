@extends('layouts.admin')

@section('titulo', 'Categorias')

@use('App\Models\Funcionalidad')

@php
    // Lo que el perfil no puede hacer se pinta en gris y deshabilitado; la
    // ruta vuelve a negarlo con el middleware permiso.
    $puedeEditar = auth()->user()->puede(Funcionalidad::CATEGORIAS, Funcionalidad::ACCION_EDITAR);
    $puedeEliminar = auth()->user()->puede(Funcionalidad::CATEGORIAS, Funcionalidad::ACCION_ELIMINAR);
@endphp

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Categorias</h1>
            <p class="text-secondary mb-0 small">
                Agrupan el catalogo publico. Cada una corresponde al color del marco impreso en la foto del repuesto.
            </p>
        </div>
        @if ($puedeEditar)
            <a href="{{ route('admin.categorias.create') }}" class="btn btn-marca">
                <i class="bi bi-plus-lg me-1"></i>Nueva categoria
            </a>
        @else
            @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'plus-lg', 'texto' => 'Nueva categoria', 'clases' => 'btn-outline-secondary'])
        @endif
    </div>

    @if ($sinCategoria > 0)
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div class="small">
                Hay <strong>{{ $sinCategoria }}</strong> repuesto(s) activos sin categoria.
                Corra <code>php artisan repuestos:clasificar</code> para asignarla desde el marco de la foto.
            </div>
        </div>
    @endif

    {{-- Filtros rapidos --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $filtros = [
                '' => ['Todas', $totales['todas'], 'dark'],
                'activas' => ['Activas', $totales['activas'], 'success'],
                'anuladas' => ['Anuladas', $totales['anuladas'], 'secondary'],
            ];
        @endphp

        @foreach ($filtros as $clave => [$etiqueta, $valor, $color])
            <a href="{{ route('admin.categorias.index', array_filter(['filtro' => $clave, 'q' => $termino])) }}"
               class="btn btn-sm {{ $filtro === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }}
                <span class="badge text-bg-{{ $filtro === $clave ? 'light' : $color }} ms-1">{{ $valor }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.categorias.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <input type="hidden" name="filtro" value="{{ $filtro }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" value="{{ $termino }}"
                               placeholder="Nombre, clave o descripcion">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.categorias.index', ['filtro' => $filtro]) }}"
                           class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        @if ($categorias->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-tags fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay categorias que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:4rem">Id</th>
                        <th scope="col" style="width:5rem">Color</th>
                        <th scope="col">Nombre</th>
                        <th scope="col" style="width:10rem">Clave</th>
                        <th scope="col" class="text-center" style="width:9rem">Repuestos</th>
                        <th scope="col" class="text-center" style="width:8rem">Estado</th>
                        <th scope="col" class="text-end pe-3" style="width:8rem">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($categorias as $categoria)
                        <tr class="{{ $categoria->col_activo ? '' : 'opacity-50' }}">
                            <td class="ps-3 text-secondary small">{{ $categoria->id }}</td>

                            <td>
                                {{-- El hex viene de la base: es contenido (el color real del
                                     marco de la foto), no una decision de diseno, por eso va
                                     en linea y no como token --ca-*. --}}
                                <span class="chip-color" style="background-color: {{ $categoria->col_color_hex }}"
                                      title="{{ $categoria->col_color_hex }}"></span>
                            </td>

                            <td>
                                <div class="fw-semibold">{{ $categoria->col_nombre }}</div>
                                <div class="small text-secondary">{{ $categoria->col_descripcion ?? 'Sin descripcion' }}</div>
                            </td>

                            <td class="font-monospace small text-secondary">{{ $categoria->col_slug }}</td>

                            <td class="text-center small">
                                {{ $categoria->repuestos_activos_count }}
                                @if ($categoria->repuestos_count > $categoria->repuestos_activos_count)
                                    <span class="text-secondary">/ {{ $categoria->repuestos_count }}</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge text-bg-{{ $categoria->col_activo ? 'success' : 'secondary' }}">
                                    {{ $categoria->col_activo ? 'Activa' : 'Anulada' }}
                                </span>
                            </td>

                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    @if ($puedeEditar)
                                        <a href="{{ route('admin.categorias.edit', $categoria) }}"
                                           class="btn btn-outline-secondary" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @else
                                        @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'pencil'])
                                    @endif
                                    @if ($categoria->col_activo)
                                        @if ($puedeEliminar)
                                            <form method="POST" action="{{ route('admin.categorias.destroy', $categoria) }}"
                                                  data-confirmar="Anular la categoria {{ $categoria->col_nombre }}? No se podra asignar a nuevos repuestos.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger" title="Anular">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                        @else
                                            @include('admin.partials.accion-sin-permiso', ['accion' => 'eliminar', 'icono' => 'slash-circle'])
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-secondary">
                    Mostrando {{ $categorias->firstItem() }}–{{ $categorias->lastItem() }} de {{ $categorias->total() }}
                </small>
                {{ $categorias->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
