<?php
/**
 * ==========================================================================
 * CATALOGHI.PHP — Gestione cataloghi (upload PDF + generazione QR)
 * ==========================================================================
 *
 * Pagina più complessa dell'area gestione. Permette di:
 *   - caricare un catalogo (PDF) associandolo a un genere
 *   - generare automaticamente un QR code che punta alla pagina pubblica
 *   - attivare / disattivare un catalogo
 *   - eliminarlo (cancellando anche i file PDF e QR dal disco)
 *
 * Riutilizza due pattern già documentati in generi.php:
 *   - selezione azienda in base al ruolo (superadmin sceglie, altri sono fissi)
 *   - generazione di slug univoci
 * Qui i commenti si concentrano sulle parti NUOVE: upload file e QR.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin' , 'user');
require_password_changed();

// Importiamo le classi della libreria Endroid per generare il QR code.
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

$user = current_user();

// PATTERN: da quale azienda operiamo (vedi generi.php per il dettaglio).
if ($user['role'] === 'superadmin') {
    $azienda_id = (int)($_GET['az'] ?? $_POST['az'] ?? 0);
    $aziende_list = $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll();
} else {
    $azienda_id = (int)$user['azienda_id'];
    $aziende_list = [];
}

$error   = '';
$success = '';

// PATTERN: slug da stringa (vedi generi.php per la spiegazione riga per riga).
function make_slug_cat(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

// --------------------------------------------------------------------------
// AZIONE: ATTIVA / DISATTIVA un catalogo
// --------------------------------------------------------------------------
// Le due azioni condividono la stessa logica, cambia solo il valore di is_active.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['attiva', 'disattiva'])) {
    csrf_verify();
    $id        = (int)($_POST['id'] ?? 0);
    $is_active = ($_POST['action'] === 'attiva') ? 1 : 0;
    // Il "AND azienda_id = ?" è il controllo di proprietà: non si tocca un
    // catalogo che non sia della propria azienda.
    $stmt = $pdo->prepare("UPDATE cataloghi SET is_active = ? WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$is_active, $id, $azienda_id]);
    $success = $is_active ? "Catalogo attivato." : "Catalogo disattivato.";
}

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un catalogo (DB + file su disco)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    // Recuperiamo i percorsi dei file PRIMA di cancellare la riga: dopo il
    // DELETE non sapremmo più dove si trovano PDF e QR da rimuovere.
    $stmt = $pdo->prepare("SELECT pdf_path, qr_code_path FROM cataloghi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    $cat = $stmt->fetch();
    if ($cat) {
        // @unlink elimina i file. La @ silenzia eventuali warning (es. file già
        // assente): non vogliamo che un file mancante blocchi l'eliminazione.
        @unlink(__DIR__ . '/../' . $cat['pdf_path']);
        @unlink(__DIR__ . '/../' . $cat['qr_code_path']);
        $pdo->prepare("DELETE FROM cataloghi WHERE id = ?")->execute([$id]);
        $success = "Catalogo eliminato.";
    }
}

// --------------------------------------------------------------------------
// AZIONE: CARICA un nuovo catalogo
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'carica') {
    csrf_verify();

    $titolo        = trim($_POST['titolo'] ?? '');
    $genere_id     = (int)($_POST['genere_id'] ?? 0);
    // L'operatore ?: trasforma una stringa vuota in null (data scadenza opzionale).
    $data_scadenza = trim($_POST['data_scadenza'] ?? '') ?: null;

    // --- VALIDAZIONE DEL FILE CARICATO (a cascata) ---
    // I controlli sono in sequenza: al primo che fallisce si imposta $error e
    // si saltano gli altri grazie agli elseif.
    if (empty($titolo) || $genere_id === 0) {
        $error = "Titolo e genere sono obbligatori.";
    } elseif (empty($_FILES['pdf']['name'])) {
        // $_FILES è la superglobale che PHP popola con i file inviati via form.
        $error = "Seleziona un file PDF.";
    } elseif ($_FILES['pdf']['type'] !== 'application/pdf') {
        // NB: 'type' è dichiarato dal browser e quindi falsificabile. È un primo
        // filtro, non una garanzia assoluta sul contenuto del file.
        $error = "Il file deve essere un PDF.";
    } elseif ($_FILES['pdf']['size'] > 20 * 1024 * 1024) {
        // Limite 20MB (20 * 1024 * 1024 byte).
        $error = "Il PDF non può superare 20MB.";
    } else {
        // Verifichiamo che il genere scelto appartenga a questa azienda:
        // impedisce di agganciare il catalogo a un genere altrui.
        $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
        $stmt->execute([$genere_id, $azienda_id]);
        if (!$stmt->fetch()) {
            $error = "Genere non valido.";
        } else {
            // PATTERN: slug univoco per il catalogo.
            $base_slug = make_slug_cat($titolo);
            $slug = $base_slug;
            $i = 1;
            while (true) {
                $stmt = $pdo->prepare("SELECT id FROM cataloghi WHERE slug = ?");
                $stmt->execute([$slug]);
                if (!$stmt->fetch()) break;
                $slug = $base_slug . '-' . $i++;
            }

            // --- SALVATAGGIO DEL PDF SU DISCO ---
            $pdf_dir  = __DIR__ . '/../uploads/pdf/';
            // Nome file = slug + timestamp: il time() rende il nome unico ed
            // evita collisioni o sovrascritture accidentali.
            $pdf_name = $slug . '_' . time() . '.pdf';
            $pdf_path = 'uploads/pdf/' . $pdf_name; // percorso "relativo" salvato nel DB

            // move_uploaded_file() sposta il file dalla cartella temporanea di PHP
            // a destinazione. È anche un controllo di sicurezza: funziona solo su
            // file effettivamente caricati via HTTP POST.
            if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdf_dir . $pdf_name)) {
                $error = "Errore durante il salvataggio del PDF.";
            } else {
                // --- GENERAZIONE DEL QR CODE ---
                // Il QR deve puntare alla pagina pubblica del catalogo, che si
                // costruisce con lo slug dell'AZIENDA + lo slug del catalogo.
                // Recuperiamo quindi lo slug dell'azienda.
                $stmt_az = $pdo->prepare("SELECT slug FROM aziende WHERE id = ?");
                $stmt_az->execute([$azienda_id]);
                $azienda_slug_qr = $stmt_az->fetchColumn();

                // URL che verrà codificato nel QR.
                $qr_url  = BASE_URL . 'public/catalogo.php?a=' . $azienda_slug_qr . '&c=' . $slug;

                $qr_dir  = __DIR__ . '/../uploads/qr/';
                $qr_name = $slug . '_' . time() . '.png';
                $qr_path = 'uploads/qr/' . $qr_name;

                try {
                    // Configurazione del QR:
                    //  - errorCorrectionLevel High = più ridondanza, resta
                    //    leggibile anche se in parte rovinato/coperto.
                    //  - size/margin in pixel; colori bianco/nero per la stampa.
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
                    $result->saveToFile($qr_dir . $qr_name); // scrive il PNG su disco
                } catch (Exception $e) {
                    // Se il QR fallisce, facciamo "rollback" del PDF già salvato:
                    // non vogliamo lasciare un file orfano senza riga nel DB.
                    @unlink($pdf_dir . $pdf_name);
                    $log->error('Errore generazione QR', ['error' => $e->getMessage()]);
                    $error = "Errore durante la generazione del QR code.";
                }

                // Inseriamo la riga solo se PDF e QR sono andati a buon fine.
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
// DATI PER LA VISUALIZZAZIONE
// --------------------------------------------------------------------------
// Carichiamo generi e cataloghi solo se è stata selezionata un'azienda
// (per il superadmin senza azienda scelta, $azienda_id = 0 → liste vuote).
if ($azienda_id > 0) {
    $generi = $pdo->prepare("SELECT * FROM generi WHERE azienda_id = ? ORDER BY nome_genere");
    $generi->execute([$azienda_id]);
    $generi = $generi->fetchAll();

    // JOIN per portarsi dietro il nome del genere e lo slug azienda, utili in tabella.
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
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Cataloghi — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
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

        <!-- Selettore azienda: solo superadmin. -->
        <?php if ($user['role'] === 'superadmin'): ?>
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" action="" class="flex items-center gap-3">
        <label class="text-sm font-semibold text-gray-700">Azienda:</label>
        <select name="az" onchange="this.form.submit()"
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

        <!-- Esiti azione. ATTENZIONE: $success/$error qui NON passano da
             htmlspecialchars perché contengono HTML voluto (es. <strong>).
             Va bene perché il contenuto è costruito da noi, non da input grezzo. -->
        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $error ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $success ?></p>
        <?php endif; ?>

        <!-- Senza generi non si può caricare un catalogo: invitiamo a crearne uno. -->
        <?php if (empty($generi)): ?>
            <div class="bg-yellow-50 text-yellow-800 px-4 py-3 rounded-lg mb-6 text-sm">
                Devi prima creare almeno un <a href="<?= BASE_URL ?>dashboard/generi.php" class="font-semibold underline">genere</a> prima di caricare cataloghi.
            </div>
        <?php else: ?>
        <!-- FORM DI UPLOAD. enctype="multipart/form-data" è OBBLIGATORIO per
             inviare file: senza, $_FILES arriverebbe vuoto. -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Carica nuovo catalogo</h3>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="carica">
                <!-- Il superadmin porta con sé l'azienda selezionata nel POST. -->
                <?php if ($user['role'] === 'superadmin'): ?>
<input type="hidden" name="az" value="<?= $azienda_id ?>">
<?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Titolo</label>
                        <input type="text" name="titolo" required
                               value="<?= htmlspecialchars($_POST['titolo'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Genere</label>
                        <select name="genere_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <option value="">— Seleziona —</option>
                            <?php foreach ($generi as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nome_genere']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            File PDF <span class="text-gray-400 font-normal">(max 20MB)</span>
                        </label>
                        <!-- accept limita la scelta a PDF nella finestra di dialogo,
                             ma è solo un suggerimento: la validazione vera è server-side. -->
                        <input type="file" name="pdf" accept="application/pdf" required
                               class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            Data scadenza <span class="text-gray-400 font-normal">(opzionale)</span>
                        </label>
                        <input type="date" name="data_scadenza"
                               value="<?= htmlspecialchars($_POST['data_scadenza'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        Carica e genera QR
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- TABELLA: cataloghi pubblicati. -->
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
                        <!-- Scadenza formattata, oppure "—" se non impostata. -->
                        <td class="px-4 py-3 text-gray-600"><?= $c['data_scadenza'] ? date('d/m/Y', strtotime($c['data_scadenza'])) : '—' ?></td>
                        <td class="px-4 py-3">
                            <?php if ($c['is_active']): ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Attivo</span>
                            <?php else: ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">Inattivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <!-- Link alla pagina pubblica + download del QR generato. -->
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
                                <!-- Toggle attiva/disattiva: l'action dipende dallo stato attuale. -->
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
                                <!-- Elimina con conferma JS. -->
                                <form method="POST" onsubmit="return confirm('Eliminare questo catalogo?')">
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