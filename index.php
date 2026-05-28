<?php
/**
 * INDEX.PHP - Front Controller
 * Gestisce tutto il routing dell'applicazione.
 *
 * Rotte:
 *   /                    → redirect a dashboard o login
 *   /dashboard           → dashboard/index.php
 *   /dashboard/login     → dashboard/login.php
 *   /dashboard/logout    → dashboard/logout.php
 *   /dashboard/password  → dashboard/change-password.php
 *   /admin               → admin/index.php
 *   /{slug}              → visualizzazione catalogo pubblico
 */

require_once __DIR__ . '/config/bootstrap.php';

// Legge la rotta passata da .htaccess, la normalizza rimuovendo slash iniziali/finali
$route = trim($_GET['_route'] ?? '', '/');

// --- ROUTING ---
switch ($route) {

    case '':
        // Root: se loggato → dashboard, altrimenti → login
        if (!empty($_SESSION['autorizzato'])) {
            header("Location: " . BASE_URL . "dashboard");
        } else {
            header("Location: " . BASE_URL . "dashboard/login");
        }
        exit();

    case 'dashboard':
        require __DIR__ . '/dashboard/index.php';
        break;

    case 'dashboard/login':
        require __DIR__ . '/dashboard/login.php';
        break;

    case 'dashboard/logout':
        require __DIR__ . '/dashboard/logout.php';
        break;

    case 'dashboard/password':
        require __DIR__ . '/dashboard/change-password.php';
        break;

    case 'admin':
        require __DIR__ . '/admin/index.php';
        break;

    default:
        // Qualsiasi altra rotta viene trattata come slug di un catalogo pubblico
        // Passa lo slug alla pagina catalogo tramite variabile (non querystring)
        $_GET['s'] = $route;
        require __DIR__ . '/public/catalogo.php';
        break;
}
