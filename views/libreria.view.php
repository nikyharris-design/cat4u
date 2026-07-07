<?php
/**
 * ==========================================================================
 * LIBRERIA.VIEW.PHP — Vista della vetrina pubblica di un'azienda
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da public/libreria.php dopo che il controller ha
 * preparato i dati. Variabili già disponibili: $azienda, $generi,
 * $genere_attivo (null se nessun filtro), $cataloghi. Pagina PUBBLICA: nessun
 * $user, nessun header della dashboard. Non va mai aperta direttamente
 * (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $azienda ? htmlspecialchars($azienda['nome_azienda']) . ' — ' : '' ?>Cataloghi</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Barra di servizio per l'utente loggato: NON visibile ai visitatori
         pubblici. Compare solo se la sessione è autenticata, così l'admin che
         arriva qui da un catalogo non resta in un vicolo cieco. Stessa logica
         della pagina catalogo.view.php. -->
  

   <!-- Header pubblico: nome azienda a sinistra. Per l'utente loggato, a destra
         compare il link alla dashboard (invisibile ai visitatori pubblici). -->
    <header class="bg-indigo-600 text-white shadow">
        <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
            <span class="font-bold text-lg"><?= $azienda ? htmlspecialchars($azienda['nome_azienda']) : 'Libreria pubblica' ?></span>
            <?php if (!empty($_SESSION['autorizzato'])): ?>
            <a href="<?= BASE_URL ?>dashboard/index.php"
               style="color:#c7d2fe;font-size:.875rem;text-decoration:none;"
               onmouseover="this.style.color='#fff'"
               onmouseout="this.style.color='#c7d2fe'">
                ← Torna alla dashboard
            </a>
            <?php endif; ?>
        </div>
    </header>

   <main class="max-w-5xl mx-auto py-8 px-4">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Cataloghi</h1>
        <?php if ($azienda): ?>
        <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($azienda['nome_azienda']) ?></p>
        <?php endif; ?>

        <?php if ($is_superadmin): ?>
        <!-- SELETTORE AZIENDA (solo superadmin loggato): anteprima della libreria
             pubblica di qualsiasi azienda. Invisibile ai visitatori normali. -->
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Anteprima libreria — scegli azienda</label>
           <form method="GET" action="<?= BASE_URL ?>public/libreria.php" class="flex items-center gap-2">
                <select name="a"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">— Seleziona un'azienda —</option>
                    <?php foreach ($aziende_list as $az): ?>
                        <option value="<?= htmlspecialchars($az['slug']) ?>"
                            <?= ($azienda && (int)$azienda['id'] === (int)$az['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($az['nome_azienda']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    Vai
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- TAB DI FILTRO PER GENERE (solo se ci sono generi con cataloghi). -->
        <?php if (!empty($generi)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <!-- "Tutti": evidenziato quando nessun genere è attivo. -->
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>"
               class="<?= !$genere_attivo ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                Tutti
            </a>
            <?php foreach ($generi as $g): ?>
            <!-- Ogni tab è evidenziata se è il genere attualmente filtrato. -->
            <a href="<?= BASE_URL ?>public/libreria.php?a=<?= htmlspecialchars($azienda['slug']) ?>&g=<?= htmlspecialchars($g['slug']) ?>"
               class="<?= ($genere_attivo && $genere_attivo['id'] === $g['id']) ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?> px-4 py-1.5 rounded-full text-sm font-medium border border-gray-200 transition">
                <?= htmlspecialchars($g['nome_genere']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- GRIGLIA DEI CATALOGHI (o messaggio se vuota). -->
       <?php if (!$azienda): ?>
            <p class="text-gray-400 text-sm text-center py-12">Scegli un'azienda dal menu qui sopra per vederne la libreria.</p>
        <?php elseif (empty($cataloghi)): ?>
            <p class="text-gray-400 text-sm text-center py-12">Nessun catalogo disponibile.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($cataloghi as $c): ?>
            <!-- Ogni card è un link alla pagina del singolo catalogo. -->
            <a href="<?= BASE_URL ?>public/catalogo.php?a=<?= htmlspecialchars($azienda['slug']) ?>&c=<?= htmlspecialchars($c['slug']) ?>"
               class="bg-white rounded-xl shadow hover:shadow-md transition p-5 flex flex-col gap-2">
                <div class="flex items-start justify-between">
                    <h2 class="font-semibold text-gray-800"><?= htmlspecialchars($c['titolo']) ?></h2>
                    <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded-full font-medium">
                        <?= htmlspecialchars($c['nome_genere']) ?>
                    </span>
                </div>
                <?php if ($c['data_scadenza']): ?>
                <p class="text-xs text-gray-400">Valido fino al <?= date('d/m/Y', strtotime($c['data_scadenza'])) ?></p>
                <?php endif; ?>
                <!-- mt-auto spinge questa riga in fondo alla card, allineando le card. -->
                <p class="text-indigo-600 text-sm font-medium mt-auto">Apri catalogo →</p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>