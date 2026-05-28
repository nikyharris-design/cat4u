<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin');
require_password_changed();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)$_SESSION['user_id']) {
        $error = "Non puoi eliminare il tuo account.";
    } else {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $success = "Utente eliminato.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();

    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? 'admin';
    $azienda_id = $_POST['azienda_id'] === '' ? null : (int)$_POST['azienda_id'];

    if (empty($name) || empty($email)) {
        $error = "Nome ed email sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email non valida.";
    } elseif (!in_array($role, ['superadmin', 'admin', 'user'], true)) {
        $error = "Ruolo non valido.";
    } elseif ($role !== 'superadmin' && $azienda_id === null) {
        $error = "Seleziona un'azienda per questo ruolo.";
    } else {
        $temp_password = bin2hex(random_bytes(8));
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (azienda_id, name, email, password, role, must_change_password)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$azienda_id, $name, $email, $hash, $role]);
            $success = "Utente creato. Password temporanea: <strong>" . htmlspecialchars($temp_password) . "</strong> — comunicala all'utente, non verrà mostrata di nuovo.";
        } catch (PDOException $e) {
            $error = "Email già presente nel sistema.";
        }
    }
}

$utenti = $pdo->query("
    SELECT u.*, a.nome_azienda
    FROM users u
    LEFT JOIN aziende a ON a.id = u.azienda_id
    ORDER BY u.role, u.name ASC
")->fetchAll();

$aziende = $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Utenti — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
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

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $error ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $success ?></p>
        <?php endif; ?>

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
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ruolo</label>
                        <select name="role" id="role-select" onchange="toggleAzienda(this.value)"
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
                </div>
                <div class="mt-4">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        Crea Utente
                    </button>
                </div>
            </form>
        </div>

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
                            <?php if ($u['must_change_password']): ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">No</span>
                            <?php else: ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Sì</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Eliminare questo utente?')">
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

    <script>
    function toggleAzienda(role) {
        document.getElementById('azienda-field').style.display =
            role === 'superadmin' ? 'none' : 'block';
    }
    </script>
</body>
</html>