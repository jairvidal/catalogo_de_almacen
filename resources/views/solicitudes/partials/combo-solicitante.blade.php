{{--
    Cuadro combinado con busqueda para elegir al solicitante del ERP.
    Lo usan el formulario de solicitud y la consulta publica.

    Parametros:
      $nombre      nombre del campo oculto que viaja con el id (solicitante_erp_id / solicitante)
      $idCampo     id del cuadro de texto (y prefijo de la lista y la ayuda)
      $elegido     ?SolicitanteErp ya elegido (old() o la URL): se muestra su nombre
      $requerido   bool
      $ayuda       texto bajo el cuadro
      $autofocus   bool (opcional)

    El cuadro de texto NO lleva name: solo viaja el id del campo oculto.
    El comportamiento vive en app.js (data-combo-solicitante).
--}}
@php
    $idLista = $idCampo.'-opciones';
    $idAyuda = $idCampo.'-ayuda';
@endphp

<div class="combo-busqueda position-relative" data-combo-solicitante
     data-url="{{ route('solicitantes.buscar') }}"
     data-minimo="{{ \App\Models\SolicitanteErp::MINIMO_BUSQUEDA }}">
    <label for="{{ $idCampo }}" class="form-label">
        Aprobador @if ($requerido)<span class="text-danger">*</span>@endif
    </label>

    <div class="combo-campo">
        <input type="text" id="{{ $idCampo }}"
               class="form-control combo-texto @error($nombre) is-invalid @enderror"
               role="combobox" aria-autocomplete="list" aria-expanded="false"
               aria-controls="{{ $idLista }}" aria-describedby="{{ $idAyuda }}"
               autocomplete="off" spellcheck="false" maxlength="100"
               placeholder="Escriba su nombre para buscar"
               value="{{ $elegido?->col_nombre }}"
               data-combo-texto
               @required($requerido)
               @if ($autofocus ?? false) autofocus @endif>
        <i class="bi bi-search combo-icono" aria-hidden="true"></i>
    </div>

    <input type="hidden" name="{{ $nombre }}" value="{{ $elegido?->id }}" data-combo-valor>

    <ul id="{{ $idLista }}" class="combo-opciones" role="listbox" aria-label="Solicitantes encontrados"
        hidden data-combo-lista></ul>

    <div class="visually-hidden" aria-live="polite" data-combo-estado></div>

    @error($nombre)
        <div id="{{ $idAyuda }}" class="invalid-feedback d-block">{{ $message }}</div>
    @else
        <div id="{{ $idAyuda }}" class="form-text">{{ $ayuda }}</div>
    @enderror
</div>
