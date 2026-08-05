<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Panel') &middot; Almacen</title>

    <link rel="icon" href="{{ asset('assets/img/sin-foto.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body>

<div class="container-fluid">
    <div class="row">

        {{-- ---------------------------------------------------------------- --}}
        {{-- Menu lateral                                                     --}}
        {{-- ---------------------------------------------------------------- --}}
        <aside class="col-lg-2 panel-sidebar p-3 d-none d-lg-block position-sticky top-0 vh-100">
            <a href="{{ route('admin.solicitudes.index') }}"
               class="d-flex align-items-center gap-2 text-white text-decoration-none mb-4 px-2">
                <i class="bi bi-nut-fill fs-4"></i>
                <span class="fw-bold">Almacen</span>
            </a>

            @include('admin.partials.menu')

            <hr class="border-secondary">

            <div class="px-2 pb-2">
                <div class="text-white small fw-semibold text-truncate">{{ auth()->user()->name }}</div>
                <div class="text-white-50" style="font-size:.78rem">
                    {{ auth()->user()->esAdmin() ? 'Administrador' : 'Almacenista' }}
                </div>
            </div>

            <form method="POST" action="{{ route('admin.logout') }}" class="px-2">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-light w-100">
                    <i class="bi bi-box-arrow-right me-1"></i>Cerrar sesion
                </button>
            </form>
        </aside>

        {{-- ---------------------------------------------------------------- --}}
        {{-- Contenido                                                        --}}
        {{-- ---------------------------------------------------------------- --}}
        <div class="col-lg-10 px-0">

            {{-- Barra superior movil --}}
            <nav class="navbar navbar-dark bg-dark d-lg-none">
                <div class="container-fluid">
                    <span class="navbar-brand mb-0"><i class="bi bi-nut-fill me-1"></i>Almacen</span>
                    <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas"
                            data-bs-target="#menuMovil" aria-controls="menuMovil" aria-label="Abrir menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>

            <div class="offcanvas offcanvas-start panel-sidebar text-white" tabindex="-1" id="menuMovil">
                <div class="offcanvas-header">
                    <h2 class="offcanvas-title h6 mb-0">{{ auth()->user()->name }}</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                            aria-label="Cerrar"></button>
                </div>
                <div class="offcanvas-body">
                    @include('admin.partials.menu')
                    <hr class="border-secondary">
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-light w-100">
                            <i class="bi bi-box-arrow-right me-1"></i>Cerrar sesion
                        </button>
                    </form>
                </div>
            </div>

            <main class="p-3 p-lg-4">
                @include('partials.alertas')
                @yield('contenido')
            </main>
        </div>

    </div>
</div>

<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
