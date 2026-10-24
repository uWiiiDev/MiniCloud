<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$imprintText = trim((string)($appSettings['imprint_text'] ?? ''));

$pageTitle = 'Impressum';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto mt-4 sm:mt-8 px-1">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-6 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Impressum</h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Angaben gemäß § 5 DDG (ehemals § 5 TMG) für <?= e($appSettings['app_title']) ?>
                </p>
            </div>

            <div class="flex items-center gap-2">
                <?php if (isAdmin()): ?>
                    <a
                        href="admin.php#branding"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-semibold hover:bg-amber-100 transition-colors"
                    >
                        ✏️ Impressum bearbeiten
                    </a>
                <?php endif; ?>
                <a
                    href="index.php"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors"
                >
                    &larr; Zurück
                </a>
            </div>
        </div>

        <?php if ($imprintText !== ''): ?>
            <div class="prose dark:prose-invert max-w-none text-sm sm:text-base text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line">
                <?= e($imprintText) ?>
            </div>
        <?php else: ?>
            <div class="py-8 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Für diese Cloud-Instanz wurde vom Administrator noch kein Impressum hinterlegt.
                </p>
                <?php if (isAdmin()): ?>
                    <a
                        href="admin.php#branding"
                        class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold transition-colors"
                    >
                        Jetzt Impressum in der Administration hinterlegen
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
