/**
 * Catalogo de repuestos - comportamiento del front.
 *
 * Se agrega al pedido sin recargar la pagina para que el usuario pueda seguir
 * navegando el catalogo; el contador del encabezado se actualiza con la
 * respuesta del servidor.
 */
(function () {
    'use strict';

    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    /* --- Avisos flotantes ------------------------------------------------ */

    function mostrarAviso(mensaje, tipo = 'success') {
        let zona = document.querySelector('.zona-toast');

        if (!zona) {
            zona = document.createElement('div');
            zona.className = 'zona-toast';
            document.body.appendChild(zona);
        }

        const icono = tipo === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill';
        const aviso = document.createElement('div');
        aviso.className = `toast align-items-center text-bg-${tipo === 'success' ? 'success' : 'danger'} border-0 mb-2`;
        aviso.setAttribute('role', 'alert');
        aviso.setAttribute('aria-live', 'assertive');
        aviso.innerHTML = `
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi bi-${icono}"></i><span></span>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Cerrar"></button>
            </div>`;
        aviso.querySelector('span').textContent = mensaje;

        zona.appendChild(aviso);

        const instancia = new bootstrap.Toast(aviso, { delay: 4000 });
        instancia.show();
        aviso.addEventListener('hidden.bs.toast', () => aviso.remove());
    }

    window.mostrarAviso = mostrarAviso;

    /* --- Contador del pedido en el encabezado ---------------------------- */

    function actualizarContador(referencias, unidades) {
        document.querySelectorAll('[data-contador-carrito]').forEach((nodo) => {
            nodo.textContent = referencias;
            nodo.classList.toggle('d-none', referencias === 0);
        });

        document.querySelectorAll('[data-unidades-carrito]').forEach((nodo) => {
            nodo.textContent = unidades;
        });
    }

    /* --- Boton "Agregar al pedido" --------------------------------------- */

    document.addEventListener('submit', async (evento) => {
        const formulario = evento.target.closest('form[data-agregar-carrito]');

        if (!formulario) {
            return;
        }

        evento.preventDefault();

        const boton = formulario.querySelector('button[type="submit"]');
        const textoOriginal = boton?.innerHTML;

        if (boton) {
            boton.disabled = true;
            boton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
        }

        try {
            const respuesta = await fetch(formulario.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: new FormData(formulario),
            });

            const datos = await respuesta.json();

            if (respuesta.ok && datos.ok) {
                actualizarContador(datos.referencias, datos.unidades);
                mostrarAviso(datos.mensaje, 'success');

                if (boton) {
                    boton.innerHTML = '<i class="bi bi-check-lg"></i> Agregado';
                    boton.classList.remove('btn-marca');
                    boton.classList.add('btn-success');
                    setTimeout(() => {
                        boton.innerHTML = textoOriginal;
                        boton.classList.add('btn-marca');
                        boton.classList.remove('btn-success');
                        boton.disabled = false;
                    }, 1600);
                    return;
                }
            } else {
                mostrarAviso(datos.mensaje ?? 'No se pudo agregar el repuesto.', 'danger');
            }
        } catch (error) {
            mostrarAviso('No se pudo conectar con el servidor. Intente de nuevo.', 'danger');
        }

        if (boton) {
            boton.disabled = false;
            boton.innerHTML = textoOriginal;
        }
    });

    /* --- Boton "Actualizar" del inventario (modo manual) ----------------- */

    /* La sincronizacion corre dentro de la peticion (QUEUE_CONNECTION=sync) y
       puede tardar: el boton se bloquea con un spinner y se avisa al empezar,
       para que nadie piense que no paso nada y vuelva a apretar. */
    document.addEventListener('submit', async (evento) => {
        const formulario = evento.target.closest('form[data-sincronizar-stock]');

        if (!formulario) {
            return;
        }

        evento.preventDefault();

        const boton = formulario.querySelector('button[type="submit"]');
        const textoOriginal = boton?.innerHTML;

        if (boton) {
            boton.disabled = true;
            boton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Actualizando...';
        }

        mostrarAviso('Actualizando el stock desde el ERP. Puede tardar un momento...', 'success');

        try {
            const respuesta = await fetch(formulario.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: new FormData(formulario),
            });

            const datos = await respuesta.json().catch(() => ({}));

            if (respuesta.ok && datos.ok) {
                mostrarAviso(datos.mensaje, 'success');

                /* La fecha la formatea el servidor (en hora de Colombia) y se
                   pinta aqui mismo: recargar la pagina borraba el aviso y
                   dejaba al usuario cuatro segundos viendo la fecha vieja, que
                   es justo lo que hacia pensar que la corrida no se registro. */
                const marca = document.querySelector('[data-sincronizar-ultima]');

                if (marca && datos.ultima) {
                    marca.textContent = `Ultima corrida: ${datos.ultima}.`;
                }

                /* La corrida termino: el aviso de "en curso" ya no aplica. */
                document.querySelector('[data-sincronizar-en-curso]')?.remove();
            } else {
                /* El orden importa. `mensaje` es lo que responde el
                   controlador y siempre explica que paso. `message` es lo que
                   devuelve Laravel cuando la peticion ni siquiera llego ahi
                   (419 de sesion vencida, un 500 sin capturar). Y si no hay
                   ninguno de los dos, el cuerpo no era JSON: paso algo entre el
                   navegador y la aplicacion —tipicamente el servidor web
                   cortando la peticion— y entonces el unico dato que tenemos es
                   el codigo HTTP, que sin esto se perdia.

                   No es un detalle cosmetico: una corrida que IIS cortaba a los
                   20 segundos se veia en pantalla como un escueto "No se pudo
                   actualizar el stock", sin codigo ni pista, y eso mando la
                   busqueda del fallo por el camino equivocado. */
                mostrarAviso(
                    datos.mensaje
                        ?? datos.message
                        ?? `No se pudo actualizar el stock: el servidor respondio HTTP ${respuesta.status}`
                            + ' sin un mensaje legible. Revise storage/logs/laravel.log y el log del servidor web.',
                    'danger'
                );
            }
        } catch (error) {
            mostrarAviso('No se pudo conectar con el servidor. Intente de nuevo.', 'danger');
        }

        if (boton) {
            boton.disabled = false;
            boton.innerHTML = textoOriginal;
        }
    });

    /* --- Confirmaciones antes de acciones destructivas ------------------- */

    document.addEventListener('submit', (evento) => {
        const formulario = evento.target.closest('form[data-confirmar]');

        if (formulario && !window.confirm(formulario.dataset.confirmar)) {
            evento.preventDefault();
        }
    });

    /* --- Filtros excluyentes (data-excluye="id-del-otro-campo") ---------- */

    /* Mientras un campo tiene valor, el campo cuyo id nombra queda
       deshabilitado, y un campo deshabilitado no viaja en el formulario. El
       texto que explica el bloqueo es el elemento de aria-describedby del
       campo bloqueado: se muestra solo mientras dura el bloqueo.

       ORDEN: este bloque tiene que registrarse ANTES que el autoenvio. Los
       listeners de un mismo elemento corren en el orden en que se agregaron, y
       form.submit() arma los datos en el momento en que se llama: si el
       autoenvio corriera primero, el otro campo todavia estaria habilitado y
       viajarian los dos valores. */
    function bloquearPorExclusion(campo) {
        const otro = document.getElementById(campo.dataset.excluye);

        if (!otro) {
            return;
        }

        const bloqueado = campo.value !== '';
        otro.disabled = bloqueado;

        const ayuda = document.getElementById(otro.getAttribute('aria-describedby') ?? '');

        if (ayuda) {
            ayuda.hidden = !bloqueado;
        }
    }

    document.querySelectorAll('[data-excluye]').forEach((campo) => {
        /* El servidor ya pinta el estado correcto; se vuelve a aplicar al
           cargar porque el navegador puede restaurar el valor de un select al
           volver con "atras" sin restaurar el disabled. Un campo que llega
           deshabilitado no manda sobre el otro. */
        if (!campo.disabled) {
            bloquearPorExclusion(campo);
        }

        campo.addEventListener('change', () => bloquearPorExclusion(campo));
    });

    /* --- Autoenvio de filtros del catalogo ------------------------------- */

    document.querySelectorAll('[data-autoenviar]').forEach((campo) => {
        campo.addEventListener('change', () => campo.form?.submit());
    });

    /* --- Filtro automatico al escribir (input[data-autofiltrar]) --------- */

    /* Envia el formulario cuando el usuario deja de escribir por un momento,
       sin tener que apretar el boton de filtrar. El envio recarga la pagina,
       asi que antes de enviar se guarda que cuadro tenia el foco y donde iba
       el cursor, y al cargar se devuelve ahi: sin eso habria que volver a
       hacer clic en el cuadro despues de cada letra. sessionStorage puede
       fallar (ventana privada, datos bloqueados): la pagina funciona igual. */
    const ESPERA_AUTOFILTRO_MS = 600;
    const CLAVE_FOCO_FILTRO = 'autofiltro.foco';

    try {
        const guardado = JSON.parse(sessionStorage.getItem(CLAVE_FOCO_FILTRO) ?? 'null');
        sessionStorage.removeItem(CLAVE_FOCO_FILTRO);
        const cuadro = guardado ? document.getElementById(guardado.id) : null;

        if (cuadro?.matches('[data-autofiltrar]')) {
            cuadro.focus();
            const posicion = Math.min(guardado.cursor ?? cuadro.value.length, cuadro.value.length);
            try { cuadro.setSelectionRange(posicion, posicion); } catch { /* tipo sin cursor */ }
        }
    } catch { /* sin almacenamiento: solo se pierde el foco */ }

    document.querySelectorAll('input[data-autofiltrar]').forEach((cuadro) => {
        let temporizador = null;
        let ultimoEnviado = cuadro.value;

        const enviar = (forzar = false) => {
            clearTimeout(temporizador);

            // Sin cambios reales (p. ej. un espacio al final) no se recarga.
            if (!cuadro.form || (!forzar && cuadro.value.trim() === ultimoEnviado.trim())) {
                return;
            }

            ultimoEnviado = cuadro.value;

            try {
                sessionStorage.setItem(CLAVE_FOCO_FILTRO, JSON.stringify({
                    id: cuadro.id,
                    cursor: cuadro.selectionStart,
                }));
            } catch { /* sin almacenamiento */ }

            cuadro.form.requestSubmit ? cuadro.form.requestSubmit() : cuadro.form.submit();
        };

        cuadro.addEventListener('input', () => {
            clearTimeout(temporizador);
            temporizador = setTimeout(enviar, ESPERA_AUTOFILTRO_MS);
        });

        // Enter envia ya, sin esperar el temporizador ni duplicar el envio.
        cuadro.addEventListener('keydown', (evento) => {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                enviar(true);
            }
        });
    });

    /* --- Funciones por perfil (form[data-matriz-permisos]) --------------- */

    /* Editar o eliminar implican ver: al marcarlos se marca ver, y al quitar
       ver se quitan los otros dos. Es solo comodidad: PermisoService aplica la
       misma regla al guardar y la base la sostiene con un CHECK. */
    function pintarTarjetaPermiso(tarjeta) {
        const marcada = [...tarjeta.querySelectorAll('[data-accion-permiso]')].some((casilla) => casilla.checked);
        tarjeta.classList.toggle('con-permiso', marcada);
    }

    document.addEventListener('change', (evento) => {
        const casilla = evento.target.closest('form[data-matriz-permisos] [data-accion-permiso]');

        if (!casilla) {
            return;
        }

        const tarjeta = casilla.closest('[data-tarjeta-permiso]');
        const hermana = (accion) => tarjeta?.querySelector(`[data-accion-permiso="${accion}"]`);

        if (casilla.checked && casilla.dataset.accionPermiso !== 'ver') {
            const ver = hermana('ver');

            if (ver) {
                ver.checked = true;
            }
        }

        if (!casilla.checked && casilla.dataset.accionPermiso === 'ver') {
            ['editar', 'eliminar'].forEach((accion) => {
                const otra = hermana(accion);

                if (otra) {
                    otra.checked = false;
                }
            });
        }

        if (tarjeta) {
            pintarTarjetaPermiso(tarjeta);
        }
    });

    /* "Seleccionar todo" y "Limpiar": solo cambian las casillas; nada se
       guarda hasta apretar "Guardar permisos". */
    document.addEventListener('click', (evento) => {
        const boton = evento.target.closest('form[data-matriz-permisos] [data-permisos-marcar]');

        if (!boton) {
            return;
        }

        const formulario = boton.closest('form');
        const marcar = boton.dataset.permisosMarcar === 'todo';

        formulario.querySelectorAll('[data-accion-permiso]:not(:disabled)').forEach((casilla) => {
            casilla.checked = marcar;
        });

        formulario.querySelectorAll('[data-tarjeta-permiso]').forEach(pintarTarjetaPermiso);
    });

    /* --- Controles + / - de cantidad ------------------------------------- */

    document.addEventListener('click', (evento) => {
        const boton = evento.target.closest('[data-paso]');

        if (!boton) {
            return;
        }

        const campo = document.getElementById(boton.dataset.objetivo);

        if (!campo) {
            return;
        }

        const paso = Number(boton.dataset.paso);
        const minimo = Number(campo.min || 0);
        const maximo = campo.max ? Number(campo.max) : Infinity;
        const actual = Number(campo.value || 0);

        campo.value = Math.min(maximo, Math.max(minimo, actual + paso));
        campo.dispatchEvent(new Event('change', { bubbles: true }));
    });

    /* --- Selector de color enlazado al campo de texto -------------------- */

    /* El hex se sigue escribiendo a mano en el input de texto (es el que se
       envia); el selector nativo solo lo acompana en las dos direcciones. */
    document.querySelectorAll('[data-objetivo-color]').forEach((selector) => {
        const campo = document.getElementById(selector.dataset.objetivoColor);

        if (!campo) {
            return;
        }

        selector.addEventListener('input', () => {
            campo.value = selector.value.toUpperCase();
        });

        campo.addEventListener('input', () => {
            if (/^#[0-9A-Fa-f]{6}$/.test(campo.value)) {
                selector.value = campo.value;
            }
        });
    });

    /* --- Cuadro combinado con busqueda: Solicitante ---------------------- */

    /* Patron combobox de ARIA 1.2: el foco se queda en el cuadro de texto y la
       opcion activa se anuncia con aria-activedescendant. Lo que viaja en el
       formulario es el id del campo oculto; el texto no lleva name. Mientras
       haya texto sin una opcion elegida, setCustomValidity frena el envio en
       el navegador (el servidor valida igual). La lista se arma con
       textContent: los nombres vienen de la base, nunca como HTML. */
    document.querySelectorAll('[data-combo-solicitante]').forEach((combo) => {
        const texto = combo.querySelector('[data-combo-texto]');
        const valor = combo.querySelector('[data-combo-valor]');
        const lista = combo.querySelector('[data-combo-lista]');
        const estado = combo.querySelector('[data-combo-estado]');
        const url = combo.dataset.url;
        const minimo = Number(combo.dataset.minimo || 2);
        const mensajeSinElegir = 'Seleccione su nombre de la lista.';

        if (!texto || !valor || !lista || !url) {
            return;
        }

        let opciones = [];
        let activa = -1;
        let temporizador = null;
        let peticion = null;
        let nombreElegido = valor.value ? texto.value : '';

        function anunciar(mensaje) {
            if (estado) {
                estado.textContent = mensaje;
            }
        }

        function cerrar() {
            lista.hidden = true;
            texto.setAttribute('aria-expanded', 'false');
            texto.removeAttribute('aria-activedescendant');
            activa = -1;
        }

        function abrir() {
            lista.hidden = false;
            texto.setAttribute('aria-expanded', 'true');
        }

        function marcarActiva(indice) {
            opciones.forEach((opcion, i) => {
                const esActiva = i === indice;
                opcion.classList.toggle('activa', esActiva);
                opcion.setAttribute('aria-selected', esActiva ? 'true' : 'false');
            });

            activa = indice;

            if (indice >= 0 && opciones[indice]) {
                texto.setAttribute('aria-activedescendant', opciones[indice].id);
                opciones[indice].scrollIntoView({ block: 'nearest' });
            } else {
                texto.removeAttribute('aria-activedescendant');
            }
        }

        function elegir(opcion) {
            nombreElegido = opcion.dataset.nombre;
            texto.value = nombreElegido;
            valor.value = opcion.dataset.id;
            texto.setCustomValidity('');
            texto.classList.remove('is-invalid');
            cerrar();
            anunciar(`Seleccionado: ${nombreElegido}`);
        }

        function mensajeEnLista(mensaje) {
            lista.replaceChildren();
            const nodo = document.createElement('li');
            nodo.className = 'combo-vacio';
            nodo.textContent = mensaje;
            lista.appendChild(nodo);
            opciones = [];
            abrir();
            anunciar(mensaje);
        }

        function pintar(resultados) {
            lista.replaceChildren();
            activa = -1;

            if (resultados.length === 0) {
                mensajeEnLista('No se encontraron solicitantes con ese nombre.');
                return;
            }

            opciones = resultados.map((resultado, i) => {
                const opcion = document.createElement('li');
                opcion.id = `${lista.id}-op-${i}`;
                opcion.setAttribute('role', 'option');
                opcion.setAttribute('aria-selected', 'false');
                opcion.dataset.id = String(resultado.id);
                opcion.dataset.nombre = resultado.nombre;
                opcion.textContent = resultado.nombre;

                if (resultado.area) {
                    const area = document.createElement('span');
                    area.className = 'combo-area';
                    area.textContent = resultado.area;
                    opcion.appendChild(area);
                }

                lista.appendChild(opcion);
                return opcion;
            });

            abrir();
            anunciar(`${resultados.length} resultado(s). Use las flechas para elegir y Enter para seleccionar.`);
        }

        async function buscar(termino) {
            peticion?.abort();
            peticion = new AbortController();

            try {
                const respuesta = await fetch(`${url}?q=${encodeURIComponent(termino)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: peticion.signal,
                });

                if (respuesta.status === 429) {
                    mensajeEnLista('Demasiadas busquedas seguidas. Espere un momento e intente de nuevo.');
                    return;
                }

                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }

                const cuerpo = await respuesta.json();

                // Una respuesta que llega cuando el texto ya cambio no se pinta.
                if (texto.value.trim() === termino) {
                    pintar(Array.isArray(cuerpo.datos) ? cuerpo.datos : []);
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    mensajeEnLista('No se pudo buscar. Revise su conexion e intente de nuevo.');
                }
            }
        }

        texto.addEventListener('input', () => {
            const termino = texto.value.trim();

            // Cambiar el texto invalida la eleccion anterior.
            if (texto.value !== nombreElegido) {
                valor.value = '';
                nombreElegido = '';
            }

            texto.setCustomValidity(termino !== '' && !valor.value ? mensajeSinElegir : '');
            clearTimeout(temporizador);

            if (termino.length < minimo) {
                peticion?.abort();
                cerrar();
                anunciar(termino === '' ? '' : `Escriba al menos ${minimo} letras para buscar.`);
                return;
            }

            temporizador = setTimeout(() => buscar(termino), 300);
        });

        texto.addEventListener('keydown', (evento) => {
            const abierta = !lista.hidden && opciones.length > 0;

            switch (evento.key) {
                case 'ArrowDown':
                    if (opciones.length > 0) {
                        evento.preventDefault();
                        abrir();
                        marcarActiva(activa < opciones.length - 1 ? activa + 1 : 0);
                    }
                    break;
                case 'ArrowUp':
                    if (opciones.length > 0) {
                        evento.preventDefault();
                        abrir();
                        marcarActiva(activa > 0 ? activa - 1 : opciones.length - 1);
                    }
                    break;
                case 'Enter':
                    // Con la lista abierta, Enter elige; nunca envia el
                    // formulario a medio elegir.
                    if (abierta) {
                        evento.preventDefault();

                        if (activa >= 0) {
                            elegir(opciones[activa]);
                        } else if (opciones.length === 1) {
                            elegir(opciones[0]);
                        }
                    }
                    break;
                case 'Escape':
                    if (!lista.hidden) {
                        evento.preventDefault();
                        cerrar();
                    }
                    break;
                default:
                    break;
            }
        });

        // mousedown con preventDefault: el cuadro no pierde el foco antes del
        // click (sin esto, el blur cerraria la lista y el click se perderia).
        lista.addEventListener('mousedown', (evento) => evento.preventDefault());

        lista.addEventListener('click', (evento) => {
            const opcion = evento.target.closest('[role="option"]');

            if (opcion) {
                elegir(opcion);
                texto.focus();
            }
        });

        texto.addEventListener('blur', () => cerrar());

        // Al enviar con texto pero sin elegir, el mensaje del navegador lo dice.
        texto.form?.addEventListener('submit', (evento) => {
            if (texto.value.trim() !== '' && !valor.value) {
                evento.preventDefault();
                texto.setCustomValidity(mensajeSinElegir);
                texto.reportValidity();
            }
        });
    });

    /* --- Mostrar toasts que vienen del servidor (sesion flash) ----------- */

    document.querySelectorAll('.toast[data-autoshow]').forEach((nodo) => {
        new bootstrap.Toast(nodo, { delay: 5000 }).show();
    });
})();
