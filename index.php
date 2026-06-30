<?php
/**
 * ==========================================================================
 * INDEX.PHP — Front Controller con bramus/router (rotte raggruppate)
 * ==========================================================================
 *
 * bramus/router fa SOLO l'instradamento: per ogni rotta richiama i file
 * esistenti, invariati. Approccio conservativo: la logica catalogo-vs-genere
 * resta in public/router.php.
 *
 * Le rotte sono raggruppate per area con mount(): dentro un gruppo, i path sono
 * RELATIVI al prefisso (es. dentro mount('/dashboard'), '/generi' = /dashboard/generi).
 * Raggruppare non cambia il comportamento: serve solo a tenere il file ordinato
 * quando le rotte crescono.
 */

require_once __DIR__ . '/config/bootstrap.php';

use Bramus\Router\Router;

$router = new Router();

// Helper: include un file e termina (come il vecchio require + exit).
$includi = function (string $relPath): void {
    require __DIR__ . $relPath;
    exit();
};

// --------------------------------------------------------------------------
// ROTTA VUOTA — redirect a dashboard (se loggato) o login.
// --------------------------------------------------------------------------
$router->get('/', function () {
    header("Location: " . BASE_URL . (!empty($_SESSION['autorizzato']) ? 'dashboard/index.php' : 'dashboard/login.php'));
    exit();
});

// --------------------------------------------------------------------------
// AREA DASHBOARD — tutto ciò che sta sotto /dashboard.
// --------------------------------------------------------------------------
$router->mount('/dashboard', function () use ($router, $includi) {
    // /dashboard (indice del gruppo): si dichiara con '/'.
    $router->get('/', fn() => $includi('/dashboard/index.php'));

    $router->match('GET|POST', '/login',     fn() => $includi('/dashboard/login.php'));
    $router->get('/logout',                  fn() => $includi('/dashboard/logout.php'));
    $router->match('GET|POST', '/password',  fn() => $includi('/dashboard/change-password.php'));
    $router->match('GET|POST', '/cataloghi', fn() => $includi('/dashboard/cataloghi.php'));
    $router->match('GET|POST', '/generi',    fn() => $includi('/dashboard/generi.php'));
});

// --------------------------------------------------------------------------
// AREA ADMIN — tutto ciò che sta sotto /admin.
// --------------------------------------------------------------------------
$router->mount('/admin', function () use ($router, $includi) {
    $router->match('GET|POST', '/aziende', fn() => $includi('/admin/aziende.php'));
    $router->match('GET|POST', '/utenti',  fn() => $includi('/admin/utenti.php'));
});

// --------------------------------------------------------------------------
// ROTTE PUBBLICHE — gli slug. La più specifica (2 segmenti) PRIMA.
// --------------------------------------------------------------------------

// /azienda/qualcosa → disambiguazione catalogo-vs-genere (logica nel DB).
$router->get('/([\w-]+)/([\w-]+)', function ($azienda, $secondo) {
    $_GET['a'] = $azienda;
    $_GET['c'] = $secondo;  // ipotesi catalogo
    $_GET['g'] = $secondo;  // ipotesi genere
    require __DIR__ . '/public/router.php';
    exit();
});

// /azienda → libreria pubblica.
$router->get('/([\w-]+)', function ($azienda) {
    $_GET['a'] = $azienda;
    require __DIR__ . '/public/libreria.php';
    exit();
});

// --------------------------------------------------------------------------
// 404 — rotte non riconosciute.
// --------------------------------------------------------------------------
$router->set404(function () {
    not_found();
});

// --------------------------------------------------------------------------
// Avvio: passiamo a bramus la rotta "pulita" dall'.htaccess.
// --------------------------------------------------------------------------
$route = '/' . trim($_GET['_route'] ?? '', '/');
$_SERVER['REQUEST_URI'] = $route;

$router->run();