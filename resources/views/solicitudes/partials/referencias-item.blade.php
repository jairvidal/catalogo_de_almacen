{{--
    Referencias de un item diligenciadas al aprobar, en solo lectura (portal
    ya decidida y admin/solicitudes/show). Solo las que tienen valor: si no
    hay ninguna no se pinta nada. Requiere las maestras del item precargadas
    (SolicitudItem::relacionesDeReferencia) para no caer en N+1.
--}}
@php($filasItem = $item->referenciasParaMostrar())

@if ($filasItem !== [])
    <dl class="referencias-item small mt-2 mb-0">
        @foreach ($filasItem as $fila)
            <div>
                <dt>{{ $fila['etiqueta'] }}</dt>
                <dd class="text-break">{{ $fila['valor'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
