/**
 * Amir Booking — Admin JS
 * Funcionalidad JS para el panel de administración.
 * No depende de React; las páginas admin son PHP nativo.
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {

        // ── Confirmar acciones destructivas ──────────────────────────────
        document.querySelectorAll( '[data-confirm]' ).forEach( function ( el ) {
            el.addEventListener( 'click', function ( e ) {
                if ( ! confirm( el.dataset.confirm ) ) {
                    e.preventDefault();
                }
            } );
        } );

        // ── Copiar al portapapeles ────────────────────────────────────────
        document.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var text = btn.dataset.copy || btn.previousElementSibling?.textContent || '';
                if ( navigator.clipboard && text ) {
                    navigator.clipboard.writeText( text.trim() ).then( function () {
                        var orig = btn.textContent;
                        btn.textContent = '✓ Copiado';
                        setTimeout( function () { btn.textContent = orig; }, 2000 );
                    } );
                }
            } );
        } );

        // ── Notificaciones: polling cada 60 s para actualizar badge ───────
        if ( typeof amirAdminData !== 'undefined' ) {
            var badgeEl = document.querySelector( '.amir-badge' );
            if ( badgeEl ) {
                setInterval( function () {
                    fetch( amirAdminData.apiUrl + 'notifications', {
                        headers: { 'X-WP-Nonce': amirAdminData.nonce }
                    } )
                    .then( function (r) { return r.ok ? r.json() : null; } )
                    .then( function (data) {
                        if ( data && typeof data.count !== 'undefined' ) {
                            badgeEl.textContent = data.count;
                            badgeEl.style.display = data.count > 0 ? 'inline-block' : 'none';
                        }
                    } )
                    .catch( function () {} );
                }, 60000 );
            }
        }

    } );

} )();
