<?php
/**
 * ==========================================================================
 * LIBRERIA.PHP — Vetrina pubblica dei cataloghi di un'azienda
 * ==========================================================================
 *
 * Pagina PUBBLICA: nessun login richiesto. È ciò che vede un cliente finale
 * dopo aver scansionato il QR aziendale. Mostra l'elenco dei cataloghi attivi
 * dell'azienda, con la possibilità di filtrarli per genere.
 *
 * Si raggiunge in due modi (entrambi finiscono qui):
 *   - URL pulito:  /nome-azienda            (gestito da index.php)
 *   - URL diretto: public/libreria.php?a=nome-azienda&g=nome-genere
 *
 * Parametri:
 *   a = slug azienda (obbligatorio)
 *   g = slug genere  (opzionale: se presente, filtra i cataloghi)
 *
 * Nota sicurezza: pur essendo pubblica, include comunque bootstrap.php (quindi
 * sessione, fingerprint, ecc.) e usa solo prepared statement.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Slug azienda dalla query string. Senza, non sappiamo cosa mostrare → 404.
$slug = trim($_GET['a'] ?? '');

if (empty($slug)) {
    http_response_code(404);
    die("Pagina non trovata.");
}

// Recuperiamo l'azienda dallo slug. Se non esiste, 404.
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    http_response_code(404);
    die("Azienda non trovata.");
}

// --- FILTRO GENERE (opzionale) ---
$genere_slug   = trim($_GET['g'] ?? '');
$genere_attivo = null; // resta null se non si filtra per genere

if ($genere_slug) {
    // Cerchiamo il genere PER QUESTA azienda (slug + azienda_id): impedisce di
    // filtrare con un genere di un'altra azienda.
    $stmt = $pdo->prepare("SELECT * FROM generi WHERE slug = ? AND azienda_id = ? LIMIT 1");
    $stmt->execute([$genere_slug, $azienda['id']]);
    $genere_attivo = $stmt->fetch();
}

// --- GENERI DA MOSTRARE COME "TAB" DI FILTRO ---
// Mostriamo solo i generi che hanno almeno un catalogo pubblicabile (attivo e
// non scaduto). La subquery EXISTS verifica "esiste almeno una riga che…?":
// è efficiente perché si ferma al primo risultato utile.
$generi = $pdo->prepare("
    SELECT g.* FROM generi g
    WHERE g.azienda_id = ?
    AND EXISTS (
        SELECT 1 FROM cataloghi c
        WHERE c.genere_id = g.id
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
    )
    ORDER BY g.nome_genere ASC
");
$generi->execute([$azienda['id']]);
$generi = $generi->fetchAll();

// --- ELENCO CATALOGHI ---
// Le condizioni "attivo + non scaduto" sono comuni; cambia solo se filtriamo
// per genere o no. (data_scadenza IS NULL = senza scadenza → sempre valido.)
if ($genere_attivo) {
    $stmt = $pdo->prepare("
        SELECT c.*, g.nome_genere FROM cataloghi c
        JOIN generi g ON g.id = c.genere_id
        WHERE c.azienda_id = ?
        AND c.genere_id = ?
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$azienda['id'], $genere_attivo['id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, g.nome_genere FROM cataloghi c
        JOIN generi g ON g.id = c.genere_id
        WHERE c.azienda_id = ?
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$azienda['id']]);
}
$cataloghi = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($azienda['nome_azienda']) ?> — Cataloghi</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Header pubblico: niente navigazione interna, solo il nome azienda. -->
    <header class="bg-indigo-600 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center">
            <span class="font-bold text-lg"><?= htmlspecialchars($azienda['nome_azienda']) ?></span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto py-8 px-4">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Cataloghi</h1>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($azienda['nome_azienda']) ?></p>

        <!-- TAB DI FILTRO PER GENERE (solo se ci sono generi con cataloghi). -->
        <?php if (!empty($generi)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <!-- "Tutti": evidenziato quando nessun genere è attivo. -->
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>"
               class="<?= !$genere_attivo ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                Tutti
            </a>
            <?php foreach ($generi as $g): ?>
            <!-- Ogni tab è evidenziata se è il genere attualmente filtrato. -->
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>&g=<?= htmlspecialchars($g['slug']) ?>"
               class="<?= ($genere_attivo && $genere_attivo['id'] === $g['id']) ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                <?= htmlspecialchars($g['nome_genere']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- GRIGLIA DEI CATALOGHI (o messaggio se vuota). -->
        <?php if (empty($cataloghi)): ?>
            <p class="text-gray-400 text-sm text-center py-12">Nessun catalogo disponibile.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($cataloghi as $c): ?>
            <!-- Ogni card è un link alla pagina del singolo catalogo. -->
            <a href="<?= BASE_URL ?>public/catalogo.php?a=<?= htmlspecialchars($azienda['slug']) ?>&c=<?= htmlspecialchars($c['slug']) ?>"
               class="bg-white rounded-xl shadow hover:shadow-md transition p-5 flex flex-col gap-2">
                <div class="flex items-start justify-between">
                    <h2 class="font-semibold text-gray-800"><?= htmlspecialchars($c['titolo']) ?></h2>
                    <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded-full font-medium">
                        <?= htmlspecialchars($c['nome_genere']) ?>
                    </span>
                </div>
                <?php if ($c['data_scadenza']): ?>
                <p class="text-xs text-gray-400">Valido fino al <?= date('d/m/Y', strtotime($c['data_scadenza'])) ?></p>
                <?php endif; ?>
                <!-- mt-auto spinge questa riga in fondo alla card, allineando le card di altezza diversa. -->
                <p class="text-indigo-600 text-sm font-medium mt-auto">Apri catalogo →</p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>