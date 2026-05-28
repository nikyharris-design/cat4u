<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin');
require_password_changed();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM aziende WHERE id = ?")->execute([$id]);
    $success = "Azienda eliminata.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['crea', 'modifica'])) {
    csrf_verify();

    $id             = (int)($_POST['id'] ?? 0);
    $nome_azienda   = trim($_POST['nome_azienda'] ?? '');
    $tipo_azienda   = trim($_POST['tipo_azienda'] ?? '');
    $partita_iva    = trim($_POST['partita_iva'] ?? '');
    $email_contatto = trim($_POST['email_contatto'] ?? '');

    if (empty($nome_azienda) || empty($tipo_azienda) || empty($partita_iva) || empty($email_contatto)) {
        $error = "Compila tutti i campi.";
    } elseif (!filter_var($email_contatto, FILTER_VALIDATE_EMAIL)) {
        $error = "Email non valida.";
    } else {
        try {
            if ($_POST['action'] === 'crea') {
                $stmt = $pdo->prepare("INSERT INTO aziende (nome_azienda, tipo_azienda, partita_iva, email_contatto) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto]);
                $success = "Azienda creata.";
            } else {
                $stmt = $pdo->prepare("UPDATE aziende SET nome_azienda=?, tipo_azienda=?, partita_iva=?, email_contatto=? WHERE id=?");
                $stmt->execute([$nome_azienda, $tipo_azienda, $partita_iva, $email_contatto, $id]);
                $success = "Azienda aggiornata.";
            }
        } catch (PDOException $e) {
            $error = "Partita IVA già presente nel sistema.";
        }
    }
}

$aziende = $pdo->query("SELECT * FROM aziende ORDER BY nome_azienda ASC")->fetchAll();

$modifica = null;
if (isset($_GET['modifica'])) {
    $stmt = $pdo->prepare("SELECT * FROM aziende WHERE id = ?");
    $stmt->execute([(int)$_GET['modifica']]);
    $modifica = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Aziende — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Gestione Aziende</h2>
            <a href="<?= BASE_URL ?>admin/utenti.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                Gestione Utenti →
            </a>
        </div>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">
                <?= $modifica ? 'Modifica Azienda' : 'Nuova Azienda' ?>
            </h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $modifica ? 'modifica' : 'crea' ?>">
                <?php if ($modifica): ?>
                    <input type="hidden" name="id" value="<?= $modifica['id'] ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nome Azienda</label>
                        <input type="text" name="nome_azienda" required
                               value="<?= htmlspecialchars($modifica['nome_azienda'] ?? $_POST['nome_azienda'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Tipo Azienda</label>
                        <input type="text" name="tipo_azienda" placeholder="es. Retail, Farmacia…" required
                               value="<?= htmlspecialchars($modifica['tipo_azienda'] ?? $_POST['tipo_azienda'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Partita IVA</label>
                        <input type="text" name="partita_iva" maxlength="20" required
                               value="<?= htmlspecialchars($modifica['partita_iva'] ?? $_POST['partita_iva'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email Contatto</label>
                        <input type="email" name="email_contatto" required
                               value="<?= htmlspecialchars($modifica['email_contatto'] ?? $_POST['email_contatto'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                </div>
                <div class="mt-4 flex gap-3">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        <?= $modifica ? 'Salva modifiche' : 'Crea Azienda' ?>
                    </button>
                    <?php if ($modifica): ?>
                        <a href="<?= BASE_URL ?>admin/aziende.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium transition">
                            Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if (empty($aziende)): ?>
            <p class="text-gray-400 text-sm text-center py-8">Nessuna azienda registrata.</p>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Nome</th>
                        <th class="px-4 py-3 text-left">Tipo</th>
                        <th class="px-4 py-3 text-left">P.IVA</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($aziende as $a): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($a['nome_azienda']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['tipo_azienda']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['partita_iva']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($a['email_contatto']) ?></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <a href="?modifica=<?= $a['id'] ?>"
                                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Modifica
                                </a>
                                <form method="POST" onsubmit="return confirm('Eliminare questa azienda?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="elimina">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button type="submit"
                                            class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded text-xs font-medium transition">
                                        Elimina
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>