<?php
// Decide se il secondo segmento è un catalogo o un genere
$azienda_slug = $_GET['azienda'] ?? '';
$secondo      = $_GET['catalogo'] ?? '';

// Cerca prima come catalogo
$stmt = $pdo->prepare("
    SELECT c.id FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE a.slug = ? AND c.slug = ? AND c.is_active = 1
    LIMIT 1
");
$stmt->execute([$azienda_slug, $secondo]);

if ($stmt->fetch()) {
    // È un catalogo
    require __DIR__ . '/catalogo.php';
} else {
    // Potrebbe essere un genere — mostra libreria filtrata
    require __DIR__ . '/libreria.php';
}