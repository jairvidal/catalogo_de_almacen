{{-- Un item de acordeon de Bootstrap 5.3 (.accordion-item) con su encabezado
     y su cuerpo ya enlazados para lectores de pantalla.

     Uso, siempre dentro de un <div class="accordion accordion-flush" id="...">:

         <x-acordeon-item id="seccion-api-erp" titulo="Configuracion API ERP" icono="plug">
             ...contenido...
         </x-acordeon-item>

     - id: unico en la pagina. Es el id del cuerpo plegable; el del boton es
       "{id}-encabezado". De el cuelgan aria-controls y aria-labelledby.
     - abierto: por defecto true, para que la pagina no aparezca vacia al entrar.
     - padre: id del .accordion. Solo si se quiere que abrir un item cierre los
       demas (data-bs-parent); sin el, cada item se abre y cierra por su cuenta.

     El color vive en app.css (reasignacion de las variables --bs-accordion-*),
     no aqui. --}}
@props([
    'id',
    'titulo',
    'icono' => null,
    'abierto' => true,
    'padre' => null,
])

<div {{ $attributes->class('accordion-item') }}>
    <h2 class="accordion-header" id="{{ $id }}-encabezado">
        <button class="accordion-button {{ $abierto ? '' : 'collapsed' }}" type="button"
                data-bs-toggle="collapse" data-bs-target="#{{ $id }}"
                aria-expanded="{{ $abierto ? 'true' : 'false' }}" aria-controls="{{ $id }}">
            @if ($icono)
                <i class="bi bi-{{ $icono }} me-2" aria-hidden="true"></i>
            @endif
            {{ $titulo }}
        </button>
    </h2>
    <div id="{{ $id }}" class="accordion-collapse collapse {{ $abierto ? 'show' : '' }}"
         aria-labelledby="{{ $id }}-encabezado"
         @if ($padre) data-bs-parent="#{{ $padre }}" @endif>
        <div class="accordion-body">
            {{ $slot }}
        </div>
    </div>
</div>
