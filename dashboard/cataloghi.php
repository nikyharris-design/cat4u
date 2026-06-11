<?php
/**
 * ==========================================================================
 * CATALOGHI.PHP — Controller della pagina "Cataloghi"
 * ==========================================================================
 *
 * Gestisce caricamento, modifica, attivazione/disattivazione ed eliminazione
 * dei cataloghi. Alla creazione genera slug univoco e QR code.
 *
 * In modifica NON si rigenerano slug e QR: il QR codifica l'URL (slug azienda +
 * slug catalogo), che resta invariato, così un QR già stampato continua a
 * funzionare anche cambiando titolo o sostituendo il PDF.
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/cataloghi.view.php, inclusa in fondo. make_slug è in config/helpers.php.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin', 'user');
require_password_changed();

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

$user = current_user();

// Admin e user operano sempre sulla propria azienda.
$azienda_id = (int)$user['azienda_id'];

$error   = '';
$success = '';

/**
 * Valida un upload PDF guardando il CONTENUTO, non il MIME dichiarato dal
 * browser ($_FILES[...]['type'] è fornito dal client ed è falsificabile).
 *
 * @return string '' se valido, altrimenti il messaggio d'errore.
 */
function validate_pdf_upload(array $file, int $maxBytes = 20 * 1024 * 1024): string {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        return match ($err) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => "Il PDF è troppo grande (max 20MB).",
            UPLOAD_ERR_PARTIAL   => "Il caricamento si è interrotto. Riprova.",
            UPLOAD_ERR_NO_FILE   => "Seleziona un file PDF.",
            default              => "Errore nel caricamento del file.",
        };
    }
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        return "Il PDF non può superare 20MB.";
    }
    // is_uploaded_file: il path deve provenire davvero da un upload HTTP.
    if (!is_uploaded_file($file['tmp_name'])) {
        return "File non valido.";
    }
    // MIME reale dai magic bytes del file salvato sul tmp.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'application/pdf') {
        return "Il file deve essere un PDF.";
    }
    // Conferma sulla firma iniziale (i PDF iniziano con "%PDF-").
    $fh = fopen($file['tmp_name'], 'rb');
    if ($fh === false) {
        return "Impossibile leggere il file.";
    }
    $head = fread($fh, 5);
    fclose($fh);
    if ($head !== '%PDF-') {
        return "Il file deve essere un PDF.";
    }
    return '';
}

// --------------------------------------------------------------------------
// POST TRONCATO: upload oltre post_max_size
// --------------------------------------------------------------------------
// Se la richiesta è POST ma $_POST e $_FILES sono vuoti pur essendoci un corpo
// (CONTENT_LENGTH > 0), PHP ha scartato tutto perché troppo grande.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = "File troppo grande: l'upload supera il limite del server. Il PDF non può superare 20MB.";
}

// --------------------------------------------------------------------------
// AZIONE: ATTIVA / DISATTIVA
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['attiva', 'disattiva'])) {
    csrf_verify();
    $id        = (int)($_POST['id'] ?? 0);
    $is_active = ($_POST['action'] === 'attiva') ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE cataloghi SET is_active = ? WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$is_active, $id, $azienda_id]);
    $success = $is_active ? "Catalogo attivato." : "Catalogo disattivato.";
}

// --------------------------------------------------------------------------
// AZIONE: ELIMINA (DB + file su disco)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT pdf_path, qr_code_path FROM cataloghi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    $cat = $stmt->fetch();
    if ($cat) {
        @unlink(__DIR__ . '/../' . $cat['pdf_path']);
        @unlink(__DIR__ . '/../' . $cat['qr_code_path']);
        $pdo->prepare("DELETE FROM cataloghi WHERE id = ?")->execute([$id]);
        $success = "Catalogo eliminato.";
    }
}

// --------------------------------------------------------------------------
// AZIONE: CARICA (crea nuovo catalogo)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'carica') {
    csrf_verify();

    $titolo        = trim($_POST['titolo'] ?? '');
    $genere_id     = (int)($_POST['genere_id'] ?? 0);
    $data_scadenza = trim($_POST['data_scadenza'] ?? '') ?: null;

    if (empty($titolo) || $genere_id === 0) {
        $error = "Titolo e genere sono obbligatori.";
    } elseif (empty($_FILES['pdf']['name'])) {
        $error = "Seleziona un file PDF.";
    } elseif (($pdf_err = validate_pdf_upload($_FILES['pdf'])) !== '') {
        $error = $pdf_err;
    } else {
        $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
        $stmt->execute([$genere_id, $azienda_id]);
        if (!$stmt->fetch()) {
            $error = "Genere non valido.";
        } else {
            $base_slug = make_slug($titolo);
            $slug = $base_slug;
            $i = 1;
            while (true) {
                $stmt = $pdo->prepare("SELECT id FROM cataloghi WHERE slug = ?");
                $stmt->execute([$slug]);
                if (!$stmt->fetch()) break;
                $slug = $base_slug . '-' . $i++;
            }

            $pdf_dir  = __DIR__ . '/../uploads/pdf/';
            $pdf_name = $slug . '_' . time() . '.pdf';
            $pdf_path = 'uploads/pdf/' . $pdf_name;

            if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdf_dir . $pdf_name)) {
                $error = "Errore durante il salvataggio del PDF.";
            } else {
                $stmt_az = $pdo->prepare("SELECT slug FROM aziende WHERE id = ?");
                $stmt_az->execute([$azienda_id]);
                $azienda_slug_qr = $stmt_az->fetchColumn();

                $qr_url  = BASE_URL . 'public/catalogo.php?a=' . $azienda_slug_qr . '&c=' . $slug;
                $qr_dir  = __DIR__ . '/../uploads/qr/';
                $qr_name = $slug . '_' . time() . '.png';
                $qr_path = 'uploads/qr/' . $qr_name;

                try {
                    $qrCode = new QrCode(
                        data: $qr_url,
                        encoding: new Encoding('UTF-8'),
                        errorCorrectionLevel: ErrorCorrectionLevel::High,
                        size: 400,
                        margin: 20,
                        foregroundColor: new Color(0, 0, 0),
                        backgroundColor: new Color(255, 255, 255)
                    );
                    $writer = new PngWriter();
                    $result = $writer->write($qrCode);
                    $result->saveToFile($qr_dir . $qr_name);
                } catch (Exception $e) {
                    @unlink($pdf_dir . $pdf_name);
                    $log->error('Errore generazione QR', ['error' => $e->getMessage()]);
                    $error = "Errore durante la generazione del QR code.";
                }

                if (empty($error)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO cataloghi (azienda_id, genere_id, titolo, pdf_path, slug, qr_code_path, data_scadenza)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$azienda_id, $genere_id, $titolo, $pdf_path, $slug, $qr_path, $data_scadenza]);
                    $success = "Catalogo pubblicato. URL: <strong>" . BASE_URL . "public/catalogo.php?a=" . htmlspecialchars($azienda_slug_qr) . "&c=" . htmlspecialchars($slug) . "</strong>";
                }
            }
        }
    }
}

