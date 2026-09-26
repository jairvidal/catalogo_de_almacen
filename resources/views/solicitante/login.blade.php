@extends('layouts.app')

@section('titulo', 'Ingreso para aprobar solicitudes')

@section('contenido')

    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">

            <div class="mb-4 text-center">
                <h1 class="h3 mb-1">Aprobar solicitudes</h1>
                <p class="text-secondary mb-0">
                    Ingrese para aprobar o denegar las solicitudes de repuestos hechas a su nombre.
                </p>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('solicitante.login.attempt') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="correo" class="form-label">Usuario (su correo)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control @error('correo') is-invalid @enderror"
                                       id="correo" name="correo" value="{{ old('correo') }}" required autofocus
                                       maxlength="150" autocomplete="username" placeholder="nombre@sidocsa.com">
                                @error('correo')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="contrasena" class="form-label">Contrasena</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control @error('contrasena') is-invalid @enderror"
                                       id="contrasena" name="contrasena" required maxlength="255"
                                       autocomplete="current-password">
                                @error('contrasena')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="recordar" name="recordar" value="1"
                                   @checked(old('recordar'))>
                            <label class="form-check-label small" for="recordar">Mantener la sesion iniciada</label>
                        </div>

                        <button type="submit" class="btn btn-marca btn-lg w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Ingresar
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-white text-center py-3">
                    <a href="{{ route('solicitante.contrasena.edit') }}" class="small">
                        <i class="bi bi-key me-1"></i>Cambiar contrasena
                    </a>
                </div>
            </div>

            <p class="small text-secondary text-center mt-3 mb-0">
                La contrasena la asigna el almacen y le llega a su correo. Si la olvido, pida que se la restablezcan.
            </p>

        </div>
    </div>

@endsection
