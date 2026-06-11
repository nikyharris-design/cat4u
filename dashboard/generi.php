<?php
/**
 * ==========================================================================
 * GENERI.PHP — Gestione dei generi (categorie dei cataloghi)
 * ==========================================================================
 *
 * CRUD ridotto (Crea / Elenca / Elimina) dei "generi" con cui si raggruppano
 * i cataloghi di un'azienda (es. Alimentari, Abbigliamento…).
 *
 * Due pattern ricorrenti introdotti qui (li ritroverai in cataloghi.php e
 * aziende.php):
 *
 *  1) SELEZIONE AZIENDA IN BASE AL RUOLO
 *     - superadmin: sceglie l'azienda da un menu a tendina (parametro ?az=).
 *     - admin/user: l'azienda è fissa, quella del proprio account.
 *
 *  2) SLUG UNIVOCI
 *     - dal nome si ricava uno "slug" adatto agli URL (minuscolo, senza accenti
 *       né spazi). Se è già usato, si aggiunge un suffisso numerico.
 *
 * Tutte le azioni che modificano dati (POST) sono protette da CSRF e usano
 * prepared statement.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Accesso consentito a tutti e tre i ruoli autenticati.
require_role( 'admin' , 'user');
require_password_changed();

$user = current_user();

// Da quale azienda stiamo operando. Admin e user sono sempre vincolati alla
// propria azienda (il superadmin non accede a questa pagina: vedi require_role).
$azienda_id = (int)$user['azienda_id'];

$error   = '';
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un genere
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    // Controllo di proprietà: eliminiamo solo se il genere appartiene davvero
    // all'azienda corrente. Impedisce di cancellare generi di altre aziende
    // manomettendo l'id nel form.
    $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM generi WHERE id = ?")->execute([$id]);
        $success = "Genere eliminato.";
    } else {
        $error = "Operazione non consentita.";
    }
}

// --------------------------------------------------------------------------
// AZIONE: CREA un genere
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();
    $nome_genere = trim($_POST['nome_genere'] ?? '');

    if (empty($nome_genere)) {
        $error = "Il nome del genere è obbligatorio.";
    } else {
        // --- PATTERN 2: generazione slug univoco ---
        $base_slug = make_slug($nome_genere);
        $slug = $base_slug;
        $i = 1;
        // Cicliamo finché lo slug risulta già presente: in tal caso proviamo
        // base-slug-1, base-slug-2, ecc. Usciamo appena ne troviamo uno libero.
        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM generi WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;       // slug libero → esci dal ciclo
            $slug = $base_slug . '-' . $i++;  // occupato → prova il successivo
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO generi (azienda_id, nome_genere, slug) VALUES (?, ?, ?)");
            $stmt->execute([$azienda_id, $nome_genere, $slug]);
            $success = "Genere creato.";
        } catch (PDOException $e) {
            // Rete di sicurezza in caso di violazione di vincoli del DB.
            $error = "Errore durante la creazione del genere.";
        }
    }
}

// Elenco dei generi dell'azienda corrente, da mostrare in tabella.
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
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
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

        <!-- Esiti delle azioni. Qui i messaggi sono testo fisso definito da noi,
             ma htmlspecialchars resta una buona abitudine. -->
        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>



        <!-- FORM: nuovo genere. -->
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

        <!-- TABELLA: elenco generi (o messaggio se vuoto). -->
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
                            <!-- Eliminazione con conferma JavaScript. Il vero
                                 controllo di sicurezza resta lato server (CSRF +
                                 verifica di proprietà). -->
                            <form method="POST" style="display:inline"
                                  data-confirm="Eliminare questo genere?">
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