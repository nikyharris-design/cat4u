<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin' , 'user');
require_password_changed();

$user       = current_user();

// Superadmin può selezionare l'azienda
if ($user['role'] === 'superadmin') {
    $azienda_id = (int)($_GET['az'] ?? $_POST['az'] ?? 0);
    $aziende_list = $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll();
} else {
    $azienda_id = (int)$user['azienda_id'];
    $aziende_list = [];
}

$error   = '';
$success = '';

function make_slug(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM generi WHERE id = ?")->execute([$id]);
        $success = "Genere eliminato.";
    } else {
        $error = "Operazione non consentita.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();
    $nome_genere = trim($_POST['nome_genere'] ?? '');

    if (empty($nome_genere)) {
        $error = "Il nome del genere è obbligatorio.";
    } else {
        $base_slug = make_slug($nome_genere);
        $slug = $base_slug;
        $i = 1;
        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM generi WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;
            $slug = $base_slug . '-' . $i++;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO generi (azienda_id, nome_genere, slug) VALUES (?, ?, ?)");
            $stmt->execute([$azienda_id, $nome_genere, $slug]);
            $success = "Genere creato.";
        } catch (PDOException $e) {
            $error = "Errore durante la creazione del genere.";
        }
    }
}

$generi = $pdo->prepare("SELECT * FROM generi WHERE azienda_id = ? ORDER BY nome_genere ASC");
$generi->execute([$azienda_id]);
$generi = $generi->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Generi — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Gestione Generi</h2>
            <a href="<?= BASE_URL ?>dashboard/index.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                ← Dashboard
            </a>
        </div>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>
        <?php if ($user['role'] === 'superadmin'): ?>
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" action="" class="flex items-center gap-3">
        <label class="text-sm font-semibold text-gray-700">Azienda:</label>
        <select name="az" onchange="this.form.submit()"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <option value="">— Seleziona azienda —</option>
            <?php foreach ($aziende_list as $az): ?>
                <option value="<?= $az['id'] ?>" <?= $azienda_id === $az['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($az['nome_azienda']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php endif; ?>

        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Nuovo Genere</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="crea">
                <div class="flex gap-3">
                    <input type="text" name="nome_genere"
                           placeholder="es. Alimentari, Abbigliamento…" required
                           value="<?= htmlspecialchars($_POST['nome_genere'] ?? '') ?>"
                           class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                        Aggiungi
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <?php if (empty($generi)): ?>
                <p class="text-gray-400 text-sm text-center py-8">Nessun genere creato. Aggiungine uno per iniziare.</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Nome</th>
                        <th class="px-4 py-3 text-left">Slug</th>
                        <th class="px-4 py-3 text-left">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($generi as $g): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($g['nome_genere']) ?></td>
                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?= htmlspecialchars($g['slug']) ?></code></td>
                        <td class="px-4 py-3">
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Eliminare questo genere?')">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="elimina">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <button type="submit"
                                        class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Elimina
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>