<?php
require_once __DIR__ . '/config/bootstrap.php';

$route = trim($_GET['_route'] ?? '', '/');

// Nessuna rotta — redirect
if ($route === '') {
    header("Location: " . BASE_URL . (!empty($_SESSION['autorizzato']) ? 'dashboard/index.php' : 'dashboard/login.php'));
    exit();
}

// Rotte dashboard e admin con .php
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

// Rotte pubbliche — parsing slug
$parti = explode('/', $route);

if (count($parti) === 1) {
    // /nome-azienda → libreria
    $_GET['azienda'] = $parti[0];
    require __DIR__ . '/public/libreria.php';

} elseif (count($parti) === 2) {
    // /nome-azienda/nome-catalogo oppure /nome-azienda/nome-genere
    // Proviamo prima come catalogo, poi come genere
    $_GET['azienda']  = $parti[0];
    $_GET['catalogo'] = $parti[1];
    $_GET['genere']   = $parti[1];
    require __DIR__ . '/public/router.php';

} else {
    http_response_code(404);
    die("Pagina non trovata.");
}