@use('App\Models\Funcionalidad')

@php
    $usuario = auth()->user();
    $verSolicitudes = $usuario->puede(Funcionalidad::SOLICITUDES, Funcionalidad::ACCION_VER);

    // Se cuenta lo que esta a la espera de accion del almacenista para el
    // indicador rojo del menu. Solo si el perfil ve las solicitudes.
    $porAtender = $verSolicitudes
        ? \App\Models\Solicitud::whereIn('estado', [
            \App\Models\Solicitud::ESTADO_PENDIENTE,
            \App\Models\Solicitud::ESTADO_EN_PROCESO,
        ])->count()
        : 0;

    // Cada entrada se pinta solo si el perfil puede VER la funcionalidad.
    // Ocultarla no es la barrera: la ruta vuelve a pedir el permiso.
    $gestion = [
        [Funcionalidad::REPUESTOS, 'admin.repuestos', 'box-seam', 'Catalogo e inventario'],
        [Funcionalidad::CATEGORIAS, 'admin.categorias', 'tags', 'Categorias'],
        [Funcionalidad::ROLES, 'admin.roles', 'person-badge', 'Roles'],
        [Funcionalidad::PERMISOS, 'admin.permisos', 'ui-checks-grid', 'Funciones por perfil'],
        [Funcionalidad::PARAMETROS, 'admin.parametros', 'sliders', 'Parametros'],
    ];

    $gestionVisible = array_values(array_filter(
        $gestion,
        fn (array $entrada) => $usuario->puede($entrada[0], Funcionalidad::ACCION_VER)
    ));
@endphp

<ul class="nav nav-pills flex-column">
    @if ($verSolicitudes)
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center {{ request()->routeIs('admin.solicitudes.index') || request()->routeIs('admin.solicitudes.show') ? 'active' : '' }}"
               href="{{ route('admin.solicitudes.index') }}">
                <i class="bi bi-inbox me-2"></i>
                <span class="flex-grow-1">Solicitudes</span>
                @if ($porAtender > 0)
                    <span class="badge rounded-pill text-bg-danger">{{ $porAtender }}</span>
                @endif
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.solicitudes.listos') ? 'active' : '' }}"
               href="{{ route('admin.solicitudes.listos') }}">
                <i class="bi bi-bag-check me-2"></i>Listos para reclamar
            </a>
        </li>
    @endif

    @foreach ($gestionVisible as $indice => [$clave, $ruta, $icono, $etiqueta])
        <li class="nav-item {{ $indice === 0 ? 'mt-2' : '' }}">
            <a class="nav-link {{ request()->routeIs($ruta.'.*') ? 'active' : '' }}"
               href="{{ route($ruta.'.index') }}">
                <i class="bi bi-{{ $icono }} me-2"></i>{{ $etiqueta }}
            </a>
        </li>
    @endforeach

    <li class="nav-item mt-2">
        <a class="nav-link" href="{{ route('catalogo.index') }}" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right me-2"></i>Ver catalogo publico
        </a>
    </li>
</ul>
