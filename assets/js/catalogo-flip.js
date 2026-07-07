// assets/js/catalogo-flip.js
// PDF.js disegna le pagine su <canvas> ad alta risoluzione. Desktop/tablet:
// flipbook (StPageFlip, loadFromHTML). Telefono: scorrimento verticale.
// In caso di errore, fallback all'iframe del PDF.

import * as pdfjsLib from './pdf.mjs';

const thisScript = document.getElementById('catalogo-flip-script');
const PDF_URL    = thisScript.dataset.pdfUrl;
const WORKER_URL = thisScript.dataset.worker;
console.log('PDF_URL =', PDF_URL, '| script trovato:', !!thisScript)

pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_URL;

const wrap     = document.getElementById('flip-wrap');
const loading  = document.getElementById('flip-loading');
const elBook   = document.getElementById('flipbook');
const controls = document.getElementById('flip-controls');
const btnPrev  = document.getElementById('flip-prev');
const btnNext  = document.getElementById('flip-next');
const lblPage  = document.getElementById('flip-page');

const viewport     = document.getElementById('flip-viewport');
const btnZoomIn    = document.getElementById('flip-zoom-in');
const btnZoomOut   = document.getElementById('flip-zoom-out');
const btnZoomReset = document.getElementById('flip-zoom-reset');

// --------------------------------------------------------------------------
// ZOOM — flip: transform scale sul libro. scroll: larghezza pagine > 100%.
// --------------------------------------------------------------------------
const ZOOM_MIN  = 1;
const ZOOM_MAX  = 3;
const ZOOM_STEP = 0.25;
let zoom = 1;
let mode = 'flip';   // 'flip' (desktop/tablet) | 'scroll' (telefono)

function applyZoom() {
    if (mode === 'scroll') {
        // Le pagine si allargano oltre il 100%; il viewport scorre in
        // orizzontale. Lo scroll verticale resta quello nativo del telefono.
        elBook.style.width = (100 * zoom) + '%';
   } else {
        elBook.style.transformOrigin = 'top left';
        elBook.style.transform = 'scale(' + zoom + ')';
    }
    // Manina "grab" quando si può trascinare (zoom attivo), in ENTRAMBE le modalità.
    viewport.style.cursor = (zoom > 1) ? 'grab' : '';
    btnZoomReset.textContent = Math.round(zoom * 100) + '%';
    const atMin = (zoom <= ZOOM_MIN);
    const atMax = (zoom >= ZOOM_MAX);
    btnZoomOut.disabled = atMin;
    btnZoomIn.disabled  = atMax;
    btnZoomOut.style.opacity = atMin ? '0.4' : '1';
    btnZoomIn.style.opacity  = atMax ? '0.4' : '1';
}
function zoomIn()    { zoom = Math.min(ZOOM_MAX, zoom + ZOOM_STEP); applyZoom(); }
function zoomOut()   { zoom = Math.max(ZOOM_MIN, zoom - ZOOM_STEP); applyZoom(); }
function zoomReset() { zoom = 1; applyZoom(); }

// --------------------------------------------------------------------------
// PAN col mouse (solo flipbook, zoom > 1): trascinare sposta il libro.
// --------------------------------------------------------------------------
let isPanning = false;
let panStartX = 0, panStartY = 0, panLeft0 = 0, panTop0 = 0;

// Su telefono vero (touch) lasciamo lo scorrimento nativo del dito e NON
// attiviamo il pan col mouse, per evitare movimenti doppi.
let touchActive = false;
window.addEventListener('touchstart', function () { touchActive = true; }, { passive: true });

viewport.addEventListener('mousedown', function (e) {
    if (touchActive || zoom <= 1 || e.button !== 0) return;   // pan col mouse, solo zoomato
    e.preventDefault();
    if (mode !== 'scroll') e.stopPropagation();               // in flip: impedisce lo sfoglio
    isPanning = true;
    panStartX = e.clientX;
    panStartY = e.clientY;
    panLeft0  = viewport.scrollLeft;
    panTop0   = (mode === 'scroll') ? window.scrollY : viewport.scrollTop;
    viewport.style.cursor = 'grabbing';
    document.body.style.userSelect = 'none';
}, true);

window.addEventListener('mousemove', function (e) {
    if (!isPanning) return;
    viewport.scrollLeft = panLeft0 - (e.clientX - panStartX);
    if (mode === 'scroll') {
        // In scorrimento il movimento verticale è a livello di pagina (window).
        window.scrollTo(0, panTop0 - (e.clientY - panStartY));
    } else {
        viewport.scrollTop = panTop0 - (e.clientY - panStartY);
    }
});

window.addEventListener('mouseup', function () {
    if (!isPanning) return;
    isPanning = false;
    viewport.style.cursor = (zoom > 1) ? 'grab' : '';
    document.body.style.userSelect = '';
});

viewport.addEventListener('click', function (e) {
    if (mode !== 'scroll' && zoom > 1) e.stopPropagation();
}, true);

