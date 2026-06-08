<?php
/**
 * ==========================================================================
 * CATALOGHI.PHP — Gestione cataloghi  [CON MODIFICA]
 * ==========================================================================
 *
 * Oltre a caricare/attivare/disattivare/eliminare, ora permette di MODIFICARE
 * un catalogo esistente: titolo, genere, data di scadenza e — opzionale —
 * sostituzione del PDF.
 *
 * SCELTA CHIAVE: in modifica NON si rigenerano slug e QR. Il QR codifica l'URL
 * (slug azienda + slug catalogo), che resta invariato: cosi' un QR gia' stampato
 * continua a funzionare anche se cambi il titolo o sostituisci il file PDF.
 *
 * Il form e' unico per "carica" (crea) e "modifica": un campo nascosto 'action'
 * e la variabile $modifica decidono comportamento e precompilazione. In modifica
 * il PDF e' facoltativo (vuoto = mantieni quello attuale).
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role( 'admin' , 'user');
require_password_changed();

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

$user = current_user();

// PATTERN: da quale azienda operiamo (superadmin sceglie, gli altri sono fissi).
if ($user['role'] === 'superadmin') {
    $azienda_id = (int)($_GET['az'] ?? $_POST['az'] ?? 0);
    $aziende_list = $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll();
} else {
    $azienda_id = (int)$user['azienda_id'];
    $aziende_list = [];
}

$error   = '';
$success = '';

function make_slug_cat(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}
/**
 * Valida un upload PDF guardando il CONTENUTO, non il MIME dichiarato dal
 * browser ($_FILES[...]['type'] è fornito dal client ed è falsificabile).
 *
 * Controlli:
 *   - esito di upload OK (UPLOAD_ERR_OK)
 *   - dimensione entro il limite
 *   - tipo MIME REALE via finfo (magic bytes) = application/pdf
 *   - firma "%PDF-" nei primi byte (doppia conferma)
 *
 * @return string '' se valido, altrimenti il messaggio d'errore.
 */
function validate_pdf_upload(array $file, int $maxBytes = 20 * 1024 * 1024): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return "Errore nel caricamento del file.";
    }
    if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
        return "Il PDF non può superare 20MB.";
    }
    // is_uploaded_file: il path deve provenire davvero da un upload HTTP,
    // non da un percorso arbitrario iniettato.
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
            $base_slug = make_slug_cat($titolo);
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
// AZIONE: MODIFICA (aggiorna un catalogo esistente)  [NUOVA]
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
            // stato caricato uno nuovo. NB: slug e QR restano invariati.
            $pdf_path = $cat['pdf_path'];

            if (!empty($_FILES['pdf']['name'])) {
                // È stato caricato un nuovo PDF: validiamolo (sul contenuto) e sostituiamolo.
                if (($pdf_err = validate_pdf_upload($_FILES['pdf'])) !== '') {
                    $error = $pdf_err;
                } else {
                    $pdf_dir  = __DIR__ . '/../uploads/pdf/';
                    // Nuovo nome con timestamp (lo slug resta quello del catalogo).
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
// Recupera il catalogo da precompilare nel form. Scoped all'azienda corrente.
$modifica = null;
if (isset($_GET['modifica']) && $azienda_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cataloghi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([(int)$_GET['modifica'], $azienda_id]);
    $modifica = $stmt->fetch();
}

// --------------------------------------------------------------------------
// DATI PER LA VISUALIZZAZIONE
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

// Valore della data scadenza da mostrare nel form (formato YYYY-MM-DD per <input type=date>).
$scadenza_value = '';
if ($modifica && !empty($modifica['data_scadenza'])) {
    $scadenza_value = date('Y-m-d', strtotime($modifica['data_scadenza']));
} elseif (!empty($_POST['data_scadenza'])) {
    $scadenza_value = $_POST['data_scadenza'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Cataloghi — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Cataloghi</h2>
            <a href="<?= BASE_URL ?>dashboard/generi.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                Gestione Generi
            </a>
        </div>

        <?php if ($user['role'] === 'superadmin'): ?>
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" action="" class="flex items-center gap-3">
        <label class="text-sm font-semibold text-gray-700">Azienda:</label>
        <select name="az" data-autosubmit
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <option value="">— Seleziona azienda —</option>
            <?php foreach ($aziende_list as $az): ?>
                <option value="<?= $az['id'] ?>" <?= $azienda_id === $az['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($az['nome_azienda']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php endif; ?>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $error ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $success ?></p>
        <?php endif; ?>

        <?php if (empty($generi)): ?>
            <div class="bg-yellow-50 text-yellow-800 px-4 py-3 rounded-lg mb-6 text-sm">
                Devi prima creare almeno un <a href="<?= BASE_URL ?>dashboard/generi.php" class="font-semibold underline">genere</a> prima di caricare cataloghi.
            </div>
        <?php else: ?>
        <!-- FORM unico: crea ("carica") oppure modifica, a seconda di $modifica. -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">
                <?= $modifica ? 'Modifica catalogo' : 'Carica nuovo catalogo' ?>
            </h3>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $modifica ? 'modifica' : 'carica' ?>">
                <?php if ($modifica): ?>
                    <input type="hidden" name="id" value="<?= $modifica['id'] ?>">
                <?php endif; ?>
                <?php if ($user['role'] === 'superadmin'): ?>
<input type="hidden" name="az" value="<?= $azienda_id ?>">
<?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Titolo</label>
                        <input type="text" name="titolo" required
                               value="<?= htmlspecialchars($modifica['titolo'] ?? $_POST['titolo'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Genere</label>
                        <select name="genere_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <option value="">— Seleziona —</option>
                            <?php foreach ($generi as $g): ?>
                                <!-- In modifica preselezioniamo il genere corrente del catalogo. -->
                                <option value="<?= $g['id'] ?>"
                                    <?= (($modifica['genere_id'] ?? $_POST['genere_id'] ?? 0) == $g['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['nome_genere']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            File PDF
                            <?php if ($modifica): ?>
                                <span class="text-gray-400 font-normal">(lascia vuoto per mantenere quello attuale)</span>
                            <?php else: ?>
                                <span class="text-gray-400 font-normal">(max 20MB)</span>
                            <?php endif; ?>
                        </label>
                        <!-- required solo in creazione; in modifica il PDF è facoltativo. -->
                        <input type="file" name="pdf" accept="application/pdf" <?= $modifica ? '' : 'required' ?>
                               class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            Data scadenza <span class="text-gray-400 font-normal">(opzionale)</span>
                        </label>
                        <input type="date" name="data_scadenza"
                               value="<?= htmlspecialchars($scadenza_value) ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                </div>
                <div class="mt-4 flex gap-3">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        <?= $modifica ? 'Salva modifiche' : 'Carica e genera QR' ?>
                    </button>
                    <?php if ($modifica): ?>
                        <!-- Annulla: torna alla pagina cataloghi (mantenendo l'azienda per il superadmin). -->
                        <a href="<?= BASE_URL ?>dashboard/cataloghi.php<?= $user['role'] === 'superadmin' ? '?az=' . $azienda_id : '' ?>"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium transition">
                            Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-500 uppercase">Cataloghi pubblicati</h3>
            </div>
            <?php if (empty($cataloghi)): ?>
                <p class="text-gray-400 text-sm text-center py-8">Nessun catalogo ancora caricato.</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Titolo</th>
                        <th class="px-4 py-3 text-left">Genere</th>
                        <th class="px-4 py-3 text-left">Scadenza</th>
                        <th class="px-4 py-3 text-left">Stato</th>
                        <th class="px-4 py-3 text-left">URL / QR</th>
                        <th class="px-4 py-3 text-left">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($cataloghi as $c): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($c['titolo']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($c['nome_genere']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= $c['data_scadenza'] ? date('d/m/Y', strtotime($c['data_scadenza'])) : '—' ?></td>
                        <td class="px-4 py-3">
                            <?php if ($c['is_active']): ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Attivo</span>
                            <?php else: ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">Inattivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="<?= BASE_URL ?>public/catalogo.php?a=<?= htmlspecialchars($c['azienda_slug'] ?? '') ?>&c=<?= htmlspecialchars($c['slug']) ?>" target="_blank"
                            class="text-indigo-600 hover:underline font-mono text-xs">
                                /<?= htmlspecialchars($c['slug']) ?>
                                </a><br>
                            <a href="<?= BASE_URL . htmlspecialchars($c['qr_code_path']) ?>"
                               download="qr-<?= htmlspecialchars($c['slug']) ?>.png"
                               class="text-xs text-gray-500 hover:text-gray-700 underline">
                                ↓ QR
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <!-- MODIFICA: ricarica la pagina in modalità modifica (con az per il superadmin). -->
                                <a href="?modifica=<?= $c['id'] ?><?= $user['role'] === 'superadmin' ? '&az=' . $azienda_id : '' ?>"
                                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Modifica
                                </a>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="<?= $c['is_active'] ? 'disattiva' : 'attiva' ?>">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <?php if ($user['role'] === 'superadmin'): ?>
<input type="hidden" name="az" value="<?= $azienda_id ?>">
<?php endif; ?>
                                    <button type="submit"
                                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                        <?= $c['is_active'] ? 'Disattiva' : 'Attiva' ?>
                                    </button>
                                </form>
                                <form method="POST" data-confirm="Eliminare questo catalogo?">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="elimina">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <?php if ($user['role'] === 'superadmin'): ?>
<input type="hidden" name="az" value="<?= $azienda_id ?>">
<?php endif; ?>
                                    <button type="submit"
                                            class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded text-xs font-medium transition">
                                        Elimina
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>