<?php
/**
 * ==========================================================================
 * AZIENDE.PHP — Gestione delle aziende (solo superadmin)
 * ==========================================================================
 *
 * Pannello del superadmin per amministrare le aziende clienti. Permette di:
 *   - creare un'azienda (generando slug + QR della sua libreria pubblica)
 *   - modificarne i dati
 *   - eliminarla (rimuovendo anche il file QR)
 *
 * Riusa pattern già documentati altrove:
 *   - slug univoco (vedi generi.php)
 *   - generazione QR con Endroid (vedi cataloghi.php)
 *
 * Particolarità di questo file:
 *   - UN SOLO form serve sia a "crea" sia a "modifica": un campo nascosto
 *     'action' distingue i due casi, e $modifica decide cosa precompilare.
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

// PATTERN: slug da stringa (spiegazione dettagliata in generi.php).
function make_slug_azienda(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un'azienda
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    // Prima recuperiamo il path del QR per poterlo cancellare dal disco…
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
// in_array(...) intercetta sia 'crea' sia 'modifica': la validazione dei campi
// è comune, cambia solo la query finale (INSERT vs UPDATE).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['crea', 'modifica'])) {
    csrf_verify();

    $id             = (int)($_POST['id'] ?? 0);
    $nome_azienda   = trim($_POST['nome_azienda'] ?? '');
    $tipo_azienda   = trim($_POST['tipo_azienda'] ?? '');
    $partita_iva    = trim($_POST['partita_iva'] ?? '');
    $email_contatto = trim($_POST['email_contatto'] ?? '');

    // Verifica nome duplicato.
    // In MODIFICA escludiamo l'azienda stessa (id <> ?), altrimenti salvare
    // senza cambiare il nome darebbe un falso "duplicato".
    // Il confronto è già case-insensitive con la collation di default (utf8mb4_..._ci),
    // quindi "Acme" e "acme" vengono trattati come lo stesso nome.
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
        // filter_var con FILTER_VALIDATE_EMAIL controlla che l'email sia ben formata.
        $error = "Email non valida.";
    } elseif ($nome_duplicato) {
        // Esiste già un'azienda con lo stesso nome: blocchiamo il salvataggio.
        $error = "Esiste già un'azienda registrata con questo nome. Scegline un altro.";
    } else {
        try {
            if ($_POST['action'] === 'crea') {
                // --- CREAZIONE ---
                // PATTERN slug univoco.
                $base_slug = make_slug_azienda($nome_azienda);
                $slug = $base_slug;
                $i = 1;
                while (true) {
                    $stmt = $pdo->prepare("SELECT id FROM aziende WHERE slug = ?");
                    $stmt->execute([$slug]);
                    if (!$stmt->fetch()) break;
                    $slug = $base_slug . '-' . $i++;
                }

                // Il QR dell'azienda punta alla sua LIBRERIA pubblica (l'elenco
                // di tutti i cataloghi), non a un singolo catalogo.
                $qr_url  = BASE_URL . 'public/libreria.php?a=' . $slug;
                $qr_dir  = __DIR__ . '/../uploads/qr/';
                $qr_name = 'azienda-' . $slug . '.png';
                $qr_path = 'uploads/qr/' . $qr_name;

                // Generazione del PNG (vedi cataloghi.php per i dettagli dei parametri).
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

                // Inserimento della nuova azienda.
                $stmt = $pdo->prepare("INSERT INTO aziende (nome_azienda, tipo_azienda, partita_iva, email_contatto, slug, qr_code_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto, $slug, $qr_path]);
                $success = "Azienda creata.";
            } else {
                // --- MODIFICA ---
                // Aggiorniamo solo i dati anagrafici. NB: slug e QR NON vengono
                // rigenerati, anche se cambia il nome: il QR già stampato/diffuso
                // resta valido e funzionante.
                $stmt = $pdo->prepare("UPDATE aziende SET nome_azienda=?, tipo_azienda=?, partita_iva=?, email_contatto=? WHERE id=?");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto, $id]);
                $success = "Azienda aggiornata.";
            }
        } catch (PDOException $e) {
            // La causa più probabile è il vincolo UNIQUE sulla partita IVA:
            // due aziende non possono averla uguale.
            $error = "Partita IVA già presente nel sistema.";
        }
    }
}

// Elenco aziende per la tabella.
$aziende = $pdo->query("SELECT * FROM aziende ORDER BY nome_azienda ASC")->fetchAll();

// Se l'URL contiene ?modifica=ID, carichiamo quell'azienda per precompilare il
// form. $modifica resta null in modalità "creazione".
$modifica = null;
if (isset($_GET['modifica'])) {
    $stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
    $stmt->execute([(int)$_GET['modifica']]);
    $modifica = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Aziende — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Gestione Aziende</h2>
            <a href="<?= BASE_URL ?>admin/utenti.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                Gestione Utenti →
            </a>
        </div>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <!-- FORM CREA/MODIFICA. Il titolo e l'action cambiano in base a $modifica. -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">
                <?= $modifica ? 'Modifica Azienda' : 'Nuova Azienda' ?>
            </h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <!-- Campo che dice al PHP quale ramo eseguire. -->
                <input type="hidden" name="action" value="<?= $modifica ? 'modifica' : 'crea' ?>">
                <!-- In modifica passiamo anche l'id della riga da aggiornare. -->
                <?php if ($modifica): ?>
                    <input type="hidden" name="id" value="<?= $modifica['id'] ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nome Azienda</label>
                        <!-- value: in modifica mostra il dato esistente; altrimenti
                             ripropone l'eventuale input dopo un errore di validazione. -->
                        <input type="text" name="nome_azienda" required
                               value="<?= htmlspecialchars($modifica['nome_azienda'] ?? $_POST['nome_azienda'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tipo Azienda</label>
                        <input type="text" name="tipo_azienda" placeholder="es. Retail, Farmacia…" required
                               value="<?= htmlspecialchars($modifica['tipo_azienda'] ?? $_POST['tipo_azienda'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Partita IVA</label>
                        <input type="text" name="partita_iva" maxlength="20" required
                               value="<?= htmlspecialchars($modifica['partita_iva'] ?? $_POST['partita_iva'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email Contatto</label>
                        <input type="email" name="email_contatto" required
                               value="<?= htmlspecialchars($modifica['email_contatto'] ?? $_POST['email_contatto'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                </div>
                <div class="mt-4 flex gap-3">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        <?= $modifica ? 'Salva modifiche' : 'Crea Azienda' ?>
                    </button>
                    <!-- In modifica mostriamo "Annulla" per tornare alla creazione. -->
                    <?php if ($modifica): ?>
                        <a href="<?= BASE_URL ?>admin/aziende.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium transition">
                            Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- TABELLA AZIENDE. -->
        <?php if (empty($aziende)): ?>
            <p class="text-gray-400 text-sm text-center py-8">Nessuna azienda registrata.</p>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
    <tr>
        <th class="px-4 py-3 text-left">Nome</th>
        <th class="px-4 py-3 text-left">Tipo</th>
        <th class="px-4 py-3 text-left">P.IVA</th>
        <th class="px-4 py-3 text-left">Email</th>
        <th class="px-4 py-3 text-left">Libreria</th>
        <th class="px-4 py-3 text-left">Azioni</th>
    </tr>
</thead>
                <tbody class="divide-y divide-gray-100">
    <?php foreach ($aziende as $a): ?>
    <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($a['nome_azienda']) ?></td>
        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['tipo_azienda']) ?></td>
        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['partita_iva']) ?></td>
        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['email_contatto']) ?></td>
        <td class="px-4 py-3">
            <!-- Link alla libreria pubblica + download del QR aziendale. -->
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($a['slug']) ?>" target="_blank"
               class="text-indigo-600 hover:underline font-mono text-xs">
                /<?= htmlspecialchars($a['slug']) ?>
            </a><br>
            <?php if ($a['qr_code_path']): ?>
            <a href="<?= BASE_URL . htmlspecialchars($a['qr_code_path']) ?>"
               download="qr-<?= htmlspecialchars($a['slug']) ?>.png"
               class="text-xs text-gray-500 hover:text-gray-700 underline">
                ↓ QR Libreria
            </a>
            <?php endif; ?>
        </td>
        <td class="px-4 py-3">
            <div class="flex gap-2">
                <!-- "Modifica" ricarica la pagina con ?modifica=ID → precompila il form. -->
                <a href="?modifica=<?= $a['id'] ?>"
                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                    Modifica
                </a>
                <!-- Eliminazione con conferma JS + CSRF. -->
                <form method="POST" data-confirm="Eliminare questa azienda?">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="elimina">
                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
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
        </div>
        <?php endif; ?>
    </main>
</body>
</html>