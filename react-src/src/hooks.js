import { useState, useEffect, useRef } from 'react';

// Devuelve `value` recién `delayMs` después de que dejó de cambiar — evita
// disparar un request HTTP por cada click de un contador +/- (bug real
// 2026-08-09: una ráfaga de taps en el paso de personas mandaba una
// cotización por click, y a veces la respuesta que llegaba última no era la
// del último click). Usado junto con un ref de "request-id" en el propio
// call site (no acá) para además descartar respuestas que ya quedaron
// obsoletas cuando SÍ llegan fuera de orden.
export function useDebouncedValue( value, delayMs = 350 ) {
  const [ debounced, setDebounced ] = useState( value );

  useEffect( () => {
    const id = setTimeout( () => setDebounced( value ), delayMs );
    return () => clearTimeout( id );
  }, [ value, delayMs ] );

  return debounced;
}

// ── Auto-scroll en mobile hacia donde el usuario tiene que prestar atención ──
// Pedido del cliente 2026-08-24: en mobile el viewport es chico y el widget
// puede ocupar varias pantallas de alto — un error de validación o un
// cambio de paso puede quedar arriba o abajo del scroll actual, invisible,
// sin que el usuario entienda por qué "no pasa nada" al tocar un botón.
// Deliberadamente NO actúa en desktop (breakpoint 640px, el mismo que ya
// usan TourList/WishlistList/RoomList/shared.jsx en sus propios @media) —
// ahí el widget entero suele entrar en el viewport de un vistazo, forzar
// scroll sería más molesto que útil.
function scrollToMobile( el, block = 'start' ) {
  if ( ! el || typeof window === 'undefined' || window.innerWidth > 640 ) return;
  requestAnimationFrame( () => el.scrollIntoView( { behavior: 'smooth', block } ) );
}

// Scrollea al primer `.ab-error-banner` dentro de `containerRef` apenas
// aparece uno nuevo (o cambia su texto — dos errores seguidos distintos
// deben volver a llamar la atención). Corre después de CADA render a
// propósito (sin array de dependencias): un error de validación puede
// aparecer sin que cambie ningún estado que este hook conozca de antemano
// (cada Step banner su propio `error`/`quoteError` local) — comparar contra
// el DOM real evita tener que cablear esta función a cada `setError` de
// cada paso por separado.
export function useScrollToErrorOnMobile( containerRef ) {
  const lastTextRef = useRef( '' );
  useEffect( () => {
    const root = containerRef.current;
    if ( ! root ) return;
    const banner = root.querySelector( '.ab-error-banner' );
    const text = banner ? banner.textContent : '';
    if ( banner && text !== lastTextRef.current ) {
      lastTextRef.current = text;
      scrollToMobile( banner, 'center' );
    } else if ( ! banner ) {
      lastTextRef.current = '';
    }
  } );
}

// Scrollea al inicio de `containerRef` cada vez que `watchKey` cambia (un
// índice/nombre de paso, o cualquier otro valor que identifique "se pasó a
// una pantalla nueva"). Separado de `useScrollToErrorOnMobile` a propósito:
// si en el mismo cambio de paso ya apareciera un error (caso raro), el
// efecto de error corre en su propio render posterior y termina ganando —
// es el punto más relevante para la atención del usuario en ese momento.
export function useScrollToTopOnChange( containerRef, watchKey ) {
  useEffect( () => {
    scrollToMobile( containerRef.current, 'start' );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ watchKey ] );
}
