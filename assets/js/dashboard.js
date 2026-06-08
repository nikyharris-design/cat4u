/**
 * ==========================================================================
 * DASHBOARD.JS — Comportamenti dell'area riservata (CSP-friendly)
 * ==========================================================================
 *
 * Sostituisce gli handler inline (onchange / onsubmit / onmouseover) e lo
 * <script> inline di utenti.php, che la Content-Security-Policy stretta
 * (script-src 'self', senza 'unsafe-inline') blocca.
 *
 * Tutto viene agganciato via addEventListener al caricamento del DOM, leggendo
 * dei data-attribute sugli elementi HTML:
 *   - select[data-autosubmit]  → al change invia il form contenitore
 *   - form[data-confirm="..."] → chiede conferma prima dell'invio
 *   - [data-hover-bg]          → cambia lo sfondo al passaggio del mouse
 *   - #role-select             → mostra/nasconde #azienda-field (utenti.php)
 *
 * Va incluso da 'self' in fondo alle pagine:
 *   <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
 */
document.addEventListener('DOMContentLoaded', function () {

    // --- Auto-submit dei select di filtro (azienda / genere / catalogo) ---
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.form) sel.form.submit();
        });
    });

    // --- Conferma prima dell'invio (es. eliminazioni) ---
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // --- Hover programmatico per elementi con stile inline (link "Reset filtri") ---
    document.querySelectorAll('[data-hover-bg]').forEach(function (el) {
        var base  = el.getAttribute('data-base-bg');
        var hover = el.getAttribute('data-hover-bg');
        el.addEventListener('mouseenter', function () { el.style.background = hover; });
        el.addEventListener('mouseleave', function () { el.style.background = base; });
    });

    // --- utenti.php: il campo "Azienda" sparisce se il ruolo è superadmin ---
    var roleSelect = document.getElementById('role-select');
    if (roleSelect) {
        var toggleAzienda = function () {
            var field = document.getElementById('azienda-field');
            if (field) {
                field.style.display = (roleSelect.value === 'superadmin') ? 'none' : 'block';
            }
        };
        roleSelect.addEventListener('change', toggleAzienda);
        toggleAzienda(); // imposta lo stato iniziale al caricamento
    }
});