// --------------------------------------------------------------------------
// REATTIVITÀ: la modalità è scelta al caricamento. Se attraversiamo i 768px
// (rotazione telefono, resize, devtools) ricarichiamo nella modalità giusta.
// Debounce per non ricaricare a raffica durante il trascinamento del bordo.
// --------------------------------------------------------------------------
let wasMobile = window.innerWidth < 768;
let resizeTimer;
window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
        const nowMobile = window.innerWidth < 768;
        if (nowMobile !== wasMobile) {
            wasMobile = nowMobile;
            location.reload();
        }
    }, 250);
});

// Fallback robusto.
function fallbackIframe() {
    wrap.innerHTML =
        '<iframe src="' + PDF_URL + '" width="100%" height="800px" ' +
        'class="rounded-xl shadow border-0" title="Catalogo"></iframe>';
}

// --------------------------------------------------------------------------
// RENDER: ogni pagina su <canvas> ad alta risoluzione dentro un <div .page>.
// --------------------------------------------------------------------------
async function renderPages() {
    const pdf = await pdfjsLib.getDocument({ url: PDF_URL }).promise;

    const RENDER_BOOST = 2;
    const dpr   = Math.min(window.devicePixelRatio || 1, 2);
    const MAX_W = 2600;

    const pages = [];
    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);

        let scale = dpr * RENDER_BOOST;
        const baseW = page.getViewport({ scale: 1 }).width;
        if (baseW * scale > MAX_W) scale = MAX_W / baseW;

        const vp = page.getViewport({ scale });

        const canvas = document.createElement('canvas');
        canvas.width  = vp.width;
        canvas.height = vp.height;
        canvas.style.width   = '100%';
        canvas.style.height  = '100%';
        canvas.style.display = 'block';

        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport: vp }).promise;

        const pageEl = document.createElement('div');
        pageEl.className = 'page';
        pageEl.style.width  = '100%';
        pageEl.style.height = '100%';
        pageEl.appendChild(canvas);

        pages.push(pageEl);
    }
    return pages;
}

// --------------------------------------------------------------------------
// FLIP (desktop/tablet)
// --------------------------------------------------------------------------
function initFlip(pages) {
    mode = 'flip';
    const isMobile = window.innerWidth < 768;
    const W = isMobile ? 380 : 500;
    const H = Math.round(W * 1.414);

    loading.style.display  = 'none';
    elBook.style.display   = 'block';
    controls.style.display = 'flex';

    pages.forEach(p => elBook.appendChild(p));

    const pageFlip = new St.PageFlip(elBook, {
        width: W,
        height: H,
        size: 'stretch',
        minWidth: 300,
        maxWidth: 1000,
        minHeight: 400,
        maxHeight: 1414,
        maxShadowOpacity: 0.5,
        showCover: true,
        mobileScrollSupport: true,
        usePortrait: isMobile,
    });

    pageFlip.loadFromHTML(pages);

    const total = pages.length;
    function refreshLabel() {
        lblPage.textContent = (pageFlip.getCurrentPageIndex() + 1) + ' / ' + total;
    }
    refreshLabel();

    btnPrev.addEventListener('click', () => pageFlip.flipPrev());
    btnNext.addEventListener('click', () => pageFlip.flipNext());
    pageFlip.on('flip', refreshLabel);

    btnZoomIn.addEventListener('click', zoomIn);
    btnZoomOut.addEventListener('click', zoomOut);
    btnZoomReset.addEventListener('click', zoomReset);
    applyZoom();
}

// --------------------------------------------------------------------------
// SCROLL (telefono): pagine impilate verticalmente. Mostriamo solo lo zoom.
// --------------------------------------------------------------------------
function initScroll(pages) {
    mode = 'scroll';
    loading.style.display  = 'none';

    // Controlli: solo zoom (Indietro/Avanti e numero pagina non servono).
    controls.style.display = 'flex';
    if (btnPrev.parentElement) btnPrev.parentElement.style.display = 'none';
    lblPage.style.display = 'none';

    elBook.style.display   = 'block';
    elBook.style.transform = 'none';
    elBook.classList.add('flip-scroll');

    // Pannello zoom flottante + pulsante per aprirlo/chiuderlo (solo scroll).
    controls.classList.add('flip-menu-floating');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'flip-menu-toggle';
    toggle.setAttribute('aria-label', 'Mostra/nascondi zoom');
    toggle.textContent = '🔍';
    toggle.addEventListener('click', function () {
        controls.classList.toggle('is-open');
        toggle.textContent = controls.classList.contains('is-open') ? '✕' : '🔍';
    });
    controls.parentElement.appendChild(toggle);

    pages.forEach(p => {
        p.style.height = 'auto';
        const c = p.querySelector('canvas');
        if (c) c.style.height = 'auto';
        elBook.appendChild(p);
    });

    btnZoomIn.addEventListener('click', zoomIn);
    btnZoomOut.addEventListener('click', zoomOut);
    btnZoomReset.addEventListener('click', zoomReset);
    applyZoom();
}

(async function () {
    try {
        if (typeof St === 'undefined' || !St.PageFlip) {
            fallbackIframe();
            return;
        }
        const pages = await renderPages();
        if (!pages.length) {
            fallbackIframe();
            return;
        }
        if (window.innerWidth < 768) {
            initScroll(pages);
        } else {
            initFlip(pages);
        }
    } catch (e) {
        console.error('Flipbook non disponibile:', e);
        fallbackIframe();
    }
})();