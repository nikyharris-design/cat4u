<?php
/**
 * ==========================================================================
 * AZIENDE.PHP — Controller "Gestione Aziende" (solo superadmin)
 * ==========================================================================
 *
 * Permette di creare, modificare ed eliminare le aziende clienti. Alla
 * creazione genera lo slug univoco e il QR della libreria pubblica.
 *
 * UN SOLO form serve sia a "crea" sia a "modifica": il campo nascosto 'action'
 * distingue i due casi, e $modifica decide cosa precompilare.
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/aziende.view.php, inclusa in fondo. make_slug è in config/helpers.php.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Guardia stretta: SOLO il superadmin. Gli admin di azienda non entrano qui.
require_role('superadmin');
require_password_changed();

// Classi per generare il QR code della libreria aziendale.
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

$error   = '';
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un'azienda
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    // Prima recuperiamo il path del QR per cancellarlo dal disco…
    $stmt = $pdo->prepare("SELECT qr_code_path FROM aziende WHERE id = ?");
    $stmt->execute([$id]);
    $az = $stmt->fetch();
    if ($az && $az['qr_code_path']) {
        @unlink(__DIR__ . '/../' . $az['qr_code_path']);
    }
    // …poi eliminiamo la riga.
    $pdo->prepare("DELETE FROM aziende WHERE id = ?")->execute([$id]);
    $success = "Azienda eliminata.";
}

// --------------------------------------------------------------------------
// AZIONE: CREA o MODIFICA (stesso blocco per entrambe)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['crea', 'modifica'])) {
    csrf_verify();

    $id             = (int)($_POST['id'] ?? 0);
    $nome_azienda   = trim($_POST['nome_azienda'] ?? '');
    $tipo_azienda   = trim($_POST['tipo_azienda'] ?? '');
    $partita_iva    = trim($_POST['partita_iva'] ?? '');
    $email_contatto = trim($_POST['email_contatto'] ?? '');

    // Verifica nome duplicato. In MODIFICA escludiamo l'azienda stessa (id <> ?).
    // Il confronto è case-insensitive con la collation di default (..._ci).
    if ($_POST['action'] === 'modifica') {
        $stmtDup = $pdo->prepare("SELECT id FROM aziende WHERE nome_azienda = ? AND id <> ? LIMIT 1");
        $stmtDup->execute([$nome_azienda, $id]);
    } else {
        $stmtDup = $pdo->prepare("SELECT id FROM aziende WHERE nome_azienda = ? LIMIT 1");
        $stmtDup->execute([$nome_azienda]);
    }
    $nome_duplicato = (bool) $stmtDup->fetch();

    // Validazione comune a crea e modifica.
    if (empty($nome_azienda) || empty($tipo_azienda) || empty($partita_iva) || empty($email_contatto)) {
        $error = "Compila tutti i campi.";
    } elseif (!filter_var($email_contatto, FILTER_VALIDATE_EMAIL)) {
        $error = "Email non valida.";
    } elseif ($nome_duplicato) {
        $error = "Esiste già un'azienda registrata con questo nome. Scegline un altro.";
    } else {
        try {
            if ($_POST['action'] === 'crea') {
                // --- CREAZIONE --- slug univoco.
                $base_slug = make_slug($nome_azienda);
                $slug = $base_slug;
                $i = 1;
                while (true) {
                    $stmt = $pdo->prepare("SELECT id FROM aziende WHERE slug = ?");
                    $stmt->execute([$slug]);
                    if (!$stmt->fetch()) break;
                    $slug = $base_slug . '-' . $i++;
                }

                // Il QR dell'azienda punta alla sua LIBRERIA pubblica.
                $qr_url  = BASE_URL . 'public/libreria.php?a=' . $slug;
                $qr_dir  = __DIR__ . '/../uploads/qr/';
                $qr_name = 'azienda-' . $slug . '.png';
                $qr_path = 'uploads/qr/' . $qr_name;

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

                $stmt = $pdo->prepare("INSERT INTO aziende (nome_azienda, tipo_azienda, partita_iva, email_contatto, slug, qr_code_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto, $slug, $qr_path]);
                $success = "Azienda creata.";
            } else {
                // --- MODIFICA --- solo dati anagrafici. Slug e QR NON rigenerati,
                // così il QR già stampato resta valido.
                $stmt = $pdo->prepare("UPDATE aziende SET nome_azienda=?, tipo_azienda=?, partita_iva=?, email_contatto=? WHERE id=?");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto, $id]);
                $success = "Azienda aggiornata.";
            }
        } catch (PDOException $e) {
            // Causa più probabile: vincolo UNIQUE sulla partita IVA.
            $error = "Partita IVA già presente nel sistema.";
        }
    }
}

// Elenco aziende per la tabella.
$aziende = $pdo->query("SELECT * FROM aziende ORDER BY nome_azienda ASC")->fetchAll();

// Se l'URL contiene ?modifica=ID, carichiamo quell'azienda per precompilare
// il form. $modifica resta null in modalità "creazione".
$modifica = null;
if (isset($_GET['modifica'])) {
    $stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
    $stmt->execute([(int)$_GET['modifica']]);
    $modifica = $stmt->fetch();
}

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/aziende.view.php';