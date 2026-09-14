@extends('layouts.admin')

@section('titulo', 'Parametros')

@use('App\Models\Funcionalidad')

@php
    // Lo que el perfil no puede hacer se pinta en gris y deshabilitado; la
    // ruta vuelve a negarlo con el middleware permiso.
    $puedeEditar = auth()->user()->puede(Funcionalidad::PARAMETROS, Funcionalidad::ACCION_EDITAR);
    $puedeEliminar = auth()->user()->puede(Funcionalidad::PARAMETROS, Funcionalidad::ACCION_ELIMINAR);
@endphp

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Parametros</h1>
            <p class="text-secondary mb-0 small">
                Configuracion que el sistema lee en caliente. Los parametros del sistema no se anulan.
            </p>
        </div>
        @if ($puedeEditar)
            <a href="{{ route('admin.parametros.create') }}" class="btn btn-marca">
                <i class="bi bi-plus-lg me-1"></i>Nuevo parametro
            </a>
        @else
            @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'plus-lg', 'texto' => 'Nuevo parametro', 'clases' => 'btn-outline-secondary'])
        @endif
    </div>

    {{-- Estado de la sincronizacion de stock con el ERP.
         El modo lo manda el parametro inv.actualizar: en automatico lo dispara
         la tarea programada, en manual solo el boton de aqui. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h2 class="h6 mb-2">
                    <i class="bi bi-arrow-repeat me-1"></i>Actualizacion del inventario desde el ERP
                    <span class="badge text-bg-{{ $sincronizacion['es_manual'] ? 'secondary' : 'success' }} ms-1">
                        {{ \App\Models\Parametro::OPCIONES[\App\Models\Parametro::INV_ACTUALIZAR][$sincronizacion['modo']] }}
                    </span>
                </h2>

                <p class="small text-secondary mb-1">
                    @if ($sincronizacion['es_manual'])
                        La tarea programada esta apagada: el stock solo se actualiza con el boton "Actualizar".
                    @else
                        La tarea programada la dispara sola cada {{ $sincronizacion['minutos'] }} minuto(s).
                        Necesita <code>php artisan schedule:work</code> corriendo en el servidor.
                    @endif
                </p>

                {{-- Las horas son de Colombia. El nodo marcado lo reescribe
                     app.js con la fecha que devuelve el boton, para que el
                     administrador vea moverse la marca sin recargar. --}}
                <p class="small text-secondary mb-0">
                    <span data-sincronizar-ultima>
                        @if ($sincronizacion['ultima'])
                            Ultima corrida: {{ $sincronizacion['ultima'] }}.
                        @else
                            Todavia no ha corrido ninguna vez.
                        @endif
                    </span>
                    @if ($sincronizacion['ultima'] && ! $sincronizacion['es_manual'])
                        Proxima: {{ $sincronizacion['proxima'] }}.
                    @endif
                </p>

                @if ($sincronizacion['en_curso'])
                    <p class="small text-marca mb-0 mt-2" data-sincronizar-en-curso>
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Sincronizacion en curso...
                    </p>
                @endif
            </div>

            {{-- El boton solo existe en modo manual; el servidor lo vuelve a
                 comprobar antes de sincronizar. --}}
            @if ($sincronizacion['es_manual'])
                @if ($puedeEditar)
                    <form method="POST" action="{{ route('admin.parametros.sincronizar') }}" data-sincronizar-stock>
                        @csrf
                        <button type="submit" class="btn btn-marca">
                            <i class="bi bi-arrow-repeat me-1"></i>Actualizar
                        </button>
                    </form>
                @else
                    @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'arrow-repeat', 'texto' => 'Actualizar', 'clases' => 'btn-outline-secondary'])
                @endif
            @endif
        </div>
    </div>

    {{-- Filtros rapidos --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $filtros = [
                '' => ['Todos', $totales['todos'], 'dark'],
                'activos' => ['Activos', $totales['activos'], 'success'],
                'inactivos' => ['Inactivos', $totales['inactivos'], 'secondary'],
            ];
        @endphp

        @foreach ($filtros as $clave => [$etiqueta, $valor, $color])
            <a href="{{ route('admin.parametros.index', array_filter(['filtro' => $clave, 'q' => $termino])) }}"
               class="btn btn-sm {{ $filtro === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }}
                <span class="badge text-bg-{{ $filtro === $clave ? 'light' : $color }} ms-1">{{ $valor }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.parametros.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <input type="hidden" name="filtro" value="{{ $filtro }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" value="{{ $termino }}"
                               placeholder="Nombre o descripcion">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.parametros.index', ['filtro' => $filtro]) }}"
                           class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        @if ($parametros->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-sliders fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay parametros que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:4rem">Id</th>
                        <th scope="col" style="width:16rem">Nombre</th>
                        <th scope="col" style="width:14rem">Valor</th>
                        <th scope="col">Descripcion</th>
                        <th scope="col" class="text-center" style="width:8rem">Estado</th>
                        <th scope="col" class="text-end pe-3" style="width:8rem">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($parametros as $parametro)
                        <tr class="{{ $parametro->es_activo ? '' : 'opacity-50' }}">
                            <td class="ps-3 text-secondary small">{{ $parametro->id }}</td>

                            <td>
                                <div class="font-monospace small fw-semibold">{{ $parametro->col_nombre }}</div>
                                @if ($parametro->es_del_sistema)
                                    <span class="badge text-bg-light border text-secondary fw-normal"
                                          title="Parametro base de la integracion">Sistema</span>
                                @endif
                            </td>

                            {{-- El valor de un parametro sensible no se imprime: lo que sale
                                 al HTML del listado queda en el historial del navegador. --}}
                            <td class="font-monospace small text-break">
                                {{ $parametro->valor_visible }}
                                @if ($parametro->es_sensible)
                                    <i class="bi bi-shield-lock ms-1 text-secondary"
                                       title="Credencial: el valor no se muestra"></i>
                                @endif
                            </td>

                            <td class="small text-secondary">
                                {{ $parametro->col_descripcion ?? 'Sin descripcion' }}
                            </td>

                            <td class="text-center">
                                <span class="badge text-bg-{{ $parametro->es_activo ? 'success' : 'secondary' }}">
                                    {{ \App\Models\Parametro::ESTADOS[$parametro->col_estado] ?? $parametro->col_estado }}
                                </span>
                            </td>

                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    @if ($puedeEditar)
                                        <a href="{{ route('admin.parametros.edit', $parametro) }}"
                                           class="btn btn-outline-secondary" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @else
                                        @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'pencil'])
                                    @endif
                                    @if ($parametro->es_activo && ! $parametro->es_del_sistema)
                                        @if ($puedeEliminar)
                                            <form method="POST" action="{{ route('admin.parametros.destroy', $parametro) }}"
                                                  data-confirmar="Anular el parametro {{ $parametro->col_nombre }}? El sistema dejara de leerlo.">
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
                    Mostrando {{ $parametros->firstItem() }}–{{ $parametros->lastItem() }} de {{ $parametros->total() }}
                </small>
                {{ $parametros->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
