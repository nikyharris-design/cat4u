<?php
require_once __DIR__ . '/../config/bootstrap.php';

$azienda_slug  = trim($_GET['a'] ?? '');
$catalogo_slug = trim($_GET['c'] ?? '');

if (empty($azienda_slug) || empty($catalogo_slug)) {
    http_response_code(404);
    die("Pagina non trovata.");
}

// Recupera azienda
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$azienda_slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    http_response_code(404);
    die("Azienda non trovata.");
}

// Recupera catalogo
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
    http_response_code(404);
    die("Catalogo non trovato o scaduto.");
}

$pdf_url = BASE_URL . ltrim($catalogo['pdf_path'], '/');

if (!isUrlSafe($pdf_url)) {
    http_response_code(403);
    die("Contenuto non disponibile.");
}

// Analytics
$device_type = 'desktop';
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
    $device_type = 'mobile';
} elseif (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
    $device_type = 'tablet';
}

$stmt = $pdo->prepare("INSERT INTO catalogo_analytics (catalogo_id, device_type) VALUES (?, ?)");
$stmt->execute([$catalogo['id'], $device_type]);
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

    <header class="bg-indigo-600 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
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
        <iframe
            src="<?= htmlspecialchars($pdf_url) ?>"
            width="100%"
            height="800px"
            class="rounded-xl shadow border-0"
            title="<?= htmlspecialchars($catalogo['titolo']) ?>">
        </iframe>
    </main>
</body>
</html>