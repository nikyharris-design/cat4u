<?php
/**
 * ==========================================================================
 * CATALOGHI.VIEW.PHP — Vista della pagina "Cataloghi"
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da dashboard/cataloghi.php dopo che il controller
 * ha preparato i dati. Variabili già disponibili: $user, $error, $success,
 * $generi, $cataloghi, $modifica (null in creazione), $scadenza_value.
 *
 * Il form è unico per "carica" (crea) e "modifica": il campo nascosto 'action'
 * e $modifica decidono comportamento e precompilazione. Non va mai aperta
 * direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Cataloghi — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Cataloghi</h2>
            <a href="<?= BASE_URL ?>dashboard/generi.php"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                Gestione Generi
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
            <p class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $error ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm"><?= $success ?></p>
        <?php endif; ?>

        <?php if ($is_superadmin): ?>
            <!-- FILTRO AZIENDA (solo superadmin): 0 = tutte. -->
            <div class="bg-white rounded-xl shadow p-6 mb-6">
                <form method="GET" action="">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Filtra per azienda</label>
                    <div class="flex items-center gap-2">
                        <select name="az" data-autosubmit
                                class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <option value="0">— Tutte le aziende —</option>
                            <?php foreach ($aziende_list as $az): ?>
                                <option value="<?= $az['id'] ?>" <?= $filtro_azienda === (int)$az['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($az['nome_azienda']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm">Filtra</button></noscript>
                    </div>
                </form>
            </div>
        <?php elseif (empty($generi)): ?>
            <div class="bg-yellow-50 text-yellow-800 px-4 py-3 rounded-lg mb-6 text-sm">
                Devi prima creare almeno un <a href="<?= BASE_URL ?>dashboard/generi.php" class="font-semibold underline">genere</a> prima di caricare cataloghi.
            </div>
        <?php else: ?>
        <!-- FORM unico: crea ("carica") oppure modifica, a seconda di $modifica. -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">
                <?= $modifica ? 'Modifica catalogo' : 'Carica nuovo catalogo' ?>
            </h3>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $modifica ? 'modifica' : 'carica' ?>">
                <?php if ($modifica): ?>
                    <input type="hidden" name="id" value="<?= $modifica['id'] ?>">
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Titolo</label>
                        <input type="text" name="titolo" required
                               value="<?= htmlspecialchars($modifica['titolo'] ?? $_POST['titolo'] ?? '') ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Genere</label>
                        <select name="genere_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <option value="">— Seleziona —</option>
                            <?php foreach ($generi as $g): ?>
                                <!-- In modifica preselezioniamo il genere corrente del catalogo. -->
                                <option value="<?= $g['id'] ?>"
                                    <?= (($modifica['genere_id'] ?? $_POST['genere_id'] ?? 0) == $g['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($g['nome_genere']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            File PDF
                            <?php if ($modifica): ?>
                                <span class="text-gray-400 font-normal">(lascia vuoto per mantenere quello attuale)</span>
                            <?php else: ?>
                                <span class="text-gray-400 font-normal">(max 20MB)</span>
                            <?php endif; ?>
                        </label>
                        <!-- required solo in creazione; in modifica il PDF è facoltativo. -->
                        <input type="file" name="pdf" accept="application/pdf" <?= $modifica ? '' : 'required' ?>
                               class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            Data scadenza <span class="text-gray-400 font-normal">(opzionale)</span>
                        </label>
                        <input type="date" name="data_scadenza"
                               value="<?= htmlspecialchars($scadenza_value) ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                </div>
                <div class="mt-4 flex gap-3">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">
                        <?= $modifica ? 'Salva modifiche' : 'Carica e genera QR' ?>
                    </button>
                    <?php if ($modifica): ?>
                        <!-- Annulla: torna alla pagina cataloghi. -->
                        <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium transition">
                            Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-500 uppercase">Cataloghi pubblicati</h3>
            </div>
            <?php if (empty($cataloghi)): ?>
                <p class="text-gray-400 text-sm text-center py-8">Nessun catalogo ancora caricato.</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <?php if ($is_superadmin): ?><th class="px-4 py-3 text-left">Azienda</th><?php endif; ?>
                        <th class="px-4 py-3 text-left">Titolo</th>
                        <th class="px-4 py-3 text-left">Genere</th>
                        <th class="px-4 py-3 text-left">Scadenza</th>
                        <th class="px-4 py-3 text-left">Stato</th>
                        <th class="px-4 py-3 text-left">URL / QR</th>
                        <?php if (!$is_superadmin): ?><th class="px-4 py-3 text-left">Azioni</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($cataloghi as $c): ?>
                    <tr class="hover:bg-gray-50">
                        <?php if ($is_superadmin): ?>
                        <td class="px-4 py-3 font-semibold text-gray-700"><?= htmlspecialchars($c['nome_azienda']) ?></td>
                        <?php endif; ?>
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($c['titolo']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($c['nome_genere']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= $c['data_scadenza'] ? date('d/m/Y', strtotime($c['data_scadenza'])) : '—' ?></td>
                        <td class="px-4 py-3">
                            <?php if ($c['is_active']): ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Attivo</span>
                            <?php else: ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">Inattivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="<?= BASE_URL ?>public/catalogo.php?a=<?= htmlspecialchars($c['azienda_slug'] ?? '') ?>&c=<?= htmlspecialchars($c['slug']) ?>" target="_blank"
                               class="text-indigo-600 hover:underline font-mono text-xs">
                                /<?= htmlspecialchars($c['slug']) ?>
                            </a><br>
                            <a href="<?= BASE_URL . htmlspecialchars($c['qr_code_path']) ?>"
                               download="qr-<?= htmlspecialchars($c['slug']) ?>.png"
                               class="text-xs text-gray-500 hover:text-gray-700 underline">
                                ↓ QR
                            </a>
                        </td>
                        <?php if (!$is_superadmin): ?>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <!-- MODIFICA: ricarica la pagina in modalità modifica. -->
                                <a href="?modifica=<?= $c['id'] ?>"
                                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                    Modifica
                                </a>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="<?= $c['is_active'] ? 'disattiva' : 'attiva' ?>">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit"
                                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs font-medium transition">
                                        <?= $c['is_active'] ? 'Disattiva' : 'Attiva' ?>
                                    </button>
                                </form>
                                <form method="POST" data-confirm="Eliminare questo catalogo?">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action" value="elimina">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit"
                                            class="bg-red-100 hover:bg-red-200 text-red-700 px-3 py-1 rounded text-xs font-medium transition">
                                        Elimina
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>