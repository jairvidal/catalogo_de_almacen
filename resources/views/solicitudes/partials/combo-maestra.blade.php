{{--
    Lista de una maestra del ERP (centros, proyecto, motivo, bodega, unidad
    de medida) con busqueda del lado del cliente.

    El servidor pinta un <select> normal, que es lo que viaja y lo que queda
    si no hay JavaScript. app.js (data-combo-maestra) lo esconde y pone encima
    un cuadro combinado ARIA 1.2 que filtra las mismas opciones; al elegir,
    escribe el valor en el select. Las maestras son chicas: no hay busqueda en
    el servidor.

    Parametros:
      $nombre          name del select (cod_centro_operacion, items[12][cod_bodega]...)
      $etiqueta        texto de la etiqueta
      $opciones        array codigo => "codigo - nombre" (solo activas)
      $valor           codigo elegido (old() o el guardado)
      $form            id del <form> al que pertenece (atributo form)
      $id              (opcional) id del select; por defecto $nombre
      $claveError      (opcional) clave en $errors; por defecto $nombre
      $etiquetaOculta  (opcional) true: etiqueta solo para lectores de
                       pantalla (en la tabla de items la dice el encabezado)
      $clases          (opcional) clases del contenedor; por defecto mb-3
      $hereda          (opcional) nombre del campo de la cabecera cuyo valor
                       se propaga a este mientras la persona no lo toque
--}}
@php
    $idCampo = $id ?? $nombre;
    $claveError = $claveError ?? $nombre;
    $idAyuda = $idCampo.'-ayuda';
    $conError = $errors->has($claveError);
    $sinOpciones = $opciones === [];
@endphp

<div class="combo-busqueda combo-maestra position-relative {{ $clases ?? 'mb-3' }}" data-combo-maestra>
    <label for="{{ $idCampo }}" class="{{ ($etiquetaOculta ?? false) ? 'visually-hidden' : 'form-label small text-secondary mb-1' }}">{{ $etiqueta }}</label>

    <select id="{{ $idCampo }}" name="{{ $nombre }}" form="{{ $form }}"
            class="form-select form-select-sm @if ($conError) is-invalid @endif"
            @if ($conError || $sinOpciones) aria-describedby="{{ $idAyuda }}" @endif
            @isset($hereda) data-hereda="{{ $hereda }}" @endisset
            data-combo-select>
        <option value="">Sin asignar</option>
        @foreach ($opciones as $codigo => $texto)
            <option value="{{ $codigo }}" @selected((string) $codigo === (string) $valor)>{{ $texto }}</option>
        @endforeach
    </select>

    @if ($conError)
        <div id="{{ $idAyuda }}" class="invalid-feedback d-block">{{ $errors->first($claveError) }}</div>
    @elseif ($sinOpciones)
        <div id="{{ $idAyuda }}" class="form-text">
            {{ ($etiquetaOculta ?? false) ? 'Sin opciones en el ERP.' : 'No hay opciones activas cargadas desde el ERP.' }}
        </div>
    @endif
</div>
