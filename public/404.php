<?php
/**
 * ==========================================================================
 * 404.PHP — Pagina "non trovato" (vista)
 * ==========================================================================
 *
 * Normalmente viene inclusa dalla funzione not_found() (in base.php), che ha
 * già impostato lo stato 404 e la variabile $messaggio_404.
 *
 * Se invece qualcuno apre questo file DIRETTAMENTE via URL, non passa da
 * bootstrap.php: in quel caso lo carichiamo qui (così BASE_URL esiste) e
 * impostiamo comunque lo stato 404.
 */
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/bootstrap.php';
    http_response_code(404);
}

// Messaggio di default se la pagina è aperta senza che not_found() l'abbia valorizzato.
$messaggio_404 = $messaggio_404 ?? "La pagina che cerchi non esiste o è stata rimossa.";
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagina non trovata — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md text-center">
        <div class="text-indigo-600 text-4xl font-bold mb-2">404</div>
        <h1 class="text-gray-800 font-semibold text-lg mb-2">Pagina non trovata</h1>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($messaggio_404) ?></p>
        <a href="<?= BASE_URL ?>"
           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2 rounded-lg transition">
            Torna alla home
        </a>
    </div>
</body>
</html>