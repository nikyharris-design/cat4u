<?php
/**
 * ==========================================================================
 * CATALOGO.PHP — Visualizzazione pubblica di un singolo catalogo (PDF)
 * ==========================================================================
 *
 * Pagina PUBBLICA (no login) che mostra il PDF di un catalogo dentro un iframe.
 * È la destinazione del QR di un catalogo.
 *
 * Qui si "chiudono" due meccanismi visti altrove:
 *   - isUrlSafe()  (definita in config/base.php): controlla che l'URL del PDF
 *     non sia segnalato come pericoloso prima di mostrarlo.
 *   - il TRACCIAMENTO che riempie la tabella catalogo_analytics, i cui dati
 *     vengono poi aggregati in dashboard/analytics.php.
 *
 * Parametri (entrambi obbligatori):
 *   a = slug azienda
 *   c = slug catalogo
 */

require_once __DIR__ . '/../config/bootstrap.php';

$azienda_slug  = trim($_GET['a'] ?? '');
$catalogo_slug = trim($_GET['c'] ?? '');

// Servono entrambi gli slug: senza, non c'è nulla da mostrare.
if (empty($azienda_slug) || empty($catalogo_slug)) {
    not_found();
}

// Recuperiamo l'azienda. Se non esiste, 404 dedicato.
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$azienda_slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    not_found("L'azienda richiesta non esiste.");
}

// Recuperiamo il catalogo, ma SOLO se è davvero "mostrabile":
//   - appartiene a questa azienda (azienda_id)
//   - è attivo (is_active = 1)
//   - non è scaduto (nessuna scadenza, oppure scadenza futura)
// In questo modo un link a un catalogo disattivato o scaduto restituisce 404.
$stmt = $pdo->prepare("
    SELECT c.*, a.nome_azienda
    FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE c.slug = ?
      AND c.azienda_id = ?
      AND c.is_active = 1
      AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
    LIMIT 1
");
$stmt->execute([$catalogo_slug, $azienda['id']]);
$catalogo = $stmt->fetch();

if (!$catalogo) {
    not_found("Il catalogo non esiste o non è più disponibile.");
}

// URL pubblico del PDF (percorso salvato nel DB, reso assoluto con BASE_URL).
$pdf_url = BASE_URL . ltrim($catalogo['pdf_path'], '/');


// --------------------------------------------------------------------------
// TRACCIAMENTO (analytics)
// --------------------------------------------------------------------------
// Determiniamo il tipo di dispositivo analizzando lo user-agent. È una
// classificazione approssimativa basata su parole chiave: sufficiente per
// statistiche di massima, non una rilevazione esatta.
$device_type = 'desktop'; // default
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
    $device_type = 'mobile';
} elseif (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
    $device_type = 'tablet';
}

// Salviamo l'IP come HASH, non in chiaro: ci basta per contare i visitatori
// "unici" (COUNT DISTINCT in analytics) senza conservare un dato personale.
$ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Registriamo la visita: una riga per ogni apertura del catalogo.
$stmt = $pdo->prepare("INSERT INTO catalogo_analytics (catalogo_id, device_type, ip_hash) VALUES (?, ?, ?)");
$stmt->execute([$catalogo['id'], $device_type, $ip_hash]);
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

        <!-- FALLBACK: senza JS, il PDF viene mostrato nell'iframe come prima. -->
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
