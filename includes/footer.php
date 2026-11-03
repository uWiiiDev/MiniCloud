<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}
?>
</main>

<!-- Footer (Files-Only Edition) -->
<?php
$footerSettings = isset($appSettings) && is_array($appSettings) ? $appSettings : getAppSettings();
$footerTitle    = $footerSettings['app_title'] ?? APP_NAME;
$versionInfo    = getAppVersionInfo();
$footerMobileClass = !empty($hideFooterOnMobile) ? 'hidden lg:block' : '';
?>
<footer id="appFooter" class="<?= $footerMobileClass ?> bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 mt-auto transition-colors">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
        <p class="flex flex-wrap items-center justify-center sm:justify-start gap-1.5">
            <span>&copy; <?= date('Y') ?> <?= e((string)$footerTitle) ?> &ndash; Schlanke Datei-Cloud (Files-Only Edition)</span>
            <span
                class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800/80 text-[11px] font-mono text-slate-400 dark:text-slate-500 border border-slate-200/70 dark:border-slate-700/60"
                title="<?= e($versionInfo['tooltip']) ?>"
            ><?= e($versionInfo['version']) ?></span>
        </p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button
                type="button"
                id="btnInstallPwa"
                class="hidden inline-flex items-center gap-1 font-semibold text-brand-600 dark:text-brand-400 hover:underline transition-colors cursor-pointer"
                title="Als App auf Smartphone, Tablet oder Desktop installieren"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                <span>App installieren</span>
            </button>
            <span id="btnInstallPwaSep" class="hidden text-slate-300 dark:text-slate-700">&bull;</span>
            <?php if (isLoggedIn()): ?>
                <a href="help.php" class="font-semibold text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 hover:underline transition-colors">
                    Hilfe
                </a>
                <span class="text-slate-300 dark:text-slate-700">&bull;</span>
            <?php endif; ?>
            <a href="impressum.php" class="font-semibold text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 hover:underline transition-colors">
                Impressum
            </a>
            <span class="text-slate-300 dark:text-slate-700">&bull;</span>
            <a
                href="https://github.com/uWiiiDev/MiniCloud/tree/files-only"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors"
                title="GitHub Repository (files-only Branch)"
                aria-label="GitHub Repository"
            >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                </svg>
            </a>
            <span class="text-slate-300 dark:text-slate-700">&bull;</span>
            <p class="flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                <span>BCRYPT &amp; AES-256-GCM Tresor &bull; Freigaben &bull; WebDAV</span>
            </p>
        </div>
    </div>
</footer>

<script>
(() => {
    const themeSelect = document.getElementById('themeSelect');
    const themeForm = document.getElementById('themeForm');
    if (!themeSelect || !themeForm) return;

    themeSelect.addEventListener('change', () => {
        const val = themeSelect.value;
        const html = document.documentElement;
        const darkThemes = ['dusk', 'mocha', 'petrol', 'plum', 'dark', 'ocean', 'forest', 'obsidian'];
        html.classList.remove(
            'dark', 'theme-light', 'theme-sand', 'theme-sage', 'theme-nordic',
            'theme-lavender', 'theme-dusk', 'theme-mocha', 'theme-petrol',
            'theme-plum', 'theme-dark', 'theme-ocean', 'theme-forest', 'theme-obsidian'
        );
        html.classList.add('theme-' + val);
        if (darkThemes.includes(val)) {
            html.classList.add('dark');
        }
        html.setAttribute('data-theme', val);

        const fd = new FormData(themeForm);
        fetch('theme.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
        }).catch(() => {});
    });
})();
</script>
</body>
</html>
