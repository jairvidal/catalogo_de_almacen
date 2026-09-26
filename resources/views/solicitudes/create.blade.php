@extends('layouts.app')

@section('titulo', 'Datos del solicitante')

@section('contenido')

    <div class="mb-4">
        <h1 class="h3 mb-1">Datos del Peticionario</h1>
        <p class="text-secondary mb-0">
            Seleccione su nombre para que el almacen pueda identificarlo y avisarle cuando el pedido este listo.
        </p>
    </div>

    <form method="POST" action="{{ route('solicitudes.store') }}" class="row g-4">
        @csrf

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="nombre_completo" class="form-label">
                                Nombre completo solicitante <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('nombre_completo') is-invalid @enderror"
                                   id="nombre_completo" name="nombre_completo"
                                   value="{{ old('nombre_completo') }}" maxlength="150" required autofocus
                                   autocomplete="name" placeholder="Ingrese el nombres y apellidos">
                            @error('nombre_completo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
							<div id="msg" class="form-text">Aqui se registra la informaciòn de la persona que requiere el repuesto o consumible</div>
                        </div>

                        <div class="col-12">
                            @include('solicitudes.partials.combo-solicitante', [
                                'nombre' => 'solicitante_erp_id',
                                'idCampo' => 'solicitante_buscar',
                                'elegido' => $solicitanteElegido,
                                'requerido' => true,
                                'ayuda' => 'Busque de la persona que aprobarà esta requisiciòn, seleccionelo de la lista. Al correo que tiene registrado el almacen le llegara el aviso cuando el pedido este listo.',
                            ])
                        </div>

                        <div class="col-12">
                            <label for="observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control @error('observaciones') is-invalid @enderror"
                                      id="observaciones" name="observaciones" rows="3" maxlength="1000"
                                      placeholder="Equipo, orden de trabajo o cualquier detalle util para la entrega">{{ old('observaciones') }}</textarea>
                            @error('observaciones')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm position-sticky" style="top:5.5rem">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-secondary mb-3" style="letter-spacing:.05em">
                        Repuestos solicitados
                    </h2>

                    <ul class="list-group list-group-flush mb-3">
                        @foreach ($lineas as $linea)
                            <li class="list-group-item px-0 d-flex gap-2 align-items-center">
                                <img src="{{ $linea->foto_url }}" alt="{{ $linea->nombre }}" class="miniatura"
                                     style="width:44px;height:44px">
                                <div class="flex-grow-1 min-width-0">
                                    <div class="small fw-semibold text-truncate">{{ $linea->nombre }}</div>
                                    <div class="repuesto-codigo">{{ $linea->codigo }}</div>
                                </div>
                                <span class="badge text-bg-light border">{{ $linea->cantidad_pedida }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <dl class="row small mb-3">
                        <dt class="col-8 fw-normal text-secondary">Referencias</dt>
                        <dd class="col-4 text-end fw-semibold mb-1">{{ $lineas->count() }}</dd>
                        <dt class="col-8 fw-normal text-secondary">Unidades totales</dt>
                        <dd class="col-4 text-end fw-semibold mb-0">{{ $unidades }}</dd>
                    </dl>

                    <button type="submit" class="btn btn-marca btn-lg w-100">
                        <i class="bi bi-send me-1"></i>Enviar solicitud al almacen
                    </button>

                    <a href="{{ route('carrito.index') }}" class="btn btn-link w-100 text-decoration-none mt-2">
                        <i class="bi bi-arrow-left me-1"></i>Volver a modificar
                    </a>
                </div>
            </div>
        </div>

    </form>

@endsection
