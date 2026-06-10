<?php
/**
 * ==========================================================================
 * PDF.PHP — "Guardiano" per la consegna controllata dei PDF
 * ==========================================================================
 *
 * I PDF non sono più scaricabili direttamente da uploads/pdf/ (vedi l'.htaccess
 * in quella cartella). L'unico modo per ottenerli è passare da qui, che ripete
 * gli STESSI controlli di catalogo.php prima di servire il file:
 *   - l'azienda esiste
 *   - il catalogo appartiene a quell'azienda, è attivo e non scaduto
 * Così un catalogo disattivato o scaduto non è più raggiungibile nemmeno
 * conoscendo l'URL diretto del PDF.
 *
 * Parametri (come catalogo.php):
 *   a = slug azienda
 *   c = slug catalogo
 */

require_once __DIR__ . '/../config/bootstrap.php';

$azienda_slug  = trim($_GET['a'] ?? '');
$catalogo_slug = trim($_GET['c'] ?? '');

// Senza i due slug non c'è nulla da servire.
if ($azienda_slug === '' || $catalogo_slug === '') {
    http_response_code(404);
    exit;
}

// Azienda dallo slug.
$stmt = $pdo->prepare("SELECT id FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$azienda_slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    http_response_code(404);
    exit;
}

// Catalogo "mostrabile": stessa identica logica di catalogo.php.
$stmt = $pdo->prepare("
    SELECT pdf_path
    FROM cataloghi
    WHERE slug = ?
      AND azienda_id = ?
      AND is_active = 1
      AND (data_scadenza IS NULL OR data_scadenza > NOW())
    LIMIT 1
");
$stmt->execute([$catalogo_slug, $azienda['id']]);
$catalogo = $stmt->fetch();

if (!$catalogo) {
    http_response_code(404);
    exit;
}

// Percorso assoluto del file su disco. Il path viene dal DB (non dall'utente),
// ma per sicurezza in più verifichiamo che sia DAVVERO dentro uploads/pdf:
// niente path traversal anche in caso di dato anomalo.
$base_dir = realpath(__DIR__ . '/../uploads/pdf');
$file     = realpath(__DIR__ . '/../' . $catalogo['pdf_path']);

if ($file === false || $base_dir === false
    || strpos($file, $base_dir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit;
}

// Svuotiamo eventuali buffer di output: il PDF deve arrivare "puro", senza
// che nulla venga accodato prima (altrimenti il file risulterebbe corrotto).
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Consegna del file. Niente analytics qui: il conteggio della visita avviene
// già in catalogo.php quando si apre la pagina del catalogo.
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="catalogo.pdf"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');

readfile($file);
exit;