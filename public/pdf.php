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

// --------------------------------------------------------------------------
// METADATI DEL FILE (per cache e range)
// --------------------------------------------------------------------------
// Dimensione e data di modifica servono sia per gli header di cache sia per
// calcolare/validare le richieste parziali (Range).
$fileSize = filesize($file);
$mtime    = filemtime($file);

// ETag stabile ma leggero: dipende solo da data di modifica + dimensione, non
// richiede di leggere l'intero file. I PDF qui sono IMMUTABILI (il nome include
// il timestamp: un file ricaricato prende un nome nuovo), quindi mtime+size
// identifica il contenuto in modo affidabile.
$etag = '"' . md5($mtime . '-' . $fileSize) . '"';

// --------------------------------------------------------------------------
// HEADER DI CACHE
// --------------------------------------------------------------------------
// La sessione avviata in base.php imposta di default header anti-cache
// (Pragma / Expires): li rimuoviamo, altrimenti annullerebbero il caching.
header_remove('Pragma');
header_remove('Expires');

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="catalogo.pdf"');
header('X-Content-Type-Options: nosniff');

// Accept-Ranges: dichiara che accettiamo richieste parziali. È ciò che permette
// a PDF.js di scaricare solo le pagine che servono invece dell'intero file.
header('Accept-Ranges: bytes');

// private: cache del singolo browser, non di proxy/CDN condivisi (il PDF è
//          dietro un controllo di autorizzazione, meglio non farlo trattenere altrove).
// immutable: il contenuto di questo URL non cambia mai (vedi nota sull'ETag).
// max-age: 1 giorno. Volutamente non troppo lungo: se un catalogo viene
//          disattivato, una copia già in cache resta raggiungibile fino a scadenza.
header('Cache-Control: private, max-age=86400, immutable');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

// --------------------------------------------------------------------------
// RICHIESTE CONDIZIONALI (304 Not Modified)
// --------------------------------------------------------------------------
// Se il browser ha già il file (stesso ETag, o non modificato dalla data nota),
// rispondiamo 304 senza corpo: risparmio di banda totale.
// Per specifica: se è presente If-None-Match si valuta SOLO quello.
$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModSince  = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

$notModified = false;
if ($ifNoneMatch !== '') {
    $notModified = ($ifNoneMatch === $etag);
} elseif ($ifModSince !== '') {
    $since = strtotime($ifModSince);
    $notModified = ($since !== false && $since >= $mtime);
}
if ($notModified) {
    http_response_code(304);
    exit;
}

// --------------------------------------------------------------------------
// GESTIONE RANGE (206 Partial Content)
// --------------------------------------------------------------------------
// Di default serviamo l'intero file [0 .. fileSize-1].
$start = 0;
$end   = $fileSize - 1;
$isPartial = false;

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
// Supportiamo un SOLO range "bytes=inizio-fine". I range multipli (separati da
// virgola) non sono gestiti: in quel caso ignoriamo l'header e serviamo tutto,
// comportamento consentito dalla specifica.
if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
    $rStart = $m[1];
    $rEnd   = $m[2];

    if ($rStart === '' && $rEnd === '') {
        // "bytes=-": privo di significato.
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }

    if ($rStart === '') {
        // "bytes=-N": gli ultimi N byte del file.
        $length = (int)$rEnd;
        if ($length > $fileSize) {
            $length = $fileSize;
        }
        $start = $fileSize - $length;
        $end   = $fileSize - 1;
    } else {
        // "bytes=N-" oppure "bytes=N-M".
        $start = (int)$rStart;
        $end   = ($rEnd === '') ? $fileSize - 1 : (int)$rEnd;
    }

    // Un last-byte oltre la fine NON è un errore: equivale a "fino in fondo".
    if ($end >= $fileSize) {
        $end = $fileSize - 1;
    }

    // 416 solo se lo start è oltre la fine o l'intervallo è invertito.
    if ($start > $end || $start < 0 || $start >= $fileSize) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }

    $isPartial = true;
}

// --------------------------------------------------------------------------
// CONSEGNA DEL FILE
// --------------------------------------------------------------------------
// Niente analytics qui: il conteggio della visita avviene già in catalogo.php
// quando si apre la pagina del catalogo.
$length = $end - $start + 1;

if ($isPartial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}
header('Content-Length: ' . $length);

// Lettura a blocchi (8KB): serviamo solo la porzione richiesta senza caricare
// l'intero PDF in memoria. Sostituisce readfile(), che non sa gestire i range.
$fh = fopen($file, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fseek($fh, $start);
$bytesLeft = $length;
$chunkSize = 8192;
while ($bytesLeft > 0 && !feof($fh)) {
    $read   = ($bytesLeft > $chunkSize) ? $chunkSize : $bytesLeft;
    $buffer = fread($fh, (int)$read);
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
    $bytesLeft -= strlen($buffer);
}
fclose($fh);
exit;