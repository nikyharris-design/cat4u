<?php
define("BASE_URL", "http://localhost:8080/");

$sessionParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Strict'
];
session_set_cookie_params($sessionParams);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip_partial = substr($ip_address, 0, strrpos($ip_address, '.'));
$user_fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . $ip_partial);

if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $user_fingerprint;
} else {
    if ($_SESSION['fingerprint'] !== $user_fingerprint) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "dashboard/login?error=sessione_non_sicura");
        exit();
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): void {
    $token_ricevuto = $_POST['csrf_token'] ?? '';
    $token_sessione = $_SESSION['csrf_token'] ?? '';
    if (empty($token_sessione) || !hash_equals($token_sessione, $token_ricevuto)) {
        http_response_code(403);
        die("Richiesta non valida. Possibile attacco CSRF.");
    }
}

$timeout_duration = 1800;

if (isset($_SESSION['autorizzato'])) {
    if (isset($_SESSION['last_activity'])) {
        $elapsed_time = time() - $_SESSION['last_activity'];
        if ($elapsed_time > $timeout_duration) {
            session_unset();
            session_destroy();
            header("Location: " . BASE_URL . "dashboard/login?error=timeout");
            exit();
        }
    }
    $_SESSION['last_activity'] = time();
}

function isUrlSafe($url_to_check) {
    $apiKey = $_ENV['GOOGLE_SAFE_BROWSING_KEY'] ?? '';
    if (empty($apiKey)) return true;

    $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . $apiKey;
    $requestData = [
        "client" => ["clientId" => "cat4u", "clientVersion" => "1.0.0"],
        "threatInfo" => [
            "threatTypes"      => ["MALWARE", "SOCIAL_ENGINEERING", "UNWANTED_SOFTWARE"],
            "platformTypes"    => ["ANY_PLATFORM"],
            "threatEntryTypes" => ["URL"],
            "threatEntries"    => [["url" => $url_to_check]]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

    $response = curl_exec($ch);
    if ($response === false || curl_errno($ch)) {
        curl_close($ch);
        return true;
    }
    $result = json_decode($response, true);
    curl_close($ch);
    return empty($result['matches']);
}
