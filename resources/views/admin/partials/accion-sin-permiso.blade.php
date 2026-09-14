{{-- Accion que el perfil no tiene permitida en Funciones por perfil: se pinta
     en gris y deshabilitada en el lugar del boton real, sin formulario ni
     enlace detras. Es solo la cara visible: la ruta la vuelve a negar.

     Parametros:
       $accion  ver | editar | eliminar  (para el texto de ayuda)
       $icono   nombre del icono de Bootstrap Icons sin "bi-"
       $texto   (opcional) etiqueta visible; sin ella el boton es solo icono
       $clases  (opcional) clases extra de tamano o variante, ej. "btn-sm btn-outline-secondary" --}}
@php
    $ayuda = "Su perfil no tiene permiso para {$accion}";
@endphp
<button type="button" class="btn {{ $clases ?? 'btn-outline-secondary' }} accion-sin-permiso" disabled
        title="{{ $ayuda }}" aria-label="{{ ($texto ?? ucfirst($accion)).' ('.$ayuda.')' }}"
        data-sin-permiso="{{ $accion }}">
    <i class="bi bi-{{ $icono }}{{ empty($texto) ? '' : ' me-1' }}"></i>{{ $texto ?? '' }}
</button>
