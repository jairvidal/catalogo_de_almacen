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
                mostrarAviso(datos.mensaje ?? 'No se pudo actualizar el stock.', 'danger');
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

    /* --- Mostrar toasts que vienen del servidor (sesion flash) ----------- */

    document.querySelectorAll('.toast[data-autoshow]').forEach((nodo) => {
        new bootstrap.Toast(nodo, { delay: 5000 }).show();
    });
})();
