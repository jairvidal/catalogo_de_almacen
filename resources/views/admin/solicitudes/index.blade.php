@extends('layouts.admin')

@section('titulo', 'Solicitudes')

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Solicitudes de repuestos</h1>
            <p class="text-secondary mb-0 small">
                Revise el detalle, elabore el pedido y el sistema avisa al solicitante por correo.
            </p>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Metricas por estado                                                --}}
    {{-- Solo en el listado general: en "Listos para reclamar" el estado lo  --}}
    {{-- fija la ruta, asi que estas tarjetas no tendrian a donde filtrar.   --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($mostrarMetricas)
    <div class="row row-cols-2 row-cols-lg-5 g-3 mb-4">
        @php
            $tarjetas = [
                '' => ['Todas', 'inbox', 'estado-todas', $totalGeneral],
                \App\Models\Solicitud::ESTADO_PENDIENTE => ['Pendientes', 'hourglass', 'estado-pendiente', $conteos['pendiente'] ?? 0],
                \App\Models\Solicitud::ESTADO_EN_PROCESO => ['En proceso', 'box-seam', 'estado-en-proceso', $conteos['en_proceso'] ?? 0],
                \App\Models\Solicitud::ESTADO_LISTO => ['Listos', 'check-circle', 'estado-listo', $conteos['listo'] ?? 0],
                \App\Models\Solicitud::ESTADO_ENTREGADA => ['Entregadas', 'bag-check', 'estado-entregada', $conteos['entregada'] ?? 0],
            ];
        @endphp

        @foreach ($tarjetas as $clave => [$etiqueta, $icono, $color, $valor])
            <div class="col">
                {{-- La tarjeta cambia solo el estado: conserva los filtros por columna y el orden. --}}
                <a href="{{ route('admin.solicitudes.index', array_filter(array_merge($consulta, ['estado' => $clave]), fn ($valor) => $valor !== '')) }}"
                   class="text-decoration-none">
                    <div class="tarjeta-metrica p-3 h-100 {{ $estadoActivo === $clave ? 'activa' : '' }}">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-{{ $icono }} text-{{ $color }}"></i>
                            <span class="small text-secondary">{{ $etiqueta }}</span>
                        </div>
                        <div class="valor text-dark">{{ $valor }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Listado con orden y filtros por columna                            --}}
    {{--                                                                    --}}
    {{-- Todo lo que decide viene del controlador ya depurado ($filtros,     --}}
    {{-- $estadoActivo, $estadoFijo, $orden, $direccion, $consulta): aqui no --}}
    {{-- se lee el request. Los cuadros de la fila de filtros van dentro de  --}}
    {{-- la tabla y se asocian al formulario con el atributo form.           --}}
    {{-- ------------------------------------------------------------------ --}}
    @php
        // null = columna sin orden ni filtro (RECIBIDA y ACCION).
        $columnas = [
            'numero' => ['Numero', 'ps-3'],
            'solicitante' => ['Solicitante', ''],
            'items' => ['Items', 'text-center'],
            'estado' => ['Estado', ''],
            'recibida' => ['Recibida', null],
            'atendida' => ['Atendida por', ''],
            'accion' => ['Accion', null],
        ];

        $hayFiltros = collect($filtros)->contains(fn ($valor) => $valor !== '')
            || ($estadoFijo === null && $estadoActivo !== '');

        // Limpiar quita los filtros pero conserva el orden elegido.
        $consultaOrden = array_intersect_key($consulta, array_flip(['orden', 'direccion']));
    @endphp

    <form id="filtros-solicitudes" method="GET" action="{{ route($rutaListado) }}"
          role="search" aria-label="Filtros de solicitudes">
        @foreach ($parametrosBase as $nombre => $valor)
            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
        @endforeach
        @if ($orden !== null)
            <input type="hidden" name="orden" value="{{ $orden }}">
            <input type="hidden" name="direccion" value="{{ $direccion }}">
        @endif
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    @foreach ($columnas as $clave => [$titulo, $clase])
                        @if ($clase === null)
                            <th scope="col" class="{{ $clave === 'accion' ? 'text-end pe-3' : '' }}">{{ $titulo }}</th>
                        @else
                            @php
                                $activa = $orden === $clave;
                                $siguiente = $activa && $direccion === 'asc' ? 'desc' : 'asc';
                                $enlace = route($rutaListado, array_merge($parametrosBase, $consulta, [
                                    'orden' => $clave,
                                    'direccion' => $siguiente,
                                ]));
                            @endphp
                            <th scope="col" class="{{ $clase }}"
                                aria-sort="{{ $activa ? ($direccion === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <a href="{{ $enlace }}" class="orden-columna"
                                   aria-label="Ordenar por {{ strtolower($titulo) }}, {{ $siguiente === 'asc' ? 'ascendente' : 'descendente' }}">
                                    <span>{{ $titulo }}</span>
                                    <span class="flechas-orden" aria-hidden="true">
                                        <i class="bi bi-caret-up-fill {{ $activa && $direccion === 'asc' ? 'activa' : '' }}"></i>
                                        <i class="bi bi-caret-down-fill {{ $activa && $direccion === 'desc' ? 'activa' : '' }}"></i>
                                    </span>
                                </a>
                            </th>
                        @endif
                    @endforeach
                </tr>
                <tr class="fila-filtros">
                    <td class="ps-3">
                        <label for="filtro-numero" class="visually-hidden">Filtrar por numero</label>
                        <input type="search" id="filtro-numero" name="numero" form="filtros-solicitudes"
                               class="form-control form-control-sm filtro-columna"
                               value="{{ $filtros['numero'] }}" placeholder="000004" autocomplete="off" data-autofiltrar>
                    </td>
                    <td>
                        <label for="filtro-solicitante" class="visually-hidden">Filtrar por solicitante: nombre, cedula o area</label>
                        <input type="search" id="filtro-solicitante" name="solicitante" form="filtros-solicitudes"
                               class="form-control form-control-sm filtro-columna"
                               value="{{ $filtros['solicitante'] }}" placeholder="Nombre, cedula o area" autocomplete="off" data-autofiltrar>
                    </td>
                    <td>
                        <label for="filtro-items" class="visually-hidden">Filtrar por cantidad de items</label>
                        <input type="search" id="filtro-items" name="items" form="filtros-solicitudes"
                               class="form-control form-control-sm filtro-columna-corto text-center"
                               value="{{ $filtros['items'] }}" inputmode="numeric" autocomplete="off" data-autofiltrar>
                    </td>
                    <td>
                        <label for="filtro-estado" class="visually-hidden">Filtrar por estado</label>
                        @if ($estadoFijo !== null)
                            {{-- En Listos el estado lo fija la ruta: el select solo lo muestra. --}}
                            <select id="filtro-estado" class="form-select form-select-sm filtro-columna" disabled>
                                <option>{{ \App\Models\Solicitud::ESTADOS[$estadoFijo]['label'] }}</option>
                            </select>
                        @else
                            <select id="filtro-estado" name="estado" form="filtros-solicitudes"
                                    class="form-select form-select-sm filtro-columna" data-autoenviar>
                                <option value="">Todos</option>
                                @foreach (\App\Models\Solicitud::ESTADOS as $claveEstado => $datosEstado)
                                    <option value="{{ $claveEstado }}" @selected($estadoActivo === $claveEstado)>
                                        {{ $datosEstado['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </td>
                    <td></td>
                    <td>
                        <label for="filtro-atendida" class="visually-hidden">Filtrar por quien atendio</label>
                        <input type="search" id="filtro-atendida" name="atendida" form="filtros-solicitudes"
                               class="form-control form-control-sm filtro-columna"
                               value="{{ $filtros['atendida'] }}" placeholder="Usuario" autocomplete="off" data-autofiltrar>
                    </td>
                    <td class="text-end pe-3 text-nowrap">
                        {{-- Los cuadros filtran solos al dejar de escribir (data-autofiltrar en app.js); --}}
                        {{-- el boton queda para Enter y como respaldo sin JavaScript.                   --}}
                        <button type="submit" form="filtros-solicitudes" class="btn btn-sm btn-marca"
                                title="Aplicar filtros">
                            <i class="bi bi-funnel"></i><span class="visually-hidden">Aplicar filtros</span>
                        </button>
                        @if ($hayFiltros)
                            <a href="{{ route($rutaListado, array_merge($parametrosBase, $consultaOrden)) }}"
                               class="btn btn-sm btn-outline-secondary" title="Quitar filtros">
                                <i class="bi bi-x-lg"></i><span class="visually-hidden">Quitar filtros</span>
                            </a>
                        @endif
                    </td>
                </tr>
                </thead>
                <tbody>
                @forelse ($solicitudes as $solicitud)
                    <tr>
                        <td class="ps-3">
                            <a href="{{ route('admin.solicitudes.show', $solicitud) }}"
                               class="font-monospace fw-semibold text-decoration-none">
                                {{ $solicitud->numero }}
                            </a>
                        </td>

                        <td>
                            <div class="fw-semibold">{{ $solicitud->solicitante_nombre }}</div>
                            <div class="small text-secondary">
                                {{-- La cedula puede faltar: el ERP no siempre la trae. --}}
                                {{ collect([
                                    $solicitud->solicitante_cedula ? 'CC '.$solicitud->solicitante_cedula : null,
                                    $solicitud->solicitante_area,
                                ])->filter()->implode(' · ') }}
                            </div>
                        </td>

                        <td class="text-center">
                            <span class="badge text-bg-light border">{{ $solicitud->items_count }}</span>
                        </td>

                        <td>
                            <span class="badge text-bg-{{ $solicitud->estado_color }}">
                                <i class="bi bi-{{ $solicitud->estado_icono }} me-1"></i>{{ $solicitud->estado_label }}
                            </span>

                            @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_LISTO && ! $solicitud->notificado_at)
                                <div class="small text-danger mt-1">
                                    <i class="bi bi-envelope-exclamation me-1"></i>Correo no enviado
                                </div>
                            @endif
                        </td>

                        <td class="small text-secondary">
                            {{ $solicitud->created_at->format('d/m/Y') }}<br>
                            {{ $solicitud->created_at->format('h:i a') }}
                        </td>

                        <td class="small text-secondary">
                            {{ $solicitud->atendidaPor?->name ?? '—' }}
                        </td>

                        <td class="text-end pe-3">
                            <a href="{{ route('admin.solicitudes.show', $solicitud) }}"
                               class="btn btn-sm {{ $solicitud->estado === 'pendiente' ? 'btn-marca' : 'btn-outline-secondary' }}">
                                {{ $solicitud->estado === 'pendiente' ? 'Atender' : 'Ver detalle' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    {{-- La tabla se queda aunque no haya filas: la fila de filtros --}}
                    {{-- tiene que seguir a mano para corregir el filtro.           --}}
                    <tr>
                        <td colspan="{{ count($columnas) }}" class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-secondary opacity-50"></i>
                            <p class="text-secondary mt-3 mb-0">No hay solicitudes que coincidan con el filtro.</p>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($solicitudes->isNotEmpty())
            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-secondary">
                    Mostrando {{ $solicitudes->firstItem() }}–{{ $solicitudes->lastItem() }}
                    de {{ $solicitudes->total() }}
                </small>
                {{ $solicitudes->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
