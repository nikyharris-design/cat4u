<?php
require_once __DIR__ . '/../config/bootstrap.php';

$slug = trim($_GET['s'] ?? '');

if (empty($slug)) {
    http_response_code(404);
    die("Catalogo non trovato.");
}

$stmt = $pdo->prepare("
    SELECT c.*, a.nome_azienda
    FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE c.slug = ?
      AND c.is_active = 1
      AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
    LIMIT 1
");
$stmt->execute([$slug]);
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
<body>
    <main class="catalogo-viewer">
        <h1><?= htmlspecialchars($catalogo['titolo']) ?></h1>
        <p><?= htmlspecialchars($catalogo['nome_azienda']) ?></p>
        <iframe
            src="<?= htmlspecialchars($pdf_url) ?>"
            width="100%"
            height="800px"
            title="<?= htmlspecialchars($catalogo['titolo']) ?>">
        </iframe>
    </main>
</body>
</html>