<?php
/**
 * ==========================================================================
 * INDEX.PHP — Front Controller (router principale)   [VERSIONE CORRETTA]
 * ==========================================================================
 *
 * Tutte le richieste con URL "pulito" passano da qui (grazie all'.htaccess che
 * le riscrive in index.php?_route=...). Questo file smista verso la pagina giusta.
 *
 * CORREZIONE rispetto alla versione precedente:
 *   Prima si valorizzavano $_GET['azienda'] / ['catalogo'] / ['genere'], ma le
 *   pagine pubbliche (libreria.php, catalogo.php) leggono $_GET['a'] / ['c'] /
 *   ['g']. I nomi non combaciavano → le pagine raggiunte via URL pulito davano
 *   404. Ora usiamo direttamente a/c/g, gli STESSI nomi dei link diretti e dei
 *   QR, così entrambi i percorsi funzionano in modo identico.
 */

require_once __DIR__ . '/config/bootstrap.php';

// Percorso richiesto, passato dall'.htaccess nel parametro _route.
$route = trim($_GET['_route'] ?? '', '/');

// --------------------------------------------------------------------------
// CASO 1 — Nessuna rotta: redirect a dashboard (se loggato) o login.
// --------------------------------------------------------------------------
if ($route === '') {
    header("Location: " . BASE_URL . (!empty($_SESSION['autorizzato']) ? 'dashboard/index.php' : 'dashboard/login.php'));
    exit();
}

// --------------------------------------------------------------------------
// CASO 2 — Rotte "dirette" (whitelist): rotta pulita → file PHP reale.
// --------------------------------------------------------------------------
$rotte_dirette = [
    'dashboard/login'    => '/dashboard/login.php',
    'dashboard/logout'   => '/dashboard/logout.php',
    'dashboard/password' => '/dashboard/change-password.php',
    'dashboard'          => '/dashboard/index.php',
    'dashboard/cataloghi'=> '/dashboard/cataloghi.php',
    'dashboard/generi'   => '/dashboard/generi.php',
    'admin/aziende'      => '/admin/aziende.php',
    'admin/utenti'       => '/admin/utenti.php',
];

if (isset($rotte_dirette[$route])) {
    require __DIR__ . $rotte_dirette[$route];
    exit();
}

// --------------------------------------------------------------------------
// CASO 3 — Rotte pubbliche: i segmenti dell'URL sono slug.
// --------------------------------------------------------------------------
$parti = explode('/', $route);

if (count($parti) === 1) {
    // /nome-azienda → libreria pubblica dell'azienda.
    // Usiamo 'a', il nome che libreria.php si aspetta. Nessun 'g' → niente filtro.
    $_GET['a'] = $parti[0];
    require __DIR__ . '/public/libreria.php';

} elseif (count($parti) === 2) {
    // /nome-azienda/qualcosa → il secondo segmento può essere catalogo O genere.
    // Prepariamo entrambe le ipotesi coi nomi reali (c e g) e lasciamo decidere
    // a router.php in base al DB.
    $_GET['a'] = $parti[0]; // slug azienda
    $_GET['c'] = $parti[1]; // ipotesi: slug catalogo
    $_GET['g'] = $parti[1]; // ipotesi: slug genere
    require __DIR__ . '/public/router.php';

} else {
    // Tre o più segmenti: non previsto → pagina 404 stilizzata.
    not_found();
}