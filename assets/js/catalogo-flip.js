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

    // Scala di rendering: più alta = più nitido ma più pesante. 1.5 è un buon
    // compromesso per la lettura a schermo.
    const SCALE = 1.5;

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