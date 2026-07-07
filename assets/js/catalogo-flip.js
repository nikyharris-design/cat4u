// assets/js/catalogo-flip.js
// Renderizza il PDF con PDF.js direttamente su <canvas> ad alta risoluzione e
// li sfoglia con StPageFlip (modalità HTML: loadFromHTML). Disegnare su canvas
// ad alta densità mantiene testo e immagini nitidi anche in zoom.
// In caso di errore, ricade sull'iframe del PDF (come il <noscript>).

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

// Elementi dello zoom.
const viewport     = document.getElementById('flip-viewport');
const btnZoomIn    = document.getElementById('flip-zoom-in');
const btnZoomOut   = document.getElementById('flip-zoom-out');
const btnZoomReset = document.getElementById('flip-zoom-reset');

// --------------------------------------------------------------------------
// ZOOM (invariato: transform scale sul libro + scroll nel viewport)
// --------------------------------------------------------------------------
const ZOOM_MIN  = 1;
const ZOOM_MAX  = 3;
const ZOOM_STEP = 0.25;
let zoom = 1;

function applyZoom() {
    elBook.style.transformOrigin = 'top left';
    elBook.style.transform = 'scale(' + zoom + ')';
    btnZoomReset.textContent = Math.round(zoom * 100) + '%';
    const atMin = (zoom <= ZOOM_MIN);
    const atMax = (zoom >= ZOOM_MAX);
    btnZoomOut.disabled = atMin;
    btnZoomIn.disabled  = atMax;
   btnZoomOut.style.opacity = atMin ? '0.4' : '1';
    btnZoomIn.style.opacity  = atMax ? '0.4' : '1';
    viewport.style.cursor = (zoom > 1) ? 'grab' : '';
}
function zoomIn()    { zoom = Math.min(ZOOM_MAX, zoom + ZOOM_STEP); applyZoom(); }
function zoomOut()   { zoom = Math.max(ZOOM_MIN, zoom - ZOOM_STEP); applyZoom(); }
function zoomReset() { zoom = 1; applyZoom(); }

// --------------------------------------------------------------------------
// PAN — quando lo zoom è attivo (>100%), trascinare col mouse SPOSTA il libro
// invece di sfogliarlo. Intercettiamo il mousedown in fase di CATTURA sul
// viewport e fermiamo la propagazione, così StPageFlip non avvia lo sfoglio;
// poi muoviamo lo scroll del viewport seguendo il trascinamento.
// A 100% non tocchiamo nulla: lo sfoglio normale resta invariato.
// --------------------------------------------------------------------------
let isPanning = false;
let panStartX = 0, panStartY = 0, panLeft0 = 0, panTop0 = 0;

viewport.addEventListener('mousedown', function (e) {
    if (zoom <= 1 || e.button !== 0) return;   // solo zoomato, solo tasto sinistro
    e.preventDefault();
    e.stopPropagation();                        // impedisce a StPageFlip di sfogliare
    isPanning = true;
    panStartX = e.clientX;
    panStartY = e.clientY;
    panLeft0  = viewport.scrollLeft;
    panTop0   = viewport.scrollTop;
    viewport.style.cursor = 'grabbing';
    document.body.style.userSelect = 'none';
}, true);   // true = fase di CATTURA (scatta prima di StPageFlip)

window.addEventListener('mousemove', function (e) {
    if (!isPanning) return;
    viewport.scrollLeft = panLeft0 - (e.clientX - panStartX);
    viewport.scrollTop  = panTop0  - (e.clientY - panStartY);
});

window.addEventListener('mouseup', function () {
    if (!isPanning) return;
    isPanning = false;
    viewport.style.cursor = (zoom > 1) ? 'grab' : '';
    document.body.style.userSelect = '';
});

// Mentre siamo zoomati, un click sul libro non deve sfogliare (solo pan).
viewport.addEventListener('click', function (e) {
    if (zoom > 1) e.stopPropagation();
}, true);

// Se qualcosa va storto, mostriamo il PDF nell'iframe (fallback robusto).
function fallbackIframe() {
    wrap.innerHTML =
        '<iframe src="' + PDF_URL + '" width="100%" height="800px" ' +
        'class="rounded-xl shadow border-0" title="Catalogo"></iframe>';
}

// --------------------------------------------------------------------------
// RENDER: ogni pagina PDF è disegnata su un <canvas> ad alta risoluzione,
// racchiuso in un <div class="page">. Restituiamo gli elementi pagina (non
// immagini): StPageFlip li usa in modalità HTML con loadFromHTML.
// --------------------------------------------------------------------------
async function renderPages() {
    const pdf = await pdfjsLib.getDocument({ url: PDF_URL }).promise;

    // Boost di risoluzione: densità schermo (devicePixelRatio, max 2) × fattore
    // extra. Il canvas ha PIÙ pixel fisici di quelli visualizzati → testo
    // nitido anche allo zoom massimo.
    const RENDER_BOOST = 2;
    const dpr   = Math.min(window.devicePixelRatio || 1, 2);
    const MAX_W = 2600; // tetto pixel fisici per pagina (memoria sicura)

    const pages = [];
    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);

        // Scala dpr × boost, ridotta se supererebbe il tetto MAX_W.
        let scale = dpr * RENDER_BOOST;
        const baseW = page.getViewport({ scale: 1 }).width;
        if (baseW * scale > MAX_W) scale = MAX_W / baseW;

        const vp = page.getViewport({ scale });

        const canvas = document.createElement('canvas');
        canvas.width  = vp.width;    // risoluzione INTERNA (alta)
        canvas.height = vp.height;
        canvas.style.width   = '100%';   // dimensione VISIVA (scalata dal box pagina)
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

function initFlip(pages) {
    const isMobile = window.innerWidth < 768;
    const W = isMobile ? 380 : 500;
    const H = Math.round(W * 1.414);

    // Contenitore visibile PRIMA di init, così 'stretch' misura la larghezza.
    loading.style.display = 'none';
    elBook.style.display  = 'block';

    // Le pagine devono stare nel DOM come figli del contenitore.
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

    // Modalità HTML: StPageFlip usa i nostri <div class="page"> con i canvas.
    pageFlip.loadFromHTML(pages);

    controls.style.display = 'flex';

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
        initFlip(pages);
    } catch (e) {
        console.error('Flipbook non disponibile:', e);
        fallbackIframe();
    }
})();