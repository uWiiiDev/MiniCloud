<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$imprintText = trim((string)($appSettings['imprint_text'] ?? ''));

$rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'runready.cloud'));
$domain  = preg_replace('/:\d+$/', '', $rawHost) ?: 'runready.cloud';
if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
    $domain = 'runready.cloud';
}
$contactMail = trim((string)($appSettings['mail_from'] ?? ''));
if (filter_var($contactMail, FILTER_VALIDATE_EMAIL) === false) {
    $contactMail = 'noreply@' . $domain;
}

$defaultPrivateNotice = implode("\n", [
    'Hinweis gemäß § 5 DDG (Digitale-Dienste-Gesetz) & Art. 2 Abs. 2 Buchst. c DSGVO',
    '',
    'Bei dieser Webanwendung handelt es sich um ein rein privates, nicht-kommerzielles System, das ausschließlich zu persönlichen und familiären Zwecken innerhalb eines geschlossenen Benutzerkreises betrieben wird.',
    '',
    '1. Keine Impressumspflicht (§ 5 Abs. 1 DDG / § 18 Abs. 1 MStV)',
    'Es liegt kein geschäftsmäßiges, entgeltliches oder journalistisch-redaktionelles Angebot vor. Eine öffentliche Selbstregistrierung ist ausgeschlossen. Daher besteht für diese private Instanz keine gesetzliche Pflicht zur Anbieterkennzeichnung (Impressumspflicht).',
    '',
    '2. Datenschutz & Haushaltsausnahme (Art. 2 Abs. 2 Buchst. c DSGVO)',
    'Die Nutzung erfolgt ausschließlich im privaten und familiären Rahmen. Es werden keinerlei Analyse-, Werbe- oder Tracking-Dienste eingesetzt und keine Daten an Dritte oder externe Server (CDNs) übertragen. Es werden lediglich technisch notwendige Sitzungsdaten für angemeldete Benutzer verarbeitet.',
    '',
    '3. Kontakt bei technischen Fragen',
    'Bei technischen Rückfragen erreichen Sie den Administrator unter:',
    'E-Mail: ' . $contactMail,
]);

$displayImprint = $imprintText !== '' ? $imprintText : $defaultPrivateNotice;

$pageTitle = 'Impressum';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto mt-4 sm:mt-8 px-1">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-6 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Impressum &amp; Rechtlicher Hinweis</h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Rechtliche Hinweise gemäß § 5 DDG &amp; DSGVO für <?= e($appSettings['app_title']) ?>
                </p>
            </div>

            <div class="flex items-center gap-2">
                <?php if (isAdmin()): ?>
                    <a
                        href="admin.php#branding"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-semibold hover:bg-amber-100 transition-colors"
                    >
                        ✏️ Text anpassen
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

        <div class="prose dark:prose-invert max-w-none text-sm sm:text-base text-slate-700 dark:text-slate-200 leading-relaxed whitespace-pre-line"><?= e($displayImprint) ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
