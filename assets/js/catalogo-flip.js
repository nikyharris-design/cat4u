// assets/js/catalogo-flip.js
// Renderizza il PDF in immagini con PDF.js e le sfoglia con StPageFlip.
// In caso di qualsiasi errore, ricade sull'iframe del PDF (stesso fallback
// del <noscript>), così l'utente vede comunque il catalogo.

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
// ZOOM
// Applichiamo uno scale CSS al flipbook (#flipbook = wrapper esterno di
// StPageFlip). Non ri-renderizziamo il PDF: le pagine sono già immagini nitide,
// quindi ingrandirle resta leggibile. Il viewport con overflow:auto permette di
// scorrere quando il libro esce dai bordi.
const ZOOM_MIN  = 1;     // 1 = pagina "a misura": non rimpiccioliamo sotto
const ZOOM_MAX  = 3;     // limite massimo
const ZOOM_STEP = 0.25;  // incremento per click
let zoom = 1;

function applyZoom() {
    elBook.style.transformOrigin = 'top center';
    elBook.style.transform = 'scale(' + zoom + ')';
    btnZoomReset.textContent = Math.round(zoom * 100) + '%';

    // Ai limiti disabilitiamo il bottone. L'opacità la gestiamo inline (non con
    // "disabled:opacity-*"): quella classe Tailwind potrebbe non essere nel CSS
    // compilato, l'inline invece è sempre affidabile.
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

// Se qualcosa va storto, mostriamo il PDF nell'iframe (fallback robusto).
function fallbackIframe() {
    wrap.innerHTML =
        '<iframe src="' + PDF_URL + '" width="100%" height="800px" ' +
        'class="rounded-xl shadow border-0" title="Catalogo"></iframe>';
}

// Rendering di tutte le pagine del PDF in dataURL PNG.
async function renderPages() {
    const pdf = await pdfjsLib.getDocument({ url: PDF_URL }).promise;
    const images = [];

   // Scala di rendering ADATTIVA e sensibile alla DENSITÀ dello schermo.
    // Su display retina/HiDPI un'immagine da 1400px viene comunque rimpicciolita
    // dal browser e appare morbida: qui moltiplichiamo per il devicePixelRatio
    // (limitato a 2 per non esagerare su alcuni telefoni). Un target più alto
    // resta nitido anche con lo zoom fino a ~2x.
    const dpr = Math.min(window.devicePixelRatio || 1, 2);

    const primaPagina   = await pdf.getPage(1);
    const larghezzaBase = primaPagina.getViewport({ scale: 1 }).width;

    // Larghezza REALE desiderata per pagina, in pixel fisici. Più alta = più
    // nitido ma più pesante in memoria: se hai cataloghi molto lunghi e noti
    // rallentamenti o crash su mobile, abbassa questo valore (es. 1600).
    const TARGET_W = 2200 * dpr;

    // Clamp: mai sotto 2 (nitido di base), mai sopra 4 (protezione memoria).
    const SCALE = Math.min(Math.max(TARGET_W / larghezzaBase, 2), 4);

    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);
        const viewport = page.getViewport({ scale: SCALE });

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width  = viewport.width;
        canvas.height = viewport.height;

        await page.render({ canvasContext: ctx, viewport }).promise;
        images.push(canvas.toDataURL('image/png'));
    }
    return images;
}

function initFlip(images) {
    const isMobile = window.innerWidth < 768;

    // Dimensioni di base: StPageFlip scala mantenendo le proporzioni.
    // Usiamo le proporzioni A4 verticali come riferimento.
    const W = isMobile ? 380 : 500;
    const H = Math.round(W * 1.414);

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
        // singola pagina su mobile, doppia su desktop
        usePortrait: isMobile,
    });

    pageFlip.loadFromImages(images);

    elBook.style.display   = 'block';
    controls.style.display = 'flex';
    loading.style.display  = 'none';

    const total = images.length;
    function refreshLabel() {
        lblPage.textContent = (pageFlip.getCurrentPageIndex() + 1) + ' / ' + total;
    }
    refreshLabel();

    btnPrev.addEventListener('click', () => pageFlip.flipPrev());
    btnNext.addEventListener('click', () => pageFlip.flipNext());
    pageFlip.on('flip', refreshLabel);

    // Zoom
    btnZoomIn.addEventListener('click', zoomIn);
    btnZoomOut.addEventListener('click', zoomOut);
    btnZoomReset.addEventListener('click', zoomReset);
    applyZoom(); // stato iniziale: 100%, bottone "−" disabilitato
}

(async function () {
    try {
        if (typeof St === 'undefined' || !St.PageFlip) {
            // StPageFlip non caricata → fallback.
            fallbackIframe();
            return;
        }
        const images = await renderPages();
        if (!images.length) {
            fallbackIframe();
            return;
        }
        initFlip(images);
    } catch (e) {
        console.error('Flipbook non disponibile:', e);
        fallbackIframe();
    }
})();