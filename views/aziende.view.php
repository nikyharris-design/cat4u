<?php
/**
 * ==========================================================================
 * AZIENDE.VIEW.PHP — Vista della pagina "Gestione Aziende"
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da admin/aziende.php dopo che il controller ha
 * preparato i dati. Variabili già disponibili: $error, $success, $aziende,
 * $modifica (null in creazione, riga azienda in modifica), $user (dall'header).
 * Non va mai aperta direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Aziende — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
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

       <?php if (!empty($errors)): ?>
            <div class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $msg): ?>
                        <li><?= htmlspecialchars($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <!-- FORM CREA/MODIFICA. Titolo e action cambiano in base a $modifica. -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">
                <?= $modifica ? 'Modifica Azienda' : 'Nuova Azienda' ?>
            </h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <!-- Dice al controller quale ramo eseguire. -->
                <input type="hidden" name="action" value="<?= $modifica ? 'modifica' : 'crea' ?>">
                <!-- In modifica passiamo anche l'id della riga da aggiornare. -->
                <?php if ($modifica): ?>
                    <input type="hidden" name="id" value="<?= $modifica['id'] ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Nome Azienda</label>
                        <!-- value: in modifica mostra il dato esistente; altrimenti
                             ripropone l'eventuale input dopo un errore di validazione. -->
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
                    <!-- In modifica mostriamo "Annulla" per tornare alla creazione. -->
                    <?php if ($modifica): ?>
                        <a href="<?= BASE_URL ?>admin/aziende.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium transition">
                            Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- TABELLA AZIENDE. -->
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
                        <th class="px-4 py-3 text-left">Libreria</th>
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
                            <!-- Link alla libreria pubblica + download del QR aziendale. -->
                            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($a['slug']) ?>" target="_blank"
                               class="text-indigo-600 hover:underline font-mono text-xs">
                                /<?= htmlspecialchars($a['slug']) ?>
                            </a><br>
                            <?php if ($a['qr_code_path']): ?>
                            <a href="<?= BASE_URL . htmlspecialchars($a['qr_code_path']) ?>"
                               download="qr-<?= htmlspecialchars($a['slug']) ?>.png"
                               class="text-xs text-gray-500 hover:text-gray-700 underline">
                                ↓ QR Libreria
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <!-- "Modifica" ricarica la pagina con ?modifica=ID → precompila il form. -->
                                <a href="?modifica=<?= $a['id'] ?>"
                                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Modifica
                                </a>
                                <!-- Eliminazione con conferma JS + CSRF. -->
                                <form method="POST" data-confirm="Eliminare questa azienda?">
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