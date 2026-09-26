@extends('layouts.app')

@section('titulo', 'Mis solicitudes por aprobar')

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Solicitudes a su nombre</h1>
            <p class="text-secondary mb-0">
                {{ $solicitante->col_nombre }}. Apruebe o deniegue las que esperan su decision:
                solo las aprobadas pasan al almacen.
            </p>
        </div>
    </div>

    {{-- Filtro: todas o solo las que esperan decision. --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('solicitante.solicitudes.index') }}"
           class="btn btn-sm {{ $filtro === '' ? 'btn-dark' : 'btn-outline-secondary' }}">
            Todas
        </a>
        <a href="{{ route('solicitante.solicitudes.index', ['filtro' => \App\Models\Solicitud::ESTADO_POR_APROBAR]) }}"
           class="btn btn-sm {{ $filtro !== '' ? 'btn-dark' : 'btn-outline-secondary' }}">
            Por aprobar
            <span class="badge {{ $filtro !== '' ? 'text-bg-light' : 'text-bg-warning' }} ms-1">{{ $porAprobar }}</span>
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        @if ($solicitudes->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-inbox fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">
                    {{ $filtro !== '' ? 'No tiene solicitudes esperando su aprobacion.' : 'No hay solicitudes a su nombre.' }}
                </p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3">Numero</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Solicitada por</th>
                        <th scope="col" class="text-center">Items</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end pe-3">Accion</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($solicitudes as $solicitud)
                        <tr class="{{ $solicitud->esta_por_aprobar ? 'fila-por-aprobar' : '' }}">
                            <td class="ps-3 font-monospace fw-semibold">{{ $solicitud->numero }}</td>
                            <td class="small text-secondary text-nowrap">
                                {{ $solicitud->created_at->format('d/m/Y') }}<br>
                                {{ $solicitud->created_at->format('h:i a') }}
                            </td>
                            {{-- Las historicas no tienen nombre completo: se muestra el del ERP. --}}
                            <td>{{ $solicitud->nombre_completo ?? $solicitud->solicitante_nombre }}</td>
                            <td class="text-center">
                                <span class="badge text-bg-light border">{{ $solicitud->items_count }}</span>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $solicitud->estado_color }}">
                                    <i class="bi bi-{{ $solicitud->estado_icono }} me-1"></i>{{ $solicitud->estado_label }}
                                </span>
                            </td>
                            <td class="text-end pe-3">
                                <a href="{{ route('solicitante.solicitudes.show', $solicitud->id) }}"
                                   class="btn btn-sm {{ $solicitud->esta_por_aprobar ? 'btn-marca' : 'btn-outline-secondary' }}">
                                    {{ $solicitud->esta_por_aprobar ? 'Revisar y decidir' : 'Ver detalle' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-secondary">
                    Mostrando {{ $solicitudes->firstItem() }}–{{ $solicitudes->lastItem() }} de {{ $solicitudes->total() }}
                </small>
                {{ $solicitudes->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
