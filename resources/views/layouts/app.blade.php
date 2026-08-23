<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Catalogo de repuestos') &middot; {{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('assets/img/sin-foto.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body class="d-flex flex-column min-vh-100">

@include('partials.marca-agua')

@php
    $carrito = app(\App\Services\Carrito::class);
    $referenciasCarrito = $carrito->cantidadReferencias();
@endphp

<nav class="navbar navbar-expand-lg navbar-app sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('catalogo.index') }}">
            <i class="bi bi-nut-fill fs-4"></i>
            <span>Almacen de Repuestos</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal"
                aria-controls="menuPrincipal" aria-expanded="false" aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menuPrincipal">
            <ul class="navbar-nav me-auto ms-lg-4">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('catalogo.*') ? 'active fw-semibold text-marca' : '' }}"
                       href="{{ route('catalogo.index') }}">
                        <i class="bi bi-grid me-1"></i>Catalogo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('solicitudes.consultar') ? 'active fw-semibold text-marca' : '' }}"
                       href="{{ route('solicitudes.consultar') }}">
                        <i class="bi bi-search me-1"></i>Consultar mi solicitud
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('carrito.index') }}" class="btn btn-marca position-relative">
                    <i class="bi bi-cart3 me-1"></i>Mi solicitud
                    {{-- Contador, no alerta: grafito sobre el boton rojo. Con text-bg-danger
                         el chip reclamaba "algo salio mal" y ademas se empastaba con la marca. --}}
                    <span class="badge rounded-pill text-bg-dark badge-carrito {{ $referenciasCarrito === 0 ? 'd-none' : '' }}"
                          data-contador-carrito>{{ $referenciasCarrito }}</span>
                </a>

                @auth
                    <a href="{{ route('admin.solicitudes.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-speedometer2 me-1"></i>Panel
                    </a>
                @else
                    <a href="{{ route('admin.login') }}" class="btn btn-outline-secondary" title="Ingreso del personal de almacen">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span class="d-lg-none ms-1">Ingreso almacen</span>
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>

<main class="flex-grow-1 py-4">
    <div class="container">
        @include('partials.alertas')
        @yield('contenido')
    </div>
</main>

<footer class="border-top bg-white py-3 mt-auto">
    <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <small class="text-secondary">
            {{ config('almacen.nombre') }} &middot; {{ config('almacen.horario') }}
        </small>
        <small class="text-secondary">
            {{ config('almacen.ubicacion') }}
        </small>
    </div>
</footer>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
