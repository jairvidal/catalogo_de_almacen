@extends('layouts.admin')

@section('titulo', 'Catalogo e inventario')

@use('App\Models\Funcionalidad')

@php
    // Lo que el perfil no puede hacer se pinta en gris y deshabilitado; la
    // ruta vuelve a negarlo con el middleware permiso.
    $puedeEditar = auth()->user()->puede(Funcionalidad::REPUESTOS, Funcionalidad::ACCION_EDITAR);
    $puedeEliminar = auth()->user()->puede(Funcionalidad::REPUESTOS, Funcionalidad::ACCION_ELIMINAR);
@endphp

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Catalogo e inventario</h1>
            <p class="text-secondary mb-0 small">Administre los repuestos, sus fotos y las existencias.</p>
        </div>
        @if ($puedeEditar)
            <a href="{{ route('admin.repuestos.create') }}" class="btn btn-marca">
                <i class="bi bi-plus-lg me-1"></i>Nuevo repuesto
            </a>
        @else
            @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'plus-lg', 'texto' => 'Nuevo repuesto', 'clases' => 'btn-outline-secondary'])
        @endif
    </div>

    {{-- Filtros rapidos --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $filtros = [
                '' => ['Todos', $totales['todos'], 'dark'],
                'bajos' => ['Existencias bajas', $totales['bajos'], 'warning'],
                'agotados' => ['Agotados', $totales['agotados'], 'danger'],
                'inactivos' => ['Inactivos', $totales['inactivos'], 'secondary'],
                // Verde y no rojo: un exceso no es una averia de despacho como
                // el agotado, y los dos rojos ya estan tomados por la marca y
                // por lo destructivo.
                'sobre_stock' => ['Sobre stock', $totales['sobre_stock'], 'success'],
            ];
        @endphp

        @foreach ($filtros as $clave => [$etiqueta, $valor, $color])
            <a href="{{ route('admin.repuestos.index', array_filter(['filtro' => $clave, 'q' => $termino])) }}"
               class="btn btn-sm {{ $filtro === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }}
                <span class="badge text-bg-{{ $filtro === $clave ? 'light' : $color }} ms-1">{{ $valor }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.repuestos.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <input type="hidden" name="filtro" value="{{ $filtro }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" value="{{ $termino }}"
                               placeholder="Codigo, nombre, descripcion o categoria">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.repuestos.index', ['filtro' => $filtro]) }}"
                           class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        @if ($repuestos->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-box fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay repuestos que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:5.5rem">Foto</th>
                        <th scope="col" style="width:4rem">Id</th>
                        <th scope="col" style="width:8rem">Codigo</th>
                        <th scope="col">Nombre</th>
                        <th scope="col" style="width:9rem">Categoria</th>
                        <th scope="col" class="text-center" style="width:11rem">Existencias</th>
                        <th scope="col" class="text-end pe-3" style="width:9rem">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($repuestos as $repuesto)
                        <tr class="{{ $repuesto->estaActivo() ? '' : 'opacity-50' }}">
                            <td class="ps-3">
                                <img src="{{ $repuesto->foto_url }}" alt="{{ $repuesto->nombre }}" class="miniatura">
                            </td>

                            <td class="text-secondary small">{{ $repuesto->id }}</td>

                            <td class="font-monospace small">{{ $repuesto->codigo }}</td>

                            <td>
                                <div class="fw-semibold">{{ $repuesto->nombre }}</div>
                                <div class="small text-secondary">
                                    {{ $repuesto->ubicacion ?? 'Sin ubicacion' }}
                                    @unless ($repuesto->estaActivo())
                                        <span class="badge text-bg-secondary ms-1">Inactivo</span>
                                    @endunless
                                </div>
                            </td>

                            <td class="small text-secondary">{{ $repuesto->desc_cat_1 ?? '—' }}</td>

                            <td>
                                @if ($puedeEditar)
                                    <form method="POST" action="{{ route('admin.repuestos.stock', $repuesto) }}"
                                          class="d-flex gap-1 justify-content-center">
                                        @csrf
                                        @method('PATCH')
                                        <input type="number" name="existencia"
                                               class="form-control form-control-sm text-center
                                                      {{ $repuesto->sin_stock ? 'border-danger' : ($repuesto->stock_bajo ? 'border-warning' : '') }}"
                                               style="width:5.5rem"
                                               value="{{ $repuesto->existencia }}" min="0" step="0.001" required>
                                        <button type="submit" class="btn btn-sm btn-outline-marca" title="Guardar existencias">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                @else
                                    <div class="d-flex gap-1 justify-content-center">
                                        <input type="number" class="form-control form-control-sm text-center"
                                               style="width:5.5rem" value="{{ $repuesto->existencia }}" disabled
                                               aria-label="Existencias de {{ $repuesto->codigo }}">
                                        @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'check-lg', 'clases' => 'btn-sm btn-outline-secondary'])
                                    </div>
                                @endif
                                <div class="text-center small text-secondary mt-1">
                                    {{ $repuesto->unidad_medida }} &middot; min {{ $repuesto->stock_minimo }}
                                </div>
                            </td>

                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('catalogo.show', $repuesto) }}" target="_blank" rel="noopener"
                                       class="btn btn-outline-secondary" title="Ver en el catalogo">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if ($puedeEditar)
                                        <a href="{{ route('admin.repuestos.edit', $repuesto) }}"
                                           class="btn btn-outline-secondary" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @else
                                        @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'pencil'])
                                    @endif
                                    @if ($repuesto->estaActivo())
                                        @if ($puedeEliminar)
                                            <form method="POST" action="{{ route('admin.repuestos.destroy', $repuesto) }}"
                                                  data-confirmar="Desactivar {{ $repuesto->codigo }}? Dejara de aparecer en el catalogo publico.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger" title="Desactivar">
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
                    Mostrando {{ $repuestos->firstItem() }}–{{ $repuestos->lastItem() }} de {{ $repuestos->total() }}
                </small>
                {{ $repuestos->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
