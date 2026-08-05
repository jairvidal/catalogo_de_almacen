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
    {{-- ------------------------------------------------------------------ --}}
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
                <a href="{{ route('admin.solicitudes.index', array_filter(['estado' => $clave, 'q' => $termino])) }}"
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

    {{-- ------------------------------------------------------------------ --}}
    {{-- Buscador                                                           --}}
    {{-- ------------------------------------------------------------------ --}}
    <form method="GET" action="{{ route('admin.solicitudes.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <input type="hidden" name="estado" value="{{ $estadoActivo }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" name="q" value="{{ $termino }}"
                               placeholder="Numero de solicitud, nombre, cedula o correo del solicitante">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.solicitudes.index', ['estado' => $estadoActivo]) }}"
                           class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Listado                                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="card border-0 shadow-sm">
        @if ($solicitudes->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-inbox fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay solicitudes que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3">Numero</th>
                        <th scope="col">Solicitante</th>
                        <th scope="col" class="text-center">Items</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Recibida</th>
                        <th scope="col">Atendida por</th>
                        <th scope="col" class="text-end pe-3">Accion</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($solicitudes as $solicitud)
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
                                    CC {{ $solicitud->solicitante_cedula }}
                                    @if ($solicitud->solicitante_area)
                                        &middot; {{ $solicitud->solicitante_area }}
                                    @endif
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
                    @endforeach
                    </tbody>
                </table>
            </div>

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
