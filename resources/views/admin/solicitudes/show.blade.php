@extends('layouts.admin')

@section('titulo', 'Solicitud '.$solicitud->numero)

@section('contenido')

    @php
        $puedeElaborar = ! $solicitud->esta_cerrada
            && $solicitud->estado !== \App\Models\Solicitud::ESTADO_LISTO;
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <a href="{{ route('admin.solicitudes.index') }}" class="text-decoration-none small no-imprimir">
                <i class="bi bi-arrow-left me-1"></i>Volver a solicitudes
            </a>
            <h1 class="h4 mt-2 mb-1">
                <span class="font-monospace">{{ $solicitud->numero }}</span>
                <span class="badge text-bg-{{ $solicitud->estado_color }} align-middle ms-2">
                    <i class="bi bi-{{ $solicitud->estado_icono }} me-1"></i>{{ $solicitud->estado_label }}
                </span>
            </h1>
            <p class="text-secondary small mb-0">
                Recibida el {{ $solicitud->created_at->format('d/m/Y \a \l\a\s h:i a') }}
                @if ($solicitud->atendidaPor)
                    &middot; atendida por {{ $solicitud->atendidaPor->name }}
                @endif
            </p>
        </div>

        <div class="d-flex gap-2 no-imprimir">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir orden
            </button>

            @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_PENDIENTE)
                <form method="POST" action="{{ route('admin.solicitudes.tomar', $solicitud) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-marca">
                        <i class="bi bi-play-circle me-1"></i>Marcar en proceso
                    </button>
                </form>
            @endif

            @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_LISTO)
                <form method="POST" action="{{ route('admin.solicitudes.entregar', $solicitud) }}"
                      data-confirmar="Confirma que la persona ya reclamo el pedido?">
                    @csrf
                    <button type="submit" class="btn btn-marca">
                        <i class="bi bi-bag-check me-1"></i>Registrar entrega
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Aviso cuando el correo no salio --}}
    @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_LISTO && ! $solicitud->notificado_at)
        <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 no-imprimir">
            <i class="bi bi-envelope-exclamation"></i>
            <div class="flex-grow-1 small">
                <strong>El aviso por correo no se pudo enviar.</strong>
                @if ($solicitud->error_notificacion)
                    <div class="text-break">{{ $solicitud->error_notificacion }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.solicitudes.reenviar', $solicitud) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-arrow-repeat me-1"></i>Reenviar aviso
                </button>
            </form>
        </div>
    @elseif ($solicitud->notificado_at)
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 no-imprimir py-2">
            <i class="bi bi-envelope-check"></i>
            <div class="flex-grow-1 small">
                Aviso enviado a <strong>{{ $solicitud->solicitante_email }}</strong>
                el {{ $solicitud->notificado_at->format('d/m/Y h:i a') }}.
            </div>
            @if ($solicitud->estado === \App\Models\Solicitud::ESTADO_LISTO)
                <form method="POST" action="{{ route('admin.solicitudes.reenviar', $solicitud) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-arrow-repeat me-1"></i>Reenviar
                    </button>
                </form>
            @endif
        </div>
    @endif

    <div class="row g-4">

        {{-- ------------------------------------------------------------- --}}
        {{-- Detalle de los items                                          --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="col-xl-8">
            <form method="POST" action="{{ route('admin.solicitudes.listo', $solicitud) }}">
                @csrf

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">
                            <i class="bi bi-list-check me-2 text-marca"></i>Detalle del pedido
                            <span class="text-secondary fw-normal">
                                ({{ $solicitud->items->count() }} referencias / {{ $solicitud->total_unidades }} unidades)
                            </span>
                        </h2>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th scope="col" class="ps-3" style="width:6.5rem">Foto</th>
                                <th scope="col" style="width:4.5rem">Id</th>
                                <th scope="col">Nombre del item</th>
                                <th scope="col" class="text-center" style="width:7rem">Solicitado</th>
                                <th scope="col" class="text-center" style="width:8rem">En almacen</th>
                                @if ($puedeElaborar)
                                    <th scope="col" class="text-center no-imprimir" style="width:8rem">A entregar</th>
                                @else
                                    <th scope="col" class="text-center" style="width:7rem">Entregado</th>
                                @endif
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($solicitud->items as $item)
                                @php
                                    $stock = $item->repuesto?->cantidad_disponible ?? 0;
                                    $alcanza = $stock >= $item->cantidad_solicitada;
                                @endphp
                                <tr class="{{ $alcanza || ! $puedeElaborar ? '' : 'table-warning' }}">
                                    <td class="ps-3">
                                        <img src="{{ $item->foto_url }}" alt="{{ $item->nombre }}"
                                             class="miniatura miniatura-lg">
                                    </td>

                                    <td class="text-secondary">{{ $item->repuesto_id }}</td>

                                    <td>
                                        <div class="fw-semibold">{{ $item->nombre }}</div>
                                        <div class="repuesto-codigo">
                                            <i class="bi bi-upc me-1"></i>{{ $item->codigo }}
                                        </div>
                                        @if ($item->repuesto?->ubicacion)
                                            <div class="small text-secondary mt-1">
                                                <i class="bi bi-geo-alt me-1"></i>{{ $item->repuesto->ubicacion }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="text-center">
                                        <span class="fs-5 fw-bold">{{ $item->cantidad_solicitada }}</span>
                                        <div class="small text-secondary">{{ $item->repuesto?->unidad_medida }}</div>
                                    </td>

                                    <td class="text-center">
                                        <span class="badge {{ $alcanza ? 'text-bg-success-subtle text-success-emphasis' : 'text-bg-warning-subtle text-warning-emphasis' }} border">
                                            {{ $stock }}
                                        </span>
                                        @unless ($alcanza)
                                            <div class="small text-warning-emphasis mt-1">Insuficiente</div>
                                        @endunless
                                    </td>

                                    @if ($puedeElaborar)
                                        <td class="text-center no-imprimir">
                                            <input type="number" class="form-control form-control-sm text-center mx-auto"
                                                   style="max-width:5.5rem"
                                                   name="cantidades[{{ $item->id }}]"
                                                   value="{{ min($item->cantidad_solicitada, $stock) }}"
                                                   min="0" max="{{ $item->cantidad_solicitada }}">
                                        </td>
                                    @else
                                        <td class="text-center">
                                            <span class="fs-5 fw-bold">
                                                {{ $item->cantidad_entregada ?? '—' }}
                                            </span>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($puedeElaborar)
                        <div class="card-body border-top no-imprimir">
                            <label for="nota_almacen" class="form-label small">
                                Nota para el solicitante (opcional)
                            </label>
                            <textarea class="form-control mb-3" id="nota_almacen" name="nota_almacen" rows="2"
                                      maxlength="1000"
                                      placeholder="Ej: se entrega cantidad parcial; el resto llega la proxima semana.">{{ old('nota_almacen', $solicitud->nota_almacen) }}</textarea>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-marca btn-lg"
                                        data-confirmar="Se descontara el inventario y se enviara el correo al solicitante. Continuar?">
                                    <i class="bi bi-check2-circle me-1"></i>Pedido elaborado y notificar
                                </button>

                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal"
                                        data-bs-target="#modalRechazar">
                                    <i class="bi bi-x-circle me-1"></i>Rechazar solicitud
                                </button>
                            </div>

                            <p class="small text-secondary mt-2 mb-0">
                                Al confirmar, el sistema descuenta las cantidades del inventario y envia el correo
                                a <strong>{{ $solicitud->solicitante_email }}</strong>.
                            </p>
                        </div>
                    @endif
                </div>
            </form>
        </div>

        {{-- ------------------------------------------------------------- --}}
        {{-- Datos del solicitante y seguimiento                           --}}
        {{-- ------------------------------------------------------------- --}}
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0"><i class="bi bi-person me-2 text-marca"></i>Solicitante</h2>
                </div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-5 fw-normal text-secondary">Nombre</dt>
                        <dd class="col-7 fw-semibold">{{ $solicitud->solicitante_nombre }}</dd>

                        <dt class="col-5 fw-normal text-secondary">Cedula</dt>
                        <dd class="col-7">{{ $solicitud->solicitante_cedula }}</dd>

                        <dt class="col-5 fw-normal text-secondary">Correo</dt>
                        <dd class="col-7 text-break">
                            <a href="mailto:{{ $solicitud->solicitante_email }}">{{ $solicitud->solicitante_email }}</a>
                        </dd>

                        @if ($solicitud->solicitante_telefono)
                            <dt class="col-5 fw-normal text-secondary">Telefono</dt>
                            <dd class="col-7">{{ $solicitud->solicitante_telefono }}</dd>
                        @endif

                        @if ($solicitud->solicitante_area)
                            <dt class="col-5 fw-normal text-secondary">Area</dt>
                            <dd class="col-7">{{ $solicitud->solicitante_area }}</dd>
                        @endif
                    </dl>

                    @if ($solicitud->observaciones)
                        <div class="alert alert-light border small mt-3 mb-0">
                            <strong>Observaciones:</strong><br>{{ $solicitud->observaciones }}
                        </div>
                    @endif

                    @if ($solicitud->nota_almacen && ! $puedeElaborar)
                        <div class="alert alert-warning small mt-3 mb-0">
                            <strong>Nota del almacen:</strong><br>{{ $solicitud->nota_almacen }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0"><i class="bi bi-clock-history me-2 text-marca"></i>Seguimiento</h2>
                </div>
                <div class="card-body">
                    <ul class="linea-tiempo small mb-0">
                        <li class="cumplido">
                            <strong>Recibida</strong><br>
                            <span class="text-secondary">{{ $solicitud->created_at->format('d/m/Y h:i a') }}</span>
                        </li>
                        <li class="{{ $solicitud->fecha_en_proceso ? 'cumplido' : '' }}">
                            <strong>En proceso</strong><br>
                            <span class="text-secondary">{{ $solicitud->fecha_en_proceso?->format('d/m/Y h:i a') ?? 'Pendiente' }}</span>
                        </li>
                        <li class="{{ $solicitud->fecha_listo ? 'cumplido' : '' }}">
                            <strong>Pedido elaborado y notificado</strong><br>
                            <span class="text-secondary">{{ $solicitud->fecha_listo?->format('d/m/Y h:i a') ?? 'Pendiente' }}</span>
                        </li>
                        <li class="{{ $solicitud->fecha_entrega ? 'cumplido' : '' }}">
                            <strong>Reclamado por el solicitante</strong><br>
                            <span class="text-secondary">{{ $solicitud->fecha_entrega?->format('d/m/Y h:i a') ?? 'Pendiente' }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

    {{-- ----------------------------------------------------------------- --}}
    {{-- Modal de rechazo                                                  --}}
    {{-- ----------------------------------------------------------------- --}}
    @if ($puedeElaborar)
        <div class="modal fade no-imprimir" id="modalRechazar" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.solicitudes.rechazar', $solicitud) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h3 class="modal-title h6">Rechazar solicitud {{ $solicitud->numero }}</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <label for="motivo" class="form-label">Motivo del rechazo</label>
                        <textarea class="form-control" id="motivo" name="nota_almacen" rows="3" required
                                  maxlength="1000"
                                  placeholder="Ej: no hay existencias y el reabastecimiento tarda un mes."></textarea>
                        <div class="form-text">Quedara registrado en la solicitud y visible para el solicitante.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Rechazar solicitud</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

@endsection
