<?php
/**
 * ==========================================================================
 * ERROR.PHP — Pagina di errore generica (vista)
 * ==========================================================================
 *
 * Usata dall'error handler globale (App\ErrorHandler) per QUALSIASI stato:
 * 403, 404, 422, 500, 503… A differenza di 404.php è parametrica: riceve
 * $status e $messaggio già impostati da chi la include.
 *
 * Difensiva di proposito: se BASE_URL non fosse ancora definita (errore
 * scattato prima del bootstrap completo) usa un fallback, invece di rompersi.
 */

$base      = defined('BASE_URL') ? BASE_URL : '/';
$status    = $status    ?? 500;
$messaggio = $messaggio ?? "Si è verificato un errore.";

$titoli = [
    403 => 'Accesso negato',
    404 => 'Pagina non trovata',
    422 => 'Dati non validi',
    500 => 'Errore del server',
    503 => 'Servizio non disponibile',
];
$titolo = $titoli[$status] ?? 'Errore';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= (int)$status ?> — Cat4U</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md text-center">
        <div class="text-indigo-600 text-4xl font-bold mb-2"><?= (int)$status ?></div>
        <h1 class="text-gray-800 font-semibold text-lg mb-2"><?= htmlspecialchars($titolo) ?></h1>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($messaggio) ?></p>
        <a href="<?= htmlspecialchars($base) ?>"
           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2 rounded-lg transition">
            Torna alla home
        </a>
    </div>
</body>
</html>