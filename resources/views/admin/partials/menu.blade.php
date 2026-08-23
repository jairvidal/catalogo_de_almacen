@php
    // Se cuenta lo que esta a la espera de accion del almacenista para el
    // indicador rojo del menu.
    $porAtender = \App\Models\Solicitud::whereIn('estado', [
        \App\Models\Solicitud::ESTADO_PENDIENTE,
        \App\Models\Solicitud::ESTADO_EN_PROCESO,
    ])->count();
@endphp

<ul class="nav nav-pills flex-column">
    <li class="nav-item">
        <a class="nav-link d-flex align-items-center {{ request()->routeIs('admin.solicitudes.*') ? 'active' : '' }}"
           href="{{ route('admin.solicitudes.index') }}">
            <i class="bi bi-inbox me-2"></i>
            <span class="flex-grow-1">Solicitudes</span>
            @if ($porAtender > 0)
                <span class="badge rounded-pill text-bg-danger">{{ $porAtender }}</span>
            @endif
        </a>
    </li>

    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.solicitudes.index') && request('estado') === 'listo' ? 'active' : '' }}"
           href="{{ route('admin.solicitudes.index', ['estado' => 'listo']) }}">
            <i class="bi bi-bag-check me-2"></i>Listos para reclamar
        </a>
    </li>

    @if (auth()->user()->puedeGestionarCatalogo())
        <li class="nav-item mt-2">
            <a class="nav-link {{ request()->routeIs('admin.repuestos.*') ? 'active' : '' }}"
               href="{{ route('admin.repuestos.index') }}">
                <i class="bi bi-box-seam me-2"></i>Catalogo e inventario
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.categorias.*') ? 'active' : '' }}"
               href="{{ route('admin.categorias.index') }}">
                <i class="bi bi-tags me-2"></i>Categorias
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}"
               href="{{ route('admin.roles.index') }}">
                <i class="bi bi-person-badge me-2"></i>Roles
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.parametros.*') ? 'active' : '' }}"
               href="{{ route('admin.parametros.index') }}">
                <i class="bi bi-sliders me-2"></i>Parametros
            </a>
        </li>
    @endif

    <li class="nav-item mt-2">
        <a class="nav-link" href="{{ route('catalogo.index') }}" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right me-2"></i>Ver catalogo publico
        </a>
    </li>
</ul>
