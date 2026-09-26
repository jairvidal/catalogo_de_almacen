@extends('layouts.admin')

@section('titulo', 'Solicitantes del ERP')

@use('App\Models\Funcionalidad')

@php
    // Asignar o restablecer la contrasena es editar. Sin permiso el boton sale
    // gris y deshabilitado; la ruta lo vuelve a negar con el middleware permiso.
    $puedeEditar = auth()->user()->puede(Funcionalidad::SOLICITANTES, Funcionalidad::ACCION_EDITAR);
@endphp

@section('contenido')

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h4 mb-1">Solicitantes del ERP</h1>
            <p class="text-secondary mb-0 small">
                Personas que aprueban las solicitudes de repuestos. Los datos llegan del ERP; aqui solo se
                asigna la contrasena del portal, que el sistema genera y envia a su correo sin mostrarla.
            </p>
        </div>
    </div>

    {{-- Filtros rapidos --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $pastillas = [
                '' => ['Todos', $totales['todos'], 'dark'],
                'activos' => ['Activos', $totales['activos'], 'success'],
                'inactivos' => ['Inactivos', $totales['inactivos'], 'secondary'],
                'con_contrasena' => ['Con contrasena', $totales['con_contrasena'], 'success'],
                'sin_contrasena' => ['Sin contrasena', $totales['sin_contrasena'], 'secondary'],
            ];
        @endphp

        @foreach ($pastillas as $clave => [$etiqueta, $valor, $color])
            <a href="{{ route('admin.solicitantes.index', array_filter(['filtro' => $clave, 'q' => $termino])) }}"
               class="btn btn-sm {{ $filtro === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }}
                <span class="badge text-bg-{{ $filtro === $clave ? 'light' : $color }} ms-1">{{ $valor }}</span>
            </a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('admin.solicitantes.index') }}" class="card border-0 shadow-sm mb-3"
          role="search" aria-label="Buscar solicitantes">
        <div class="card-body py-3">
            <input type="hidden" name="filtro" value="{{ $filtro }}">
            <div class="row g-2">
                <div class="col-md-9">
                    <label for="buscar-solicitante" class="visually-hidden">Buscar por nombre, codigo, cedula, correo o area</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="buscar-solicitante" name="q" value="{{ $termino }}"
                               placeholder="Nombre, codigo, cedula, correo o area" maxlength="100">
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-marca flex-grow-1">Buscar</button>
                    @if ($termino !== '')
                        <a href="{{ route('admin.solicitantes.index', array_filter(['filtro' => $filtro])) }}"
                           class="btn btn-outline-secondary" title="Quitar busqueda">
                            <i class="bi bi-x-lg"></i><span class="visually-hidden">Quitar busqueda</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        @if ($solicitantes->isEmpty())
            <div class="card-body text-center py-5">
                <i class="bi bi-person-lines-fill fs-1 text-secondary opacity-50"></i>
                <p class="text-secondary mt-3 mb-0">No hay solicitantes que coincidan con el filtro.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="ps-3" style="width:7rem">Codigo</th>
                        <th scope="col">Nombre</th>
                        <th scope="col" style="width:9rem">Cedula</th>
                        <th scope="col">Correo</th>
                        <th scope="col">Area</th>
                        <th scope="col" class="text-center" style="width:7rem">Estado</th>
                        <th scope="col" style="width:10rem">Contrasena</th>
                        <th scope="col" class="text-end pe-3" style="width:12rem">Accion</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($solicitantes as $solicitante)
                        @php
                            $correo = $solicitante->correoNormalizado();
                            $motivo = $solicitante->motivoSinAcceso(
                                $correo !== null && in_array($correo, $correosCompartidos, true)
                            );
                            $idMotivo = 'motivo-sin-acceso-'.$solicitante->id;
                        @endphp
                        <tr class="{{ $solicitante->col_activo ? '' : 'opacity-50' }}">
                            <td class="ps-3 font-monospace small">{{ $solicitante->col_codigo_erp }}</td>

                            <td class="fw-semibold">{{ $solicitante->col_nombre }}</td>

                            <td class="small">{{ $solicitante->col_cedula ?? '—' }}</td>

                            <td class="small text-break">
                                {{ $solicitante->col_correo ?? '' }}
                                @if ($motivo !== null)
                                    <div id="{{ $idMotivo }}" class="text-secondary mt-1">
                                        <i class="bi bi-info-circle me-1"></i>{{ $motivo }}
                                    </div>
                                @endif
                            </td>

                            <td class="small text-secondary">{{ $solicitante->col_area ?? '—' }}</td>

                            <td class="text-center">
                                <span class="badge text-bg-{{ $solicitante->col_activo ? 'success' : 'secondary' }}">
                                    {{ $solicitante->col_activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>

                            <td class="small">
                                @if ($solicitante->tieneContrasena())
                                    <i class="bi bi-key-fill text-success me-1"></i>Asignada
                                    <div class="text-secondary">{{ $solicitante->col_password_asignada_at->format('d/m/Y') }}</div>
                                    @if ($solicitante->col_password_cambiada_at)
                                        <div class="text-secondary">Cambiada {{ $solicitante->col_password_cambiada_at->format('d/m/Y') }}</div>
                                    @endif
                                @else
                                    <span class="text-secondary"><i class="bi bi-dash-circle me-1"></i>Sin asignar</span>
                                @endif
                            </td>

                            <td class="text-end pe-3">
                                @php
                                    $textoBoton = $solicitante->tieneContrasena() ? 'Restablecer' : 'Asignar contrasena';
                                @endphp
                                @if (! $puedeEditar)
                                    @include('admin.partials.accion-sin-permiso', ['accion' => 'editar', 'icono' => 'key', 'texto' => $textoBoton, 'clases' => 'btn-sm btn-outline-secondary'])
                                @elseif ($motivo !== null)
                                    {{-- No es falta de permiso sino de datos del ERP: se explica al lado del correo. --}}
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                            aria-describedby="{{ $idMotivo }}" title="{{ $motivo }}">
                                        <i class="bi bi-key me-1"></i>{{ $textoBoton }}
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('admin.solicitantes.contrasena', $solicitante) }}"
                                          data-confirmar="{{ $solicitante->tieneContrasena()
                                              ? "Se generara una contrasena NUEVA para {$solicitante->col_nombre} y se enviara a {$correo}. La actual dejara de funcionar. Continuar?"
                                              : "Se generara una contrasena para {$solicitante->col_nombre} y se enviara a {$correo}. Continuar?" }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-marca">
                                            <i class="bi bi-key me-1"></i>{{ $textoBoton }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-secondary">
                    Mostrando {{ $solicitantes->firstItem() }}–{{ $solicitantes->lastItem() }} de {{ $solicitantes->total() }}
                </small>
                {{ $solicitantes->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

@endsection
