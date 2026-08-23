{{-- Marca de agua: el logo de la empresa detras del contenido.

     Se incluye como hija DIRECTA de <body> a proposito: cualquier contenedor
     con transform, opacidad o filtro le crearia un contexto de apilamiento y
     el z-index negativo dejaria de mandarla al fondo.

     La URL sale de asset() y no de una ruta escrita en el CSS para que
     funcione igual en `php artisan serve` y en Apache bajo subcarpeta.

     Es decorativa: alt vacio y aria-hidden para que el lector de pantalla no
     la anuncie. Los estilos viven en .marca-agua (public/assets/css/app.css). --}}
<img src="{{ asset('logo.png') }}" alt="" aria-hidden="true" class="marca-agua" width="238" height="105">
