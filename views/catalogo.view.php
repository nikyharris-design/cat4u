<?php
/**
 * ==========================================================================
 * CATALOGO.VIEW.PHP — Vista della pagina pubblica del singolo catalogo
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da public/catalogo.php dopo che il controller ha
 * preparato i dati e registrato la visita. Variabili già disponibili:
 * $azienda, $catalogo, $pdf_url (URL del guardiano pdf.php, non del file).
 *
 * La barra "Torna alla dashboard" compare solo se la sessione è autenticata,
 * leggendo direttamente $_SESSION. Pagina PUBBLICA. Non va mai aperta
 * direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($catalogo['titolo']) ?> — <?= htmlspecialchars($catalogo['nome_azienda']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">

   

    <!-- Header pubblico con link di ritorno alla libreria dell'azienda. -->
    <header class="bg-indigo-600 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
            <!-- Il nome azienda riporta alla home pubblica (URL pulito). -->
            <a href="<?= BASE_URL . htmlspecialchars($azienda['slug']) ?>"
               class="font-bold text-lg hover:text-indigo-200 transition">
                <?= htmlspecialchars($catalogo['nome_azienda']) ?>
            </a>
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>"
               class="text-indigo-200 hover:text-white text-sm transition">
                ← Tutti i cataloghi
            </a>
        </div>
    </header>

    <main class="max-w-5xl mx-auto py-6 px-4">
        <h1 class="text-xl font-bold text-gray-800 mb-1"><?= htmlspecialchars($catalogo['titolo']) ?></h1>
        <?php if ($catalogo['data_scadenza']): ?>
            <p class="text-xs text-gray-400 mb-4">Valido fino al <?= date('d/m/Y', strtotime($catalogo['data_scadenza'])) ?></p>
        <?php endif; ?>

        <!-- Contenitore del flipbook. Popolato da JS. Resta vuoto se JS è
             disattivato: in quel caso si mostra il <noscript> con l'iframe. -->
        <div id="flip-wrap" class="select-none">
            <div id="flip-loading" class="text-center text-gray-400 text-sm py-12">
                Caricamento catalogo…
            </div>
            <div id="flipbook" style="display:none;"></div>

            <!-- Controlli di navigazione. -->
            <div id="flip-controls" class="flex items-center justify-center gap-4 mt-4" style="display:none;">
                <button id="flip-prev" type="button"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    ← Indietro
                </button>
                <span id="flip-page" class="text-sm text-gray-500"></span>
                <button id="flip-next" type="button"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    Avanti →
                </button>
            </div>
        </div>

        <!-- FALLBACK: senza JS, il PDF viene mostrato nell'iframe. -->
        <noscript>
            <iframe
                src="<?= htmlspecialchars($pdf_url) ?>"
                width="100%"
                height="800px"
                class="rounded-xl shadow border-0"
                title="<?= htmlspecialchars($catalogo['titolo']) ?>">
            </iframe>
        </noscript>
    </main>

    <!-- L'URL del PDF è passato via data-attribute (già escapato), non
         interpolato dentro lo script: compatibile con CSP senza unsafe-inline. -->
    <script src="<?= BASE_URL ?>assets/js/page-flip.browser.js"></script>
    <script type="module"
            id="catalogo-flip-script"
            src="<?= BASE_URL ?>assets/js/catalogo-flip.js"
            data-pdf-url="<?= htmlspecialchars($pdf_url) ?>"
            data-worker="<?= BASE_URL ?>assets/js/pdf.worker.mjs"></script>
</body>
</html>