<?php
/**
 * ==========================================================================
 * INDEX.PHP — Front Controller (router principale)
 * ==========================================================================
 *
 * In un'architettura "front controller" TUTTE le richieste passano da questo
 * unico file, che poi smista verso la pagina giusta. È l'.htaccess a rendere
 * possibile tutto ciò: riscrive URL puliti come
 *     /cat4u/acme            →  index.php?_route=acme
 *     /cat4u/acme/volantino  →  index.php?_route=acme/volantino
 * passando il percorso richiesto nel parametro _route.
 *
 * Vantaggi:
 *   - URL leggibili e "belli" (niente .php o query string in vista).
 *   - Un solo punto di ingresso dove applicare logica comune.
 *
 * Schema di routing gestito qui:
 *   - rotta vuota            → redirect a dashboard o login
 *   - rotte "dirette"        → pagine interne note (dashboard, admin…)
 *   - 1 segmento  (/azienda) → libreria pubblica dell'azienda
 *   - 2 segmenti  (/az/qcosa)→ catalogo o genere (decide router.php)
 */

require_once __DIR__ . '/config/bootstrap.php';

// Leggiamo il percorso richiesto dal parametro _route (popolato dall'.htaccess).
// trim(..., '/') rimuove eventuali slash iniziali/finali per normalizzarlo.
$route = trim($_GET['_route'] ?? '', '/');

// --------------------------------------------------------------------------
// CASO 1 — Nessuna rotta (qualcuno ha aperto la radice del sito)
// --------------------------------------------------------------------------
// Mandiamo l'utente alla dashboard se è loggato, altrimenti al login.
if ($route === '') {
    header("Location: " . BASE_URL . (!empty($_SESSION['autorizzato']) ? 'dashboard/index.php' : 'dashboard/login.php'));
    exit();
}

// --------------------------------------------------------------------------
// CASO 2 — Rotte "dirette": mappa rotta-pulita → file PHP reale
// --------------------------------------------------------------------------
// Una whitelist esplicita: solo questi percorsi sono validi come pagine interne.
// È anche una misura di sicurezza: non si include un file arbitrario basandosi
// sull'input dell'utente, ma solo file presenti in questa mappa.
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

// Se la rotta richiesta è nella whitelist, includiamo il file corrispondente.
if (isset($rotte_dirette[$route])) {
    require __DIR__ . $rotte_dirette[$route];
    exit();
}

// --------------------------------------------------------------------------
// CASO 3 — Rotte pubbliche: interpretiamo i segmenti dell'URL come slug
// --------------------------------------------------------------------------
// Spezziamo la rotta sui "/" per contare e leggere i segmenti.
$parti = explode('/', $route);

if (count($parti) === 1) {
    // Un solo segmento → è lo slug di un'azienda: mostriamo la sua libreria.
    // Travasiamo lo slug in $_GET['azienda'] perché libreria.php lo legge da lì.
    $_GET['azienda'] = $parti[0];
    require __DIR__ . '/public/libreria.php';

} elseif (count($parti) === 2) {
    // Due segmenti → /azienda/qualcosa. Quel "qualcosa" può essere lo slug di
    // un catalogo OPPURE di un genere: non lo sappiamo ancora qui.
    // Prepariamo entrambe le interpretazioni e deleghiamo la decisione a
    // router.php, che interroga il DB per capire quale sia.
    $_GET['azienda']  = $parti[0];
    $_GET['catalogo'] = $parti[1];
    $_GET['genere']   = $parti[1];
    require __DIR__ . '/public/router.php';

} else {
    // Tre o più segmenti: non previsto dall'app → pagina non trovata.
    http_response_code(404);
    die("Pagina non trovata.");
}