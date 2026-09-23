<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingreso al almacen &middot; {{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('assets/img/sin-foto.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ @filemtime(public_path('assets/css/app.css')) }}">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4"
      style="background:var(--ca-superficie-oscura)">

@include('partials.marca-agua')

<div class="w-100" style="max-width:26rem">

    <div class="text-center mb-4">
        <i class="bi bi-nut-fill text-white" style="font-size:2.5rem;opacity:.9"></i>
        <h1 class="h4 text-white mt-2 mb-1">Panel del almacen</h1>
        <p class="text-white-50 small mb-0">Ingreso exclusivo del personal autorizado</p>
    </div>

    <div class="card border-0 shadow-lg">
        <div class="card-body p-4">

            @if (session('exito'))
                <div class="alert alert-success py-2 small">{{ session('exito') }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.attempt') }}">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">Usuario (correo)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username" placeholder="usuario@sidocsa.com">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Contrasena</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                               id="password" name="password" required autocomplete="current-password">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1"
                           @checked(old('remember'))>
                    <label class="form-check-label small" for="remember">Mantener la sesion iniciada</label>
                </div>

                <button type="submit" class="btn btn-marca btn-lg w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Ingresar
                </button>
            </form>

        </div>
    </div>

    <p class="text-center mt-3 mb-0">
        <a href="{{ route('catalogo.index') }}" class="text-white-50 text-decoration-none small">
            <i class="bi bi-arrow-left me-1"></i>Volver al catalogo publico
        </a>
    </p>

</div>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
