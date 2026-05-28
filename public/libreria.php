<?php
require_once __DIR__ . '/../config/bootstrap.php';

$slug = trim($_GET['a'] ?? '');

if (empty($slug)) {
    http_response_code(404);
    die("Pagina non trovata.");
}

$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    http_response_code(404);
    die("Azienda non trovata.");
}

$genere_slug = trim($_GET['g'] ?? '');
$genere_attivo = null;

if ($genere_slug) {
    $stmt = $pdo->prepare("SELECT * FROM generi WHERE slug = ? AND azienda_id = ? LIMIT 1");
    $stmt->execute([$genere_slug, $azienda['id']]);
    $genere_attivo = $stmt->fetch();
}

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

    <header class="bg-indigo-600 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center">
            <span class="font-bold text-lg"><?= htmlspecialchars($azienda['nome_azienda']) ?></span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto py-8 px-4">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Cataloghi</h1>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($azienda['nome_azienda']) ?></p>

        <?php if (!empty($generi)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>"
               class="<?= !$genere_attivo ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                Tutti
            </a>
            <?php foreach ($generi as $g): ?>
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>&g=<?= htmlspecialchars($g['slug']) ?>"
               class="<?= ($genere_attivo && $genere_attivo['id'] === $g['id']) ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                <?= htmlspecialchars($g['nome_genere']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($cataloghi)): ?>
            <p class="text-gray-400 text-sm text-center py-12">Nessun catalogo disponibile.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($cataloghi as $c): ?>
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
                <p class="text-indigo-600 text-sm font-medium mt-auto">Apri catalogo →</p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>