// --------------------------------------------------------------------------
// AZIONE: MODIFICA (aggiorna un catalogo esistente)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifica') {
    csrf_verify();

    $id            = (int)($_POST['id'] ?? 0);
    $titolo        = trim($_POST['titolo'] ?? '');
    $genere_id     = (int)($_POST['genere_id'] ?? 0);
    $data_scadenza = trim($_POST['data_scadenza'] ?? '') ?: null;

    // Controllo di proprietà: il catalogo deve appartenere all'azienda corrente.
    $stmt = $pdo->prepare("SELECT * FROM cataloghi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    $cat = $stmt->fetch();

    if (!$cat) {
        $error = "Catalogo non trovato.";
    } elseif (empty($titolo) || $genere_id === 0) {
        $error = "Titolo e genere sono obbligatori.";
    } else {
        // Il genere scelto deve essere dell'azienda corrente.
        $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
        $stmt->execute([$genere_id, $azienda_id]);
        if (!$stmt->fetch()) {
            $error = "Genere non valido.";
        } else {
            // Di default manteniamo il PDF attuale; lo cambiamo solo se ne è
            // stato caricato uno nuovo. Slug e QR restano invariati.
            $pdf_path = $cat['pdf_path'];

            if (!empty($_FILES['pdf']['name'])) {
                if (($pdf_err = validate_pdf_upload($_FILES['pdf'])) !== '') {
                    $error = $pdf_err;
                } else {
                    $pdf_dir  = __DIR__ . '/../uploads/pdf/';
                    $pdf_name = $cat['slug'] . '_' . time() . '.pdf';
                    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdf_dir . $pdf_name)) {
                        $error = "Errore durante il salvataggio del PDF.";
                    } else {
                        // Cancelliamo il vecchio PDF e puntiamo al nuovo.
                        @unlink(__DIR__ . '/../' . $cat['pdf_path']);
                        $pdf_path = 'uploads/pdf/' . $pdf_name;
                    }
                }
            }

            if (empty($error)) {
                $stmt = $pdo->prepare("
                    UPDATE cataloghi
                    SET titolo = ?, genere_id = ?, data_scadenza = ?, pdf_path = ?
                    WHERE id = ? AND azienda_id = ?
                ");
                $stmt->execute([$titolo, $genere_id, $data_scadenza, $pdf_path, $id, $azienda_id]);
                $success = "Catalogo aggiornato.";
            }
        }
    }
}

// --------------------------------------------------------------------------
// CARICAMENTO IN MODALITÀ MODIFICA (se ?modifica=ID)
// --------------------------------------------------------------------------
$modifica = null;
if (isset($_GET['modifica']) && $azienda_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cataloghi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([(int)$_GET['modifica'], $azienda_id]);
    $modifica = $stmt->fetch();
}

// --------------------------------------------------------------------------
// DATI PER LA VISTA
// --------------------------------------------------------------------------
if ($azienda_id > 0) {
    $generi = $pdo->prepare("SELECT * FROM generi WHERE azienda_id = ? ORDER BY nome_genere");
    $generi->execute([$azienda_id]);
    $generi = $generi->fetchAll();

    $cataloghi = $pdo->prepare("
        SELECT c.*, g.nome_genere, a.slug AS azienda_slug
        FROM cataloghi c
        JOIN generi g ON g.id = c.genere_id
        JOIN aziende a ON a.id = c.azienda_id
        WHERE c.azienda_id = ?
        ORDER BY c.created_at DESC
    ");
    $cataloghi->execute([$azienda_id]);
    $cataloghi = $cataloghi->fetchAll();
} else {
    $generi    = [];
    $cataloghi = [];
}

// Valore della data scadenza per il form (formato YYYY-MM-DD per <input type=date>).
$scadenza_value = '';
if ($modifica && !empty($modifica['data_scadenza'])) {
    $scadenza_value = date('Y-m-d', strtotime($modifica['data_scadenza']));
} elseif (!empty($_POST['data_scadenza'])) {
    $scadenza_value = $_POST['data_scadenza'];
}

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/cataloghi.view.php';