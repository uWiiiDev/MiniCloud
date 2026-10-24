<?php
declare(strict_types=1);
?>
</main>

<!-- Footer -->
<?php
$footerSettings = isset($appSettings) && is_array($appSettings) ? $appSettings : getAppSettings();
$footerTitle    = $footerSettings['app_title'] ?? APP_NAME;
$versionInfo    = getAppVersionInfo();
?>
<footer class="bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 mt-auto transition-colors">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
        <p class="flex flex-wrap items-center justify-center sm:justify-start gap-1.5">
            <span>&copy; <?= date('Y') ?> <?= e((string)$footerTitle) ?> &ndash; Leichtgewichtiger, sicherer Cloud-Speicher</span>
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
                href="https://github.com/uwebenzelrath/MiniCloud"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors"
                title="GitHub Repository"
                aria-label="GitHub Repository"
            >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                </svg>
            </a>
            <span class="text-slate-300 dark:text-slate-700">&bull;</span>
            <p class="flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                <span>BCRYPT-geschützt &bull; Freigabelinks &bull; Gruppenordner</span>
            </p>
        </div>
    </div>
</footer>

<!-- PWA Installations-Dialog (für iPhone/iPad & manuelle Installation) -->
<div
    id="pwaInstallModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 backdrop-blur-xs p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="pwaInstallModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-md w-full p-5 sm:p-6 shadow-2xl">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-3">
                <img
                    src="app-icon.php?size=180"
                    alt="<?= e((string)$footerTitle) ?>"
                    class="w-11 h-11 rounded-xl border border-slate-200 dark:border-slate-700 object-contain bg-slate-900 p-1 shrink-0"
                >
                <div>
                    <h3 id="pwaInstallModalTitle" class="text-base font-bold text-slate-900 dark:text-white">
                        <?= e((string)$footerTitle) ?> als App installieren
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Vollbild-App mit eigenem Symbol auf Ihrem Startbildschirm
                    </p>
                </div>
            </div>
            <button
                type="button"
                id="btnClosePwaModal"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="space-y-3 text-xs text-slate-700 dark:text-slate-300">
            <!-- Apple iOS / iPadOS Anleitung -->
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700">
                <p class="font-bold text-slate-900 dark:text-white mb-1.5 flex items-center gap-1.5">
                    <span>🍎</span>
                    <span>iPhone &amp; iPad (Safari)</span>
                </p>
                <ol class="list-decimal list-inside space-y-1 text-slate-600 dark:text-slate-300">
                    <li>Tippen Sie in Safari auf das <strong>Teilen-Symbol</strong> <span class="inline-block px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-[11px] font-mono">⬆️</span>.</li>
                    <li>Wählen Sie im Menü <strong>„Zum Home-Bildschirm“</strong>.</li>
                    <li>Bestätigen Sie oben rechts mit <strong>„Hinzufügen“</strong>.</li>
                </ol>
            </div>

            <!-- Android Anleitung -->
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700">
                <p class="font-bold text-slate-900 dark:text-white mb-1.5 flex items-center gap-1.5">
                    <span>🤖</span>
                    <span>Android (Chrome / Edge / Samsung)</span>
                </p>
                <ol class="list-decimal list-inside space-y-1 text-slate-600 dark:text-slate-300">
                    <li>Tippen Sie oben rechts im Browser auf das <strong>Menü-Symbol (⋮)</strong>.</li>
                    <li>Wählen Sie <strong>„App installieren“</strong> oder <strong>„Zum Startbildschirm hinzufügen“</strong>.</li>
                </ol>
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button
                type="button"
                id="btnClosePwaModalBottom"
                class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold transition-colors cursor-pointer"
            >
                Verstanden
            </button>
        </div>
    </div>
</div>

<script>
(() => {
    const themeSelect = document.getElementById('themeSelect');
    const themeForm = document.getElementById('themeForm');
    if (!themeSelect || !themeForm) return;

    themeSelect.addEventListener('change', () => {
        const val = themeSelect.value;
        const html = document.documentElement;
        const darkThemes = ['dusk', 'mocha', 'petrol', 'plum', 'dark', 'ocean', 'forest'];
        html.classList.remove(
            'dark',
            'theme-light',
            'theme-sand',
            'theme-sage',
            'theme-nordic',
            'theme-lavender',
            'theme-dusk',
            'theme-mocha',
            'theme-petrol',
            'theme-plum',
            'theme-dark',
            'theme-ocean',
            'theme-forest'
        );
        html.classList.add('theme-' + val);
        if (darkThemes.includes(val)) {
            html.classList.add('dark');
        }
        html.setAttribute('data-theme', val);

        const fd = new FormData(themeForm);
        fetch('theme.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: fd
        }).catch(() => {});
    });
})();

(() => {
    // Service Worker registrieren
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        });
    }

    const btnInstall = document.getElementById('btnInstallPwa');
    const btnInstallSep = document.getElementById('btnInstallPwaSep');
    const modal = document.getElementById('pwaInstallModal');
    const btnCloseTop = document.getElementById('btnClosePwaModal');
    const btnCloseBottom = document.getElementById('btnClosePwaModalBottom');

    if (!btnInstall) return;

    const isStandalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true;

    let deferredPrompt = null;

    const showInstallButton = () => {
        if (isStandalone) return;
        btnInstall.classList.remove('hidden');
        if (btnInstallSep) btnInstallSep.classList.remove('hidden');
    };

    const hideInstallButton = () => {
        btnInstall.classList.add('hidden');
        if (btnInstallSep) btnInstallSep.classList.add('hidden');
    };

    // Falls noch nicht als Vollbild-App gestartet, Button anzeigen
    if (!isStandalone) {
        showInstallButton();
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        showInstallButton();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        hideInstallButton();
    });

    const openModal = () => {
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    btnInstall.addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            try {
                await deferredPrompt.userChoice;
            } catch (_) {}
            deferredPrompt = null;
        } else {
            openModal();
        }
    });

    if (btnCloseTop) btnCloseTop.addEventListener('click', closeModal);
    if (btnCloseBottom) btnCloseBottom.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }
})();

<?php if (isLoggedIn() && getCurrentSessionTimeout() > 0): ?>
(() => {
    const timeoutMs = <?= (int)getCurrentSessionTimeout() * 60 * 1000 ?>;
    let idleTimer = null;

    const triggerAutoLogout = () => {
        window.location.href = 'login.php?timeout=1';
    };

    const resetIdleTimer = () => {
        if (idleTimer !== null) {
            clearTimeout(idleTimer);
        }
        idleTimer = setTimeout(triggerAutoLogout, timeoutMs);
    };

    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach((evt) => {
        window.addEventListener(evt, resetIdleTimer, { passive: true });
    });

    resetIdleTimer();
})();
<?php endif; ?>
</script>
</body>
</html>
