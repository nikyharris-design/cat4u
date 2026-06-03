<?php
/**
 * ==========================================================================
 * MAILER.PHP — Invio email via SMTP (PHPMailer)
 * ==========================================================================
 *
 * Helper per spedire email. NON è incluso da bootstrap.php (servirebbe solo su
 * poche pagine): va richiesto dove serve, es.
 *     require_once __DIR__ . '/../config/mailer.php';
 *
 * Posizione corretta del file: config/mailer.php
 *
 * Prerequisiti:
 *   - composer require phpmailer/phpmailer
 *   - variabili SMTP nel file .env (vedi sotto). Se mancano, l'invio fallisce
 *     in modo controllato (ritorna false e logga), senza bloccare l'app.
 *
 * Variabili .env attese:
 *   SMTP_HOST       host del server SMTP (es. smtp.gmail.com)
 *   SMTP_PORT       porta (587 per STARTTLS, 465 per SMTPS)
 *   SMTP_USER       utente SMTP
 *   SMTP_PASS       password SMTP (per Gmail: una "app password")
 *   SMTP_SECURE     'tls' (STARTTLS) oppure 'ssl' (SMTPS)
 *   MAIL_FROM       indirizzo mittente (es. noreply@tuodominio.it)
 *   MAIL_FROM_NAME  nome mittente visualizzato (es. Cat4U)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Invia un'email HTML a un destinatario.
 *
 * @param  string $to       indirizzo destinatario
 * @param  string $subject  oggetto
 * @param  string $htmlBody corpo in HTML
 * @return bool   true se l'invio è riuscito, false altrimenti (errore loggato)
 */
function send_email(string $to, string $subject, string $htmlBody): bool {
    // $log è creato in config.php a livello globale: lo richiamiamo qui dentro
    // per poter registrare eventuali errori di invio.
    global $log;

    // "true" attiva le eccezioni: gli errori diventano catchabili invece di
    // restare warning silenziosi.
    $mail = new PHPMailer(true);

    try {
        // --- Configurazione del trasporto SMTP ---
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'] ?? '';
        $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
        // STARTTLS (porta 587) o SMTPS (porta 465) a seconda del .env.
        $mail->SMTPSecure = ($_ENV['SMTP_SECURE'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
        $mail->CharSet    = 'UTF-8'; // accenti e caratteri speciali corretti

        // --- Mittente e destinatario ---
        $mail->setFrom($_ENV['MAIL_FROM'] ?? 'noreply@localhost', $_ENV['MAIL_FROM_NAME'] ?? 'Cat4U');
        $mail->addAddress($to);

        // --- Contenuto ---
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        // Versione testuale per i client che non leggono HTML: togliamo i tag.
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Non mostriamo l'errore all'utente; lo registriamo per il debug.
        // $mail->ErrorInfo contiene il dettaglio tecnico dell'invio fallito.
        $log->error('Invio email fallito', ['to' => $to, 'error' => $mail->ErrorInfo]);
        return false;
    }
}

/**
 * Costruisce e invia l'email di recupero password.
 *
 * @param  string $to       email dell'utente
 * @param  string $resetUrl link completo con il token (monouso)
 * @return bool   esito dell'invio
 */
function send_password_reset_email(string $to, string $resetUrl): bool {
    $subject = 'Cat4U — Reimposta la tua password';

    // Corpo HTML semplice. L'URL è generato da noi (non è input utente), quindi
    // è sicuro inserirlo cosi' com'è.
    $body = '
        <div style="font-family:sans-serif;max-width:480px;margin:auto">
            <h2 style="color:#4f46e5">Cat4U</h2>
            <p>Hai richiesto di reimpostare la password del tuo account.</p>
            <p>Clicca sul pulsante qui sotto. Il link è valido per <strong>1 ora</strong>
               e può essere usato una sola volta.</p>
            <p style="margin:24px 0">
                <a href="' . $resetUrl . '"
                   style="background:#4f46e5;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">
                    Reimposta password
                </a>
            </p>
            <p style="color:#6b7280;font-size:13px">
                Se non hai richiesto tu il reset, ignora questa email: la tua
                password resterà invariata.
            </p>
        </div>
    ';

    return send_email($to, $subject, $body);
}