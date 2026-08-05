@extends('layouts.app')

@section('titulo', 'Datos del solicitante')

@section('contenido')

    <div class="mb-4">
        <h1 class="h3 mb-1">Datos del solicitante</h1>
        <p class="text-secondary mb-0">
            Complete sus datos para que el almacen pueda identificarlo y avisarle cuando el pedido este listo.
        </p>
    </div>

    <form method="POST" action="{{ route('solicitudes.store') }}" class="row g-4">
        @csrf

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="solicitante_nombre" class="form-label">
                                Nombre completo <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('solicitante_nombre') is-invalid @enderror"
                                   id="solicitante_nombre" name="solicitante_nombre"
                                   value="{{ old('solicitante_nombre') }}" maxlength="150" required autofocus
                                   placeholder="Nombres y apellidos">
                            @error('solicitante_nombre')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-sm-6">
                            <label for="solicitante_cedula" class="form-label">
                                Cedula <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control @error('solicitante_cedula') is-invalid @enderror"
                                   id="solicitante_cedula" name="solicitante_cedula"
                                   value="{{ old('solicitante_cedula') }}" maxlength="30" required
                                   inputmode="numeric" placeholder="Numero de documento">
                            @error('solicitante_cedula')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">La necesitara para consultar el estado de su solicitud.</div>
                            @enderror
                        </div>

                        <div class="col-sm-6">
                            <label for="solicitante_telefono" class="form-label">Telefono o extension</label>
                            <input type="text" class="form-control @error('solicitante_telefono') is-invalid @enderror"
                                   id="solicitante_telefono" name="solicitante_telefono"
                                   value="{{ old('solicitante_telefono') }}" maxlength="30">
                            @error('solicitante_telefono')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label for="solicitante_email" class="form-label">
                                Correo electronico <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control @error('solicitante_email') is-invalid @enderror"
                                       id="solicitante_email" name="solicitante_email"
                                       value="{{ old('solicitante_email') }}" maxlength="150" required
                                       placeholder="nombre@sidocsa.com">
                                @error('solicitante_email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                A este correo llegara el aviso cuando el pedido este listo para reclamar.
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="solicitante_area" class="form-label">Area o dependencia</label>
                            <input type="text" class="form-control @error('solicitante_area') is-invalid @enderror"
                                   id="solicitante_area" name="solicitante_area"
                                   value="{{ old('solicitante_area') }}" maxlength="100"
                                   placeholder="Ej: Mantenimiento, Produccion, Planta 2">
                            @error('solicitante_area')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label for="observaciones" class="form-label">Observaciones para el almacen</label>
                            <textarea class="form-control @error('observaciones') is-invalid @enderror"
                                      id="observaciones" name="observaciones" rows="3" maxlength="1000"
                                      placeholder="Equipo, orden de trabajo o cualquier detalle util">{{ old('observaciones') }}</textarea>
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
