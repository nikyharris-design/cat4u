<?php
/**
 * ==========================================================================
 * RATELIMIT.PHP — Limitazione dei tentativi (anti brute-force / anti-spam)
 * ==========================================================================
 *
 * Funzioni riutilizzabili basate sulla tabella rate_limit_attempts.
 * Vanno rese disponibili includendo questo file nel bootstrap (dopo auth.php):
 *     require_once __DIR__ . '/ratelimit.php';
 *
 * Modello d'uso tipico:
 *   - PRIMA dell'azione: rate_too_many() per decidere se bloccare.
 *   - SU tentativo fallito (o su ogni richiesta, a seconda del caso): rate_record().
 *   - SU successo (es. login corretto): rate_clear() per non penalizzare l'utente.
 *
 * Le funzioni usano $pdo globale (disponibile dopo config.php).
 */

/**
 * Registra un tentativo per (azione, chiave).
 * Ogni tanto (~1% delle volte) ripulisce le righe più vecchie di 1 giorno:
 * una manutenzione "opportunistica" che evita che la tabella cresca all'infinito
 * senza bisogno di un cron dedicato.
 */
function rate_record(string $action, string $key): void {
    global $pdo;
    $pdo->prepare("INSERT INTO rate_limit_attempts (action, rl_key) VALUES (?, ?)")
        ->execute([$action, $key]);

    if (random_int(1, 100) === 1) {
        $pdo->query("DELETE FROM rate_limit_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

/**
 * Conta i tentativi per (azione, chiave) negli ultimi $windowSeconds secondi.
 *
 * Nota tecnica: $windowSeconds NON è un parametro legato (?) ma inserito diretto
 * nella query. È sicuro perché lo forziamo a intero con (int) e NON proviene
 * dall'utente: i segnaposto dentro "INTERVAL ? SECOND" darebbero problemi con MySQL.
 */
function rate_count(string $action, string $key, int $windowSeconds): int {
    global $pdo;
    $windowSeconds = (int)$windowSeconds;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM rate_limit_attempts
        WHERE action = ? AND rl_key = ?
          AND created_at > (NOW() - INTERVAL $windowSeconds SECOND)
    ");
    $stmt->execute([$action, $key]);
    return (int)$stmt->fetchColumn();
}

/**
 * True se i tentativi recenti hanno raggiunto/superato il massimo consentito.
 *
 * @param string $action        nome dell'azione (es. 'login')
 * @param string $key           identificatore (es. IP)
 * @param int    $max           numero massimo di tentativi nella finestra
 * @param int    $windowSeconds ampiezza della finestra in secondi
 */
function rate_too_many(string $action, string $key, int $max, int $windowSeconds): bool {
    return rate_count($action, $key, $windowSeconds) >= $max;
}

/**
 * Azzera i tentativi per (azione, chiave). Da chiamare quando l'azione riesce
 * (es. login corretto), così l'utente legittimo riparte "pulito".
 */
function rate_clear(string $action, string $key): void {
    global $pdo;
    $pdo->prepare("DELETE FROM rate_limit_attempts WHERE action = ? AND rl_key = ?")
        ->execute([$action, $key]);
}

/**
 * Restituisce l'IP del client. Helper minimo per avere una "chiave" coerente.
 *
 * ATTENZIONE: se l'app girasse dietro un proxy/load balancer, l'IP reale
 * sarebbe in X-Forwarded-For e andrebbe gestito con cautela (quell'header è
 * falsificabile se non filtrato dal proxy). In hosting diretto, REMOTE_ADDR va bene.
 */
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}