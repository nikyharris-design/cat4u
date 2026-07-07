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

// /azienda/qualcosa → il secondo segmento può essere CATALOGO o GENERE.
// Hanno lo stesso pattern URL, quindi la distinzione la fa il DB (non bramus):
// proviamo prima come catalogo attivo; se non esiste, ricadiamo sulla libreria
// (dove $_GET['g'] funge da filtro genere). È la stessa logica che stava in
// public/router.php, ora dichiarata direttamente qui: un solo punto di routing.
$router->get('/([\w-]+)/([\w-]+)', function ($azienda, $secondo) use ($pdo) {
    $_GET['a'] = $azienda;
    $_GET['c'] = $secondo;  // ipotesi: slug catalogo
    $_GET['g'] = $secondo;  // ipotesi: slug genere

    $stmt = $pdo->prepare("
        SELECT c.id FROM cataloghi c
        JOIN aziende a ON a.id = c.azienda_id
        WHERE a.slug = ? AND c.slug = ? AND c.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$azienda, $secondo]);

    if ($stmt->fetch()) {
        require __DIR__ . '/public/catalogo.php';   // è un catalogo
    } else {
        require __DIR__ . '/public/libreria.php';    // è (forse) un genere → libreria filtrata
    }
    exit();
});

// /azienda → libreria pubblica.
// @phpstan-ignore closure.unusedUse
$router->get('/([\w-]+)', function ($azienda) use ($pdo) {
    // use ($pdo): la rotta a due segmenti sopra importa già $pdo nella closure;
    // questa a un segmento se n'era dimenticata. Senza, libreria.php incluso qui
    // dentro gira nello scope della funzione e non vede $pdo → null->prepare().
    $_GET['a'] = $azienda;
    require __DIR__ . '/public/libreria.php';
    exit();
});

// --------------------------------------------------------------------------
// 404 — rotte non riconosciute.

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
// Neutralizza l'auto-rilevamento del base path di bramus/router.
// Di default bramus deduce il base path da SCRIPT_NAME (/cat4u/index.php → /cat4u)
// e lo rimuove dalla rotta, "mangiando" i primi caratteri dello slug
// (es. /cabaddu-srl → u-srl). Noi passiamo già in REQUEST_URI una rotta pulita
// (vedi riga sopra), quindi forziamo SCRIPT_NAME a /index.php: così il base path
// calcolato è vuoto e la rotta viene usata così com'è.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$router->run();