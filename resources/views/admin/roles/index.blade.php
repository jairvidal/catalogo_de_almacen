@extends('layouts.admin')

@section('titulo', 'Roles')

@use('App\Models\Funcionalidad')

@php
    // Lo que el perfil no puede hacer se pinta en gris y deshabilitado; la
    // ruta vuelve a negarlo con el middleware permiso.
    $puedeEditar = auth()->user()->puede(Funcionalidad::ROLES, Funcionalidad::ACCION_EDITAR);
    $puedeEliminar = auth()->user()->puede(Funcionalidad::ROLES, Funcionalidad::ACCION_ELIMINAR);
@endphp

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Roles</h1>
            <p class="text-secondary mb-0 small">
                Define que puede hacer cada usuario del panel. Los roles del sistema no se anulan.
            </p>
        </div>
        @if ($puedeEditar)
            <a href="{{ route('admin.roles.create') }}" class="btn btn-marca">
                <i class="bi bi-plus-lg me-1"></i>Nuevo rol
            </a>
        @else
            @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'plus-lg', 'texto' => 'Nuevo rol', 'clases' => 'btn-outline-secondary'])
        @endif
    </div>

    {{-- Filtros rapidos --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $filtros = [
                '' => ['Todos', $totales['todos'], 'dark'],
                'activos' => ['Activos', $totales['activos'], 'success'],
                'anulados' => ['Anulados', $totales['anulados'], 'secondary'],
            ];
        @endphp

        @foreach ($filtros as $clave => [$etiqueta, $valor, $color])
            <a href="{{ route('admin.roles.index', array_filter(['filtro' => $clave, 'q' => $termino])) }}"
               class="btn btn-sm {{ $filtro === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }}
                <span class="badge text-bg-{{ $filtro === $clave ? 'light' : $color }} ms-1">{{ $valor }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.roles.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <input type="hidden" name="filtro" value="{{ $filtro }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" value="{{ $termino }}"
                               placeholder="Clave, nombre o descripcion">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.roles.index', ['filtro' => $filtro]) }}"
                           class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        @if ($roles->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-person-badge fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay roles que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:4rem">Id</th>
                        <th scope="col" style="width:10rem">Clave</th>
                        <th scope="col">Nombre</th>
                        <th scope="col" class="text-center" style="width:10rem">Gestiona catalogo</th>
                        <th scope="col" class="text-center" style="width:8rem">Usuarios</th>
                        <th scope="col" class="text-center" style="width:8rem">Estado</th>
                        <th scope="col" class="text-end pe-3" style="width:8rem">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($roles as $rol)
                        <tr class="{{ $rol->col_activo ? '' : 'opacity-50' }}">
                            <td class="ps-3 text-secondary small">{{ $rol->id }}</td>

                            <td class="font-monospace small">{{ $rol->col_clave }}</td>

                            <td>
                                <div class="fw-semibold">
                                    {{ $rol->col_nombre }}
                                    @if ($rol->es_del_sistema)
                                        <span class="badge text-bg-light border text-secondary fw-normal ms-1"
                                              title="Rol base del panel">Sistema</span>
                                    @endif
                                </div>
                                <div class="small text-secondary">{{ $rol->col_descripcion ?? 'Sin descripcion' }}</div>
                            </td>

                            <td class="text-center">
                                @if ($rol->col_gestiona_catalogo)
                                    <i class="bi bi-check-circle-fill text-success" title="Si"></i>
                                @else
                                    <i class="bi bi-dash-circle text-secondary" title="No"></i>
                                @endif
                            </td>

                            <td class="text-center small">
                                {{ $rol->usuarios_activos_count }}
                                @if ($rol->usuarios_count > $rol->usuarios_activos_count)
                                    <span class="text-secondary">/ {{ $rol->usuarios_count }}</span>
                                @endif
                            </td>

                            <td class="text-center">
                                <span class="badge text-bg-{{ $rol->col_activo ? 'success' : 'secondary' }}">
                                    {{ $rol->col_activo ? 'Activo' : 'Anulado' }}
                                </span>
                            </td>

                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    @if ($puedeEditar)
                                        <a href="{{ route('admin.roles.edit', $rol) }}"
                                           class="btn btn-outline-secondary" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @else
                                        @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'pencil'])
                                    @endif
                                    @if ($rol->col_activo && ! $rol->es_del_sistema)
                                        @if ($puedeEliminar)
                                            <form method="POST" action="{{ route('admin.roles.destroy', $rol) }}"
                                                  data-confirmar="Anular el rol {{ $rol->col_nombre }}? No se podra asignar a nuevos usuarios.">
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
                    Mostrando {{ $roles->firstItem() }}–{{ $roles->lastItem() }} de {{ $roles->total() }}
                </small>
                {{ $roles->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
