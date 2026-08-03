<?php
/**
 * ==========================================================================
 * UTENTI.VIEW.PHP — Vista della pagina "Gestione Utenti"
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da admin/utenti.php dopo che il controller ha
 * preparato i dati. Variabili già disponibili: $user, $is_superadmin, $error,
 * $success, $utenti, $aziende. Non va mai aperta direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
   <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
    <style>
        /* Telefono: la tabella diventa schede impilate (etichette da data-label). */
        @media (max-width:767px){
            .utenti-table{ display:block; padding:.5rem; }
            .utenti-table thead{ display:none; }
            .utenti-table tbody{ display:block; }
            .utenti-table tr{
                display:block; background:var(--surface);
                border:1px solid var(--border); border-radius:.6rem;
                margin-bottom:.5rem; overflow:hidden;
            }
            .utenti-table td{
                display:flex; align-items:center; justify-content:space-between;
                gap:1rem; padding:.55rem .8rem; text-align:right;
            }
            .utenti-table td + td{ border-top:1px solid var(--border); }
            .utenti-table td::before{
                content:attr(data-label);
                font-weight:600; font-size:.7rem; letter-spacing:.03em;
                text-transform:uppercase; color:var(--muted);
                text-align:left; white-space:nowrap;
            }
        }
    </style>
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
             (la password) è già stato "escapato" nel controller. -->
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
                             "superadmin". È solo UX: il controllo vero è server-side. -->
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
        <!-- TABELLA UTENTI -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="utenti-table w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Nome</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Ruolo</th>
                        <?php if ($is_superadmin): ?>
                        <th class="px-4 py-3 text-left">Azienda</th>
                        <th class="px-4 py-3 text-left">Pwd cambiata</th>
                        <th class="px-4 py-3 text-left">Azioni</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($utenti as $u): ?>
                    <tr class="hover:bg-gray-50">
                        <td data-label="Nome" class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($u['name']) ?></td>
                        <td data-label="Email" class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['email']) ?></td>
                        <td data-label="Ruolo" class="px-4 py-3">
                            <?php
                            // match() (PHP 8) sceglie il colore del badge in base al ruolo.
                            $badge = match($u['role']) {
                                'superadmin' => 'bg-purple-100 text-purple-700',
                                'admin'      => 'bg-blue-100 text-blue-700',
                                default      => 'bg-gray-100 text-gray-700',
                            };
                            ?>
                            <span class="<?= $badge ?> px-2 py-0.5 rounded-full text-xs font-semibold">
                                <?= htmlspecialchars($u['role']) ?>
                            </span>
                        </td>
                        <?php if ($is_superadmin): ?>
                        <td data-label="Azienda" class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['nome_azienda'] ?? '—') ?></td>
                        <td data-label="Pwd cambiata" class="px-4 py-3">
                            <?php if ($u['must_change_password']): ?>
                                <span class="bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full text-xs font-semibold">No</span>
                            <?php else: ?>
                                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded-full text-xs font-semibold">Sì</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Azioni" class="px-4 py-3">
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
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>