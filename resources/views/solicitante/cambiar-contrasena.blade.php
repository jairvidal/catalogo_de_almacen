@extends('layouts.app')

@section('titulo', 'Cambiar contrasena')

@section('contenido')

    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">

            <div class="mb-4">
                <a href="{{ route('solicitante.login') }}" class="text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>Volver al ingreso
                </a>
                <h1 class="h3 mt-2 mb-1">Cambiar contrasena</h1>
                <p class="text-secondary mb-0">
                    Confirme su contrasena actual y escriba la nueva.
                </p>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('solicitante.contrasena.update') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="correo" class="form-label">Usuario (su correo)</label>
                            <input type="email" class="form-control @error('correo') is-invalid @enderror"
                                   id="correo" name="correo" value="{{ old('correo') }}" required autofocus
                                   maxlength="150" autocomplete="username">
                            @error('correo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="contrasena_actual" class="form-label">Contrasena actual</label>
                            <input type="password" class="form-control @error('contrasena_actual') is-invalid @enderror"
                                   id="contrasena_actual" name="contrasena_actual" required maxlength="255"
                                   autocomplete="current-password">
                            @error('contrasena_actual')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="contrasena" class="form-label">Contrasena nueva</label>
                            <input type="password" class="form-control @error('contrasena') is-invalid @enderror"
                                   id="contrasena" name="contrasena" required minlength="10" maxlength="255"
                                   autocomplete="new-password" aria-describedby="ayuda-contrasena">
                            <div id="ayuda-contrasena" class="form-text">
                                Minimo 10 caracteres, con mayusculas, minusculas y al menos un numero.
                            </div>
                            @error('contrasena')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="contrasena_confirmation" class="form-label">Confirme la contrasena nueva</label>
                            <input type="password" class="form-control" id="contrasena_confirmation"
                                   name="contrasena_confirmation" required maxlength="255" autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-marca btn-lg w-100">
                            <i class="bi bi-check2 me-1"></i>Cambiar contrasena
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

@endsection
