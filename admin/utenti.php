<?php
/**
 * ==========================================================================
 * UTENTI.PHP — Gestione utenti (superadmin e admin)
 * ==========================================================================
 *
 * Permette di creare ed eliminare utenti. È accessibile a DUE ruoli, con
 * permessi diversi:
 *
 *   SUPERADMIN: vede tutti gli utenti, può creare qualsiasi ruolo
 *               (superadmin/admin/user) e assegnarli a qualsiasi azienda.
 *   ADMIN:      vede solo gli utenti della PROPRIA azienda e può creare
 *               soltanto utenti "user" di quella stessa azienda.
 *
 * Il principio guida è: non fidarsi MAI di ciò che arriva dal form per i campi
 * "sensibili" (ruolo, azienda). Per l'admin questi valori vengono FORZATI lato
 * server, ignorando quanto inviato dal browser.
 *
 * Sicurezza:
 *   - password temporanea casuale + obbligo di cambio al primo accesso
 *   - un utente non può eliminare sé stesso
 *   - l'admin può eliminare solo utenti della propria azienda
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin');
require_password_changed();

$user          = current_user();
// Flag comodo: ci serve in molti punti per distinguere i due livelli di permesso.
$is_superadmin = $user['role'] === 'superadmin';

$error   = '';
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un utente
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$_SESSION['user_id']) {
        // Protezione anti-autodistruzione: non puoi cancellare il tuo account.
        $error = "Non puoi eliminare il tuo account.";
    } else {
        // Se è un admin, può eliminare SOLO utenti della propria azienda.
        // Verifichiamo l'appartenenza prima di procedere.
        if (!$is_superadmin) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$id, $user['azienda_id']]);
            if (!$stmt->fetch()) {
                $error = "Operazione non consentita.";
            }
        }
        // Procediamo solo se nessun controllo ha sollevato un errore.
        if (empty($error)) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $success = "Utente eliminato.";
        }
    }
}

// --------------------------------------------------------------------------
// AZIONE: CREA un utente
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'user';

    // --- CHI PUÒ CREARE COSA (la parte più delicata) ---
    if ($is_superadmin) {
        // Il superadmin può anche creare superadmin (azienda nulla) o assegnare
        // un'azienda. Stringa vuota → null (utente senza azienda).
        $azienda_id = $_POST['azienda_id'] === '' ? null : (int)$_POST['azienda_id'];
    } else {
        // ADMIN: ignoriamo qualsiasi azienda/ruolo arrivati dal form e li
        // FORZIAMO ai valori consentiti. Anche se un attaccante manomettesse i
        // campi nascosti, qui non avrebbe effetto: l'azienda è la sua e il ruolo
        // è sempre "user".
        $azienda_id = (int)$user['azienda_id'];
        $role = 'user';
    }

    // --- VALIDAZIONI ---
    if (empty($name) || empty($email)) {
        $error = "Nome ed email sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email non valida.";
    } elseif (!in_array($role, ['superadmin', 'admin', 'user'], true)) {
        // Whitelist dei ruoli ammessi: nessun valore "creativo".
        $error = "Ruolo non valido.";
    } elseif ($role !== 'superadmin' && $azienda_id === null) {
        // Solo il superadmin può non avere azienda; ogni altro ruolo deve averne una.
        $error = "Seleziona un'azienda per questo ruolo.";
    } else {
        // --- PASSWORD TEMPORANEA ---
        // Generiamo una password casuale sicura (16 caratteri esadecimali).
        $temp_password = bin2hex(random_bytes(8));
        // Salviamo solo l'hash, mai la password in chiaro.
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);
        try {
            // must_change_password = 1 → al primo login sarà costretto a cambiarla.
            $stmt = $pdo->prepare("
                INSERT INTO users (azienda_id, name, email, password, role, must_change_password)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$azienda_id, $name, $email, $hash, $role]);
            // Mostriamo la password temporanea UNA SOLA VOLTA: va comunicata
            // subito all'utente, perché non sarà più recuperabile (nel DB c'è solo l'hash).
            $success = "Utente creato. Password temporanea: <strong>" . htmlspecialchars($temp_password) . "</strong> — comunicala all'utente, non verrà mostrata di nuovo.";
        } catch (PDOException $e) {
            // Verosimilmente il vincolo UNIQUE sull'email.
            $error = "Email già presente nel sistema.";
        }
    }
}

// --------------------------------------------------------------------------
// ELENCO UTENTI (filtrato in base al ruolo di chi guarda)
// --------------------------------------------------------------------------
if ($is_superadmin) {
    // Superadmin: tutti gli utenti, con il nome azienda via LEFT JOIN
    // (LEFT perché un superadmin può non avere azienda → resta NULL).
    $utenti = $pdo->query("
        SELECT u.*, a.nome_azienda
        FROM users u
        LEFT JOIN aziende a ON a.id = u.azienda_id
        ORDER BY u.role, u.name ASC
    ")->fetchAll();
} else {
    // Admin: solo gli utenti della propria azienda.
    $stmt = $pdo->prepare("
        SELECT u.*, a.nome_azienda
        FROM users u
        LEFT JOIN aziende a ON a.id = u.azienda_id
        WHERE u.azienda_id = ?
        ORDER BY u.role, u.name ASC
    ");
    $stmt->execute([(int)$user['azienda_id']]);
    $utenti = $stmt->fetchAll();
}

// L'elenco aziende serve solo al superadmin (per il menu nel form di creazione).
$aziende = $is_superadmin
    ? $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll()
    : [];
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Utenti — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Gestione Utenti</h2>
            <a href="<?= BASE_URL ?>admin/aziende.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                ← Aziende
            </a>
        </div>

        <!-- $error/$success contengono HTML voluto (la password in <strong>),
             quindi NON passano da htmlspecialchars qui. Il dato variabile interno
             (la password) è però già stato "escapato" sopra al momento di costruirlo. -->
        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $error ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $success ?></p>
        <?php endif; ?>

        <!-- FORM NUOVO UTENTE -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Nuovo Utente</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="crea">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nome</label>
                        <input type="text" name="name" required
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>

                    <!-- Il superadmin sceglie ruolo e azienda; l'admin no. -->
                    <?php if ($is_superadmin): ?>
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-1">Ruolo</label>
    <!-- onchange chiama toggleAzienda(): nasconde il campo azienda se si sceglie
         "superadmin" (che non ha azienda). È solo UX: il controllo vero è server-side. -->
    <select name="role" id="role-select" 
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <option value="admin">Admin</option>
        <option value="user">User</option>
        <option value="superadmin">Superadmin</option>
    </select>
</div>
<div id="azienda-field">
    <label class="block text-sm font-semibold text-gray-700 mb-1">Azienda</label>
    <select name="azienda_id"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        <option value="">— Seleziona —</option>
        <?php foreach ($aziende as $az): ?>
            <option value="<?= $az['id'] ?>"><?= htmlspecialchars($az['nome_azienda']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php else: ?>
<!-- Per l'admin il ruolo è mostrato come campo disabilitato (solo visuale).
     Un campo "disabled" NON viene inviato dal form, perciò aggiungiamo un
     hidden con value="user". In ogni caso il PHP forza "user" lato server. -->
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-1">Ruolo</label>
    <input type="text" value="User" disabled
           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-400">
    <input type="hidden" name="role" value="user">
</div>
<?php endif; ?>
                </div>
                <div class="mt-4">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        Crea Utente
                    </button>
                </div>
            </form>
        </div>

        <!-- TABELLA UTENTI -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Nome</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Ruolo</th>
                        <th class="px-4 py-3 text-left">Azienda</th>
                        <th class="px-4 py-3 text-left">Pwd cambiata</th>
                        <th class="px-4 py-3 text-left">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($utenti as $u): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($u['name']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['email']) ?></td>
                        <td class="px-4 py-3">
                            <?php
                            // match() (PHP 8) sceglie il colore del badge in base al ruolo.
                            $badge = match($u['role']) {
                                'superadmin' => 'bg-purple-100 text-purple-700',
                                'admin'      => 'bg-blue-100 text-blue-700',
                                default      => 'bg-gray-100 text-gray-700',
                            };
                            ?>
                            <span class="<?= $badge ?> px-2 py-0.5 rounded-full text-xs font-semibold">
                                <?= $u['role'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['nome_azienda'] ?? '—') ?></td>
                        <td class="px-4 py-3">
                            <!-- Mostra se l'utente ha già cambiato la password iniziale. -->
                            <?php if ($u['must_change_password']): ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">No</span>
                            <?php else: ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Sì</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <!-- Non si mostra il pulsante "Elimina" sulla propria riga. -->
                            <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" data-confirm="Eliminare questo utente?">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="elimina">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit"
                                        class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Elimina
                                </button>
                            </form>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">Tu</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>