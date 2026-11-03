<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}
?>
</main>

<!-- Footer -->
<?php
$footerSettings = isset($appSettings) && is_array($appSettings) ? $appSettings : getAppSettings();
$footerTitle    = $footerSettings['app_title'] ?? APP_NAME;
$versionInfo    = getAppVersionInfo();
$footerMobileClass = !empty($hideFooterOnMobile) ? 'hidden lg:block' : '';
?>
<footer id="appFooter" class="<?= $footerMobileClass ?> bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 mt-auto transition-colors">
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
                href="https://github.com/uWiiiDev/MiniCloud"
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
                <span>BCRYPT &amp; AES-256 &bull; Freigaben &bull; Kalender &amp; Chat</span>
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
        const darkThemes = ['dusk', 'mocha', 'petrol', 'plum', 'dark', 'ocean', 'forest', 'obsidian'];
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
            'theme-forest',
            'theme-obsidian'
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

<?php if (isLoggedIn() && (($footerSettings['enable_chat'] ?? '1') === '1')): ?>
(() => {
    const notifyMode = <?= (int)getCurrentChatNotifyMode() ?>;
    const flashDurationMs = <?= (int)(getCurrentFlashDuration() * 1000) ?>;
    const storageKey = 'mc_last_notified_msg_id_<?= (int)$_SESSION['user_id'] ?>';
    let sinceId = parseInt(sessionStorage.getItem(storageKey) || '0', 10) || 0;

    const showSystemNotification = (msg) => {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        const title = msg.is_broadcast
            ? `📢 Rundnachricht von ${msg.sender_username}`
            : `💬 Neue Nachricht von ${msg.sender_username}`;
        const targetUrl = `messages.php?channel=${encodeURIComponent(msg.channel)}`;
        const opts = {
            body: msg.message_text || '',
            icon: 'app-icon.php?size=180',
            badge: 'app-icon.php?size=180',
            tag: 'mc-chat-' + msg.id,
            data: { url: targetUrl }
        };
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready
                .then((reg) => reg.showNotification(title, opts))
                .catch(() => {
                    try {
                        const n = new Notification(title, opts);
                        n.onclick = () => { window.focus(); window.location.href = targetUrl; };
                    } catch (_) {}
                });
        } else {
            try {
                const n = new Notification(title, opts);
                n.onclick = () => { window.focus(); window.location.href = targetUrl; };
            } catch (_) {}
        }
    };

    const showInAppBanner = (msg) => {
        // Nur bei Modus 2 (System + In-App) oder Modus 3 (Nur In-App) einblenden
        if (notifyMode !== 2 && notifyMode !== 3) return;

        // Nicht einblenden, wenn man bereits genau diesen Chat im Vordergrund geöffnet hat
        if (window.location.pathname.endsWith('messages.php') && !document.hidden) {
            const params = new URLSearchParams(window.location.search);
            if (params.get('channel') === String(msg.channel)) return;
        }

        const banner = document.createElement('a');
        banner.href = `messages.php?channel=${encodeURIComponent(msg.channel)}`;
        banner.className = 'fixed bottom-4 right-4 z-50 max-w-sm w-[calc(100%-2rem)] bg-white dark:bg-slate-900 border border-brand-500/60 rounded-2xl p-3.5 shadow-2xl flex items-start gap-3 transition-all duration-300';
        const iconSpan = document.createElement('span');
        iconSpan.className = 'w-9 h-9 rounded-xl bg-brand-600 text-white flex items-center justify-center text-base shrink-0';
        iconSpan.textContent = msg.is_broadcast ? '📢' : '💬';

        const contentDiv = document.createElement('div');
        contentDiv.className = 'min-w-0 flex-1';
        const titleEl = document.createElement('p');
        titleEl.className = 'text-xs font-bold text-slate-900 dark:text-white truncate';
        titleEl.textContent = msg.is_broadcast
            ? `Rundnachricht von ${msg.sender_username}`
            : `Nachricht von ${msg.sender_username}`;
        const bodyEl = document.createElement('p');
        bodyEl.className = 'text-xs text-slate-600 dark:text-slate-300 truncate mt-0.5';
        bodyEl.textContent = msg.message_text || '';

        contentDiv.appendChild(titleEl);
        contentDiv.appendChild(bodyEl);
        banner.appendChild(iconSpan);
        banner.appendChild(contentDiv);
        document.body.appendChild(banner);

        if (flashDurationMs > 0) {
            setTimeout(() => {
                banner.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => banner.remove(), 300);
            }, flashDurationMs);
        }
    };

    const csrfTokenGlobal = <?= json_encode(getCsrfToken()) ?>;
    let activeCallBannerSigId = 0;

    const playIncomingRingChime = () => {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            [523.25, 659.25, 783.99].forEach((freq, idx) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, ctx.currentTime + idx * 0.14);
                gain.gain.setValueAtTime(0.08, ctx.currentTime + idx * 0.14);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + idx * 0.14 + 0.25);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + idx * 0.14);
                osc.stop(ctx.currentTime + idx * 0.14 + 0.26);
            });
        } catch (_) {}
    };

    const renderIncomingCallBanner = (call) => {
        let banner = document.getElementById('mcGlobalIncomingCallBanner');
        if (!call) {
            if (banner) banner.remove();
            activeCallBannerSigId = 0;
            return;
        }
        if (activeCallBannerSigId !== Number(call.sig_id)) {
            activeCallBannerSigId = Number(call.sig_id);
            playIncomingRingChime();
        } else {
            // Sanft nachklingeln solange der Anruf aktiv ist
            playIncomingRingChime();
            if (banner) return;
        }

        if (banner) banner.remove();
        banner = document.createElement('div');
        banner.id = 'mcGlobalIncomingCallBanner';
        banner.className = 'fixed top-16 right-4 z-50 max-w-sm w-[calc(100%-2rem)] bg-slate-900 text-white border-2 border-emerald-500 rounded-2xl p-4 shadow-2xl space-y-3';

        const topRow = document.createElement('div');
        topRow.className = 'flex items-center gap-3';
        const iconSpan = document.createElement('span');
        iconSpan.className = 'w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-lg animate-bounce shrink-0';
        iconSpan.textContent = call.mode === 'ptt' ? '📻' : '📞';

        const textWrap = document.createElement('div');
        textWrap.className = 'min-w-0 flex-1';
        const titleEl = document.createElement('p');
        titleEl.className = 'text-xs font-bold text-emerald-400 uppercase tracking-wider';
        titleEl.textContent = call.mode === 'ptt' ? 'Eingehender Funk-Ruf' : 'Eingehender Sprachanruf';
        const callerEl = document.createElement('p');
        callerEl.className = 'text-sm font-bold text-white truncate';
        callerEl.textContent = `${call.caller_username} ruft an…`;
        textWrap.appendChild(titleEl);
        textWrap.appendChild(callerEl);
        topRow.appendChild(iconSpan);
        topRow.appendChild(textWrap);

        const btnRow = document.createElement('div');
        btnRow.className = 'grid grid-cols-2 gap-2';

        const acceptBtn = document.createElement('button');
        acceptBtn.type = 'button';
        acceptBtn.className = 'px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-colors cursor-pointer';
        acceptBtn.textContent = '🟢 Annehmen';
        acceptBtn.onclick = () => {
            banner.remove();
            const callTitle = `1:1 Sprachanruf mit ${call.caller_username}`;
            if (typeof window.joinVoiceCallRoom === 'function') {
                window.joinVoiceCallRoom(call.room_key, callTitle, call.mode || 'webrtc', false);
            } else {
                window.location.href = `messages.php?channel=${encodeURIComponent(call.caller_id)}&autocall=1&room=${encodeURIComponent(call.room_key)}&mode=${encodeURIComponent(call.mode || 'webrtc')}&title=${encodeURIComponent(callTitle)}`;
            }
        };

        const declineBtn = document.createElement('button');
        declineBtn.type = 'button';
        declineBtn.className = 'px-3 py-2 rounded-xl bg-red-600/90 hover:bg-red-600 text-white text-xs font-bold transition-colors cursor-pointer';
        declineBtn.textContent = '🔴 Ablehnen';
        declineBtn.onclick = async () => {
            banner.remove();
            const fd = new FormData();
            fd.append('csrf_token', csrfTokenGlobal);
            fd.append('room_key', call.room_key);
            try {
                await fetch('messages.php?ajax=voice_leave', { method: 'POST', body: fd });
            } catch (_) {}
        };

        btnRow.appendChild(acceptBtn);
        btnRow.appendChild(declineBtn);
        banner.appendChild(topRow);
        banner.appendChild(btnRow);
        document.body.appendChild(banner);
    };

    const checkUnreadMessages = async () => {
        try {
            const res = await fetch(`messages.php?ajax=check_unread&since_id=${sinceId}`);
            const data = await res.json();
            if (!data || !data.success) return;

            renderIncomingCallBanner(data.incoming_call || null);

            const badge = document.getElementById('headerUnreadChatBadge');
            if (badge) {
                if (data.unread_total > 0) {
                    badge.textContent = String(data.unread_total);
                    badge.classList.remove('hidden');
                    badge.classList.add('inline-flex');
                } else {
                    badge.classList.add('hidden');
                    badge.classList.remove('inline-flex');
                }
            }

            if (sinceId === 0) {
                sinceId = Number(data.max_id || 0);
                sessionStorage.setItem(storageKey, String(sinceId));
                return;
            }

            if (data.latest_unread && Number(data.latest_unread.id) > sinceId) {
                const msg = data.latest_unread;
                sinceId = Number(msg.id);
                sessionStorage.setItem(storageKey, String(sinceId));

                if (notifyMode === 1 || notifyMode === 2) {
                    showSystemNotification(msg);
                }
                if (notifyMode === 2 || notifyMode === 3) {
                    showInAppBanner(msg);
                }
            } else if (Number(data.max_id || 0) > sinceId) {
                sinceId = Number(data.max_id);
                sessionStorage.setItem(storageKey, String(sinceId));
            }
        } catch (_) {}
    };

    checkUnreadMessages();
    setInterval(checkUnreadMessages, 6000);
})();
<?php endif; ?>

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

// Auf Mobilgeräten den Footer automatisch ausblenden, sobald ein Eingabefeld / die Tastatur aktiv ist
(() => {
    const footer = document.getElementById('appFooter');
    if (!footer) return;
    const isTextField = (el) => {
        if (!el || !el.tagName) return false;
        const tag = el.tagName.toUpperCase();
        if (tag === 'TEXTAREA' || el.isContentEditable) return true;
        if (tag === 'INPUT') {
            const t = (el.type || 'text').toLowerCase();
            return !['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'range', 'color', 'hidden'].includes(t);
        }
        return false;
    };
    document.addEventListener('focusin', (e) => {
        if (window.innerWidth < 1024 && isTextField(e.target)) {
            footer.classList.add('max-lg:hidden');
        }
    });
    document.addEventListener('focusout', () => {
        setTimeout(() => {
            if (window.innerWidth < 1024 && !isTextField(document.activeElement)) {
                footer.classList.remove('max-lg:hidden');
            }
        }, 150);
    });
})();

// Universelle Live-Benutzer- & Gruppensuche für alle Teilen-/Zuweisungs-Dropdowns, Chat & Admin-Benutzertabelle
(() => {
    // 1. Admin-Benutzertabelle Live-Filter (admin.php)
    const adminSearchInput = document.getElementById('adminUserSearchInput');
    if (adminSearchInput) {
        const rows = Array.from(document.querySelectorAll('tr[data-admin-user-row="1"]'));
        const badge = document.getElementById('adminUserCountBadge');
        const noMatchRow = document.getElementById('adminUserNoMatchRow');
        const total = rows.length;

        const filterAdminUsers = () => {
            const q = (adminSearchInput.value || '').trim().toLowerCase();
            let shown = 0;
            rows.forEach((tr) => {
                const hay = (tr.getAttribute('data-user-search') || '').toLowerCase();
                const match = q === '' || hay.includes(q);
                tr.classList.toggle('hidden', !match);
                if (match) shown++;
            });
            if (badge) {
                badge.textContent = q === '' ? String(total) : `${shown} / ${total}`;
            }
            if (noMatchRow) {
                noMatchRow.classList.toggle('hidden', shown > 0 || total === 0);
            }
        };

        adminSearchInput.addEventListener('input', filterAdminUsers);
        adminSearchInput.addEventListener('search', filterAdminUsers);
    }

    // 2. Alle Benutzer- & Gruppen-Auswahlfelder (<select>) in der gesamten Cloud mit Live-Suche ausstatten
    const selector = [
        'select[name="share_target"]',
        'select[name="target_user_id"]',
        'select[name="shared_user_id"]',
        'select[name="assigned_user_id"]',
        'select[name="member_id"]',
        '#shareTargetUserId',
        '#newChatUserSelect',
        'select[data-user-searchable="1"]'
    ].join(', ');

    const nativeValueDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');

    const escapeHtml = (str) => {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    };

    const highlightMatch = (text, query) => {
        if (!query) return escapeHtml(text);
        const idx = text.toLowerCase().indexOf(query.toLowerCase());
        if (idx === -1) return escapeHtml(text);
        const before = text.slice(0, idx);
        const match = text.slice(idx, idx + query.length);
        const after = text.slice(idx + query.length);
        return `${escapeHtml(before)}<mark class="bg-amber-200/90 dark:bg-amber-500/40 text-slate-900 dark:text-white rounded px-0.5 font-bold">${escapeHtml(match)}</mark>${escapeHtml(after)}`;
    };

    const enhanceUserSelect = (selectEl) => {
        if (!selectEl || selectEl.dataset.mcUserEnhanced === '1') return;

        // Original-Optionen (inkl. Optgroups) auslesen
        const allItems = [];
        Array.from(selectEl.children).forEach((child) => {
            if (child.tagName === 'OPTGROUP') {
                const grpLabel = child.getAttribute('label') || '';
                Array.from(child.children).forEach((opt) => {
                    if (opt.tagName === 'OPTION') {
                        allItems.push({
                            value: opt.value,
                            label: (opt.textContent || '').trim(),
                            group: grpLabel,
                            isPlaceholder: opt.value === '' || opt.value === '0'
                        });
                    }
                });
            } else if (child.tagName === 'OPTION') {
                const val = child.value;
                allItems.push({
                    value: val,
                    label: (child.textContent || '').trim(),
                    group: '',
                    isPlaceholder: val === '' || val === '0'
                });
            }
        });

        const realItems = allItems.filter((it) => !it.isPlaceholder);
        if (realItems.length === 0) return;

        selectEl.dataset.mcUserEnhanced = '1';

        // Falls das Elternteil ein flex-row Formular mit sm:items-center ist, auf sm:items-end anpassen
        const parentForm = selectEl.closest('form');
        if (parentForm && parentForm.classList.contains('sm:flex-row') && parentForm.classList.contains('sm:items-center')) {
            parentForm.classList.remove('sm:items-center');
            parentForm.classList.add('sm:items-end');
        }

        // Wrapper erstellen
        const wrapper = document.createElement('div');
        const isFlex1 = selectEl.classList.contains('flex-1');
        wrapper.className = `mc-user-picker-wrap relative ${isFlex1 ? 'flex-1 min-w-0' : 'w-full'} space-y-1`;
        if (isFlex1) {
            selectEl.classList.remove('flex-1');
            selectEl.classList.add('w-full');
        }

        selectEl.parentNode.insertBefore(wrapper, selectEl);

        // Suchleiste oberhalb des Selects
        const searchWrap = document.createElement('div');
        searchWrap.className = 'relative flex items-center';

        const searchIcon = document.createElement('span');
        searchIcon.className = 'absolute left-2.5 text-[11px] text-slate-400 pointer-events-none select-none';
        searchIcon.textContent = '🔍';

        const hasGroups = allItems.some((it) => it.group !== '' || it.value.startsWith('group:'));
        const searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.autocomplete = 'off';
        searchInput.placeholder = hasGroups
            ? `Benutzer oder Gruppe suchen (${realItems.length})…`
            : `Benutzer suchen (${realItems.length})…`;
        searchInput.className = 'w-full pl-7 pr-16 py-1 rounded-lg bg-slate-50/90 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-[11px] text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white dark:focus:bg-slate-800 transition-colors';

        const countBadge = document.createElement('span');
        countBadge.className = 'absolute right-2 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-200/70 dark:bg-slate-700/80 text-slate-600 dark:text-slate-300 pointer-events-none';
        countBadge.textContent = String(realItems.length);

        searchWrap.appendChild(searchIcon);
        searchWrap.appendChild(searchInput);
        searchWrap.appendChild(countBadge);

        // Schwebende Schnell-Auswahlliste (Autocomplete-Popup)
        const dropdown = document.createElement('div');
        dropdown.className = 'hidden absolute left-0 right-0 top-7 z-50 max-h-52 overflow-y-auto rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-xl divide-y divide-slate-100 dark:divide-slate-700/60 text-xs';

        wrapper.appendChild(searchWrap);
        wrapper.appendChild(dropdown);
        wrapper.appendChild(selectEl);

        let activeSuggestIndex = -1;
        let currentVisibleSuggestions = [];

        const rebuildSelectOptions = (query, preserveValue) => {
            const q = (query || '').trim().toLowerCase();
            const prevVal = preserveValue !== undefined
                ? preserveValue
                : (nativeValueDesc ? nativeValueDesc.get.call(selectEl) : selectEl.value);

            selectEl.innerHTML = '';
            const groupsMap = new Map();
            let matchCount = 0;
            let firstMatchedValue = null;
            let prevValStillExists = false;

            allItems.forEach((item) => {
                const hay = `${item.label} ${item.group}`.toLowerCase();
                const isMatch = item.isPlaceholder || q === '' || hay.includes(q);
                if (!isMatch) return;

                if (!item.isPlaceholder) {
                    matchCount++;
                    if (firstMatchedValue === null) {
                        firstMatchedValue = item.value;
                    }
                }
                if (item.value === prevVal) {
                    prevValStillExists = true;
                }

                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.label;

                if (item.group !== '') {
                    let grpEl = groupsMap.get(item.group);
                    if (!grpEl) {
                        grpEl = document.createElement('optgroup');
                        grpEl.label = item.group;
                        groupsMap.set(item.group, grpEl);
                        selectEl.appendChild(grpEl);
                    }
                    grpEl.appendChild(opt);
                } else {
                    selectEl.appendChild(opt);
                }
            });

            if (q !== '' && firstMatchedValue !== null) {
                if (nativeValueDesc) {
                    nativeValueDesc.set.call(selectEl, firstMatchedValue);
                } else {
                    selectEl.value = firstMatchedValue;
                }
            } else if (prevValStillExists) {
                if (nativeValueDesc) {
                    nativeValueDesc.set.call(selectEl, prevVal);
                } else {
                    selectEl.value = prevVal;
                }
            }

            countBadge.textContent = q === '' ? String(realItems.length) : `${matchCount}/${realItems.length}`;
            return matchCount;
        };

        const renderSuggestions = (query) => {
            const q = (query || '').trim().toLowerCase();
            dropdown.innerHTML = '';
            currentVisibleSuggestions = [];
            activeSuggestIndex = -1;

            const currentVal = nativeValueDesc ? nativeValueDesc.get.call(selectEl) : selectEl.value;

            allItems.forEach((item) => {
                const hay = `${item.label} ${item.group}`.toLowerCase();
                if (!item.isPlaceholder && q !== '' && !hay.includes(q)) return;
                if (item.isPlaceholder && q !== '') return;

                const btn = document.createElement('button');
                btn.type = 'button';
                const isSelected = item.value === currentVal && !item.isPlaceholder;
                btn.className = `w-full text-left px-3 py-2 flex items-center justify-between gap-2 hover:bg-brand-50 dark:hover:bg-brand-950/50 transition-colors cursor-pointer ${
                    isSelected ? 'bg-brand-50/70 dark:bg-brand-950/30 font-bold text-brand-700 dark:text-brand-300' : 'text-slate-800 dark:text-slate-100'
                }`;

                const leftSpan = document.createElement('span');
                leftSpan.className = 'truncate';
                leftSpan.innerHTML = highlightMatch(item.label, q);
                btn.appendChild(leftSpan);

                if (item.group !== '') {
                    const grpBadge = document.createElement('span');
                    grpBadge.className = 'shrink-0 text-[10px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300';
                    grpBadge.textContent = item.group.replace(/^[^\wÄÖÜäöüß]+/, '').trim();
                    btn.appendChild(grpBadge);
                }

                btn.addEventListener('mousedown', (ev) => {
                    ev.preventDefault(); // verhindert Blur vor Klick
                    selectOptionItem(item.value);
                });

                dropdown.appendChild(btn);
                currentVisibleSuggestions.push({ item, btn });
            });

            if (currentVisibleSuggestions.length === 0) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'px-3 py-2.5 text-slate-400 italic text-center';
                emptyDiv.textContent = 'Kein passender Benutzer oder Gruppe gefunden.';
                dropdown.appendChild(emptyDiv);
            }
        };

        const selectOptionItem = (val) => {
            searchInput.value = '';
            rebuildSelectOptions('', val);
            dropdown.classList.add('hidden');
            if (selectEl.id === 'newChatUserSelect') {
                filterChatConversations('');
            }
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const filterChatConversations = (query) => {
            const q = (query || '').trim().toLowerCase();
            document.querySelectorAll('a[data-chat-conv-item="1"]').forEach((link) => {
                const hay = (link.getAttribute('data-chat-conv-search') || '').toLowerCase();
                link.classList.toggle('hidden', q !== '' && !hay.includes(q));
            });
        };

        searchInput.addEventListener('focus', () => {
            renderSuggestions(searchInput.value);
            dropdown.classList.remove('hidden');
        });

        searchInput.addEventListener('input', () => {
            const q = searchInput.value;
            rebuildSelectOptions(q);
            renderSuggestions(q);
            dropdown.classList.remove('hidden');
            if (selectEl.id === 'newChatUserSelect') {
                filterChatConversations(q);
            }
        });

        searchInput.addEventListener('search', () => {
            const q = searchInput.value;
            rebuildSelectOptions(q);
            renderSuggestions(q);
            if (selectEl.id === 'newChatUserSelect') {
                filterChatConversations(q);
            }
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dropdown.classList.contains('hidden')) {
                    renderSuggestions(searchInput.value);
                    dropdown.classList.remove('hidden');
                }
                if (currentVisibleSuggestions.length > 0) {
                    activeSuggestIndex = (activeSuggestIndex + 1) % currentVisibleSuggestions.length;
                    updateActiveSuggestionHighlight();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (currentVisibleSuggestions.length > 0) {
                    activeSuggestIndex = (activeSuggestIndex - 1 + currentVisibleSuggestions.length) % currentVisibleSuggestions.length;
                    updateActiveSuggestionHighlight();
                }
            } else if (e.key === 'Enter') {
                if (!dropdown.classList.contains('hidden') && currentVisibleSuggestions.length > 0) {
                    e.preventDefault();
                    const chosen = activeSuggestIndex >= 0
                        ? currentVisibleSuggestions[activeSuggestIndex]
                        : currentVisibleSuggestions.find((s) => !s.item.isPlaceholder) || currentVisibleSuggestions[0];
                    if (chosen) {
                        selectOptionItem(chosen.item.value);
                    }
                }
            } else if (e.key === 'Escape') {
                dropdown.classList.add('hidden');
            }
        });

        const updateActiveSuggestionHighlight = () => {
            currentVisibleSuggestions.forEach((entry, idx) => {
                entry.btn.classList.toggle('ring-2', idx === activeSuggestIndex);
                entry.btn.classList.toggle('ring-inset', idx === activeSuggestIndex);
                entry.btn.classList.toggle('ring-brand-500', idx === activeSuggestIndex);
                if (idx === activeSuggestIndex) {
                    entry.btn.scrollIntoView({ block: 'nearest' });
                }
            });
        };

        searchInput.addEventListener('blur', () => {
            setTimeout(() => {
                dropdown.classList.add('hidden');
            }, 150);
        });

        // Wenn programmseitig selectEl.value = '...' gesetzt wird (z. B. beim Öffnen eines Bearbeiten-Modals),
        // zuerst alle Optionen wiederherstellen, damit jeder gespeicherte Wert zuverlässig selektiert wird.
        if (nativeValueDesc && nativeValueDesc.get && nativeValueDesc.set) {
            Object.defineProperty(selectEl, 'value', {
                configurable: true,
                enumerable: true,
                get() {
                    return nativeValueDesc.get.call(this);
                },
                set(newVal) {
                    if (searchInput.value !== '') {
                        searchInput.value = '';
                    }
                    rebuildSelectOptions('', String(newVal ?? ''));
                    nativeValueDesc.set.call(this, newVal);
                }
            });
        }

        if (parentForm) {
            parentForm.addEventListener('reset', () => {
                searchInput.value = '';
                rebuildSelectOptions('', '');
            });
        }
    };

    document.querySelectorAll(selector).forEach(enhanceUserSelect);
})();
</script>

<?php
$footerFolderPaths = [];
if (isLoggedIn() && function_exists('getMergedFoldersList')) {
    try {
        $footerDb = isset($pdo) && $pdo instanceof PDO ? $pdo : getDB();
        $footerUid = (int)currentUserId();
        if ($footerUid > 0) {
            foreach (getMergedFoldersList($footerDb, $footerUid, null) as $ffRow) {
                $ffp = trim((string)($ffRow['folder_path'] ?? ''));
                if ($ffp !== '') {
                    $footerFolderPaths[] = $ffp;
                }
            }
        }
    } catch (Throwable $e) {
        // Ignorieren falls DB nicht bereit
    }
}
?>
<script>
// 3. Aufklappbarer Ordnerbaum (Collapsible Folder Tree) für alle Zielordner-Auswahlmenüs & Eingabefelder
(function () {
    const defaultFolderPaths = <?= json_encode(array_values(array_unique($footerFolderPaths)), JSON_UNESCAPED_UNICODE) ?>;

    const buildHierarchy = (paths) => {
        const root = { children: new Map() };
        (paths || []).forEach((raw) => {
            const clean = String(raw || '').trim().replace(/^\/+|\/+$/g, '');
            if (!clean) return;
            const parts = clean.split('/').filter(Boolean);
            let curr = root;
            let acc = '';
            parts.forEach((part) => {
                acc = acc ? (acc + '/' + part) : part;
                if (!curr.children.has(part)) {
                    curr.children.set(part, { name: part, path: acc, children: new Map() });
                }
                curr = curr.children.get(part);
            });
        });
        return root;
    };

    let activePopoverCleanup = null;

    const openFloatingFolderTreePopover = ({ anchorEl, folderPaths, getCurrentValue, onSelectValue, rootLabel }) => {
        if (activePopoverCleanup) {
            activePopoverCleanup();
            activePopoverCleanup = null;
        }

        const expandedSet = new Set();
        // Aktuell ausgewählten Pfad (falls in Unterordner) automatisch bis zum Elternordner aufklappen
        const initVal = String(getCurrentValue() || '').trim();
        if (initVal.includes('/')) {
            const parts = initVal.split('/').filter(Boolean);
            let acc = '';
            for (let i = 0; i < parts.length - 1; i++) {
                acc = acc ? (acc + '/' + parts[i]) : parts[i];
                expandedSet.add(acc);
            }
        }

        const treeRoot = buildHierarchy(folderPaths);

        const popover = document.createElement('div');
        popover.className = 'fixed z-[9999] rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl p-2.5 flex flex-col gap-2 text-xs select-none';
        popover.style.minWidth = '260px';
        popover.style.maxWidth = '92vw';

        // Kopfzeile mit Schnellsuche & Alle auf-/zuklappen
        const topBar = document.createElement('div');
        topBar.className = 'flex items-center gap-1.5';

        const searchWrap = document.createElement('div');
        searchWrap.className = 'relative flex-1';
        const searchIcon = document.createElement('span');
        searchIcon.className = 'absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] text-slate-400 pointer-events-none';
        searchIcon.textContent = '🔍';
        const searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.autocomplete = 'off';
        searchInput.placeholder = 'Ordner im Baum suchen…';
        searchInput.className = 'w-full pl-7 pr-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500';
        searchWrap.appendChild(searchIcon);
        searchWrap.appendChild(searchInput);
        topBar.appendChild(searchWrap);

        const collectExpandable = (m, out = []) => {
            m.forEach((n) => {
                if (n.children.size > 0) {
                    out.push(n.path);
                    collectExpandable(n.children, out);
                }
            });
            return out;
        };
        const allExpandable = collectExpandable(treeRoot.children);

        const toggleAllBtn = document.createElement('button');
        toggleAllBtn.type = 'button';
        toggleAllBtn.className = `px-2 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-[11px] font-semibold text-slate-600 dark:text-slate-300 shrink-0 cursor-pointer ${allExpandable.length === 0 ? 'hidden' : ''}`;
        const updateToggleBtnLabel = () => {
            const allOpen = allExpandable.length > 0 && allExpandable.every((k) => expandedSet.has(k));
            toggleAllBtn.textContent = allOpen ? 'Zuklappen' : 'Aufklappen';
        };
        updateToggleBtnLabel();

        toggleAllBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const allOpen = allExpandable.length > 0 && allExpandable.every((k) => expandedSet.has(k));
            if (allOpen) {
                expandedSet.clear();
            } else {
                allExpandable.forEach((k) => expandedSet.add(k));
            }
            updateToggleBtnLabel();
            renderTree();
        });
        topBar.appendChild(toggleAllBtn);
        popover.appendChild(topBar);

        const viewport = document.createElement('div');
        viewport.className = 'max-h-60 overflow-y-auto rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40 p-1.5 space-y-0.5';
        popover.appendChild(viewport);

        const closePopover = () => {
            if (popover.parentNode) {
                popover.parentNode.removeChild(popover);
            }
            document.removeEventListener('mousedown', onDocMouseDown, true);
            window.removeEventListener('resize', positionPopover);
            window.removeEventListener('scroll', positionPopover, true);
            activePopoverCleanup = null;
        };

        const onDocMouseDown = (ev) => {
            if (!popover.contains(ev.target) && !anchorEl.contains(ev.target)) {
                closePopover();
            }
        };

        const positionPopover = () => {
            const rect = anchorEl.getBoundingClientRect();
            const width = Math.max(rect.width, 280);
            popover.style.width = `${Math.min(width, window.innerWidth - 16)}px`;
            let left = rect.left;
            if (left + width > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - width - 8);
            }
            popover.style.left = `${left}px`;

            const popHeight = popover.offsetHeight || 280;
            const spaceBelow = window.innerHeight - rect.bottom - 10;
            if (spaceBelow < popHeight && rect.top > spaceBelow) {
                popover.style.top = `${Math.max(8, rect.top - popHeight - 6)}px`;
            } else {
                popover.style.top = `${rect.bottom + 6}px`;
            }
        };

        const renderTree = () => {
            viewport.innerHTML = '';
            const q = (searchInput.value || '').trim().toLowerCase();
            const curVal = String(getCurrentValue() || '').trim();

            const makeRow = ({ pathVal, label, icon, depth, hasChildren, isExpanded, subCount, onToggle }) => {
                const isSelected = curVal === pathVal;
                const row = document.createElement('div');
                row.className = `flex items-center gap-1 rounded-lg px-1.5 py-1 text-xs transition-colors cursor-pointer ${
                    isSelected
                        ? 'bg-brand-600 text-white font-bold shadow-2xs'
                        : 'text-slate-700 dark:text-slate-200 hover:bg-slate-200/70 dark:hover:bg-slate-700/60'
                }`;
                row.style.paddingLeft = `${Math.max(4, depth * 15 + 4)}px`;

                if (hasChildren) {
                    const expBtn = document.createElement('button');
                    expBtn.type = 'button';
                    expBtn.title = isExpanded ? 'Unterordner zuklappen' : 'Unterordner öffnen';
                    expBtn.className = `w-5 h-5 rounded flex items-center justify-center shrink-0 cursor-pointer ${
                        isSelected
                            ? 'text-white hover:bg-white/20'
                            : 'text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-300/60 dark:hover:bg-slate-600/60'
                    }`;
                    expBtn.innerHTML = `<svg class="w-3.5 h-3.5 transition-transform duration-150 ${isExpanded ? 'rotate-90' : ''}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>`;
                    expBtn.addEventListener('click', (ev) => {
                        ev.stopPropagation();
                        onToggle();
                    });
                    row.appendChild(expBtn);
                } else {
                    const sp = document.createElement('span');
                    sp.className = 'w-5 h-5 shrink-0 inline-block';
                    row.appendChild(sp);
                }

                const content = document.createElement('div');
                content.className = 'flex-1 min-w-0 flex items-center justify-between gap-2 py-0.5 pr-1';

                const left = document.createElement('div');
                left.className = 'flex items-center gap-1.5 min-w-0';
                const ic = document.createElement('span');
                ic.className = 'shrink-0 text-sm leading-none';
                ic.textContent = icon;
                const tx = document.createElement('span');
                tx.className = 'truncate';
                tx.textContent = label;
                left.appendChild(ic);
                left.appendChild(tx);
                content.appendChild(left);

                const right = document.createElement('div');
                right.className = 'flex items-center gap-1 shrink-0';
                if (hasChildren) {
                    const b = document.createElement('span');
                    b.className = `text-[10px] px-1.5 py-0.5 rounded-md font-semibold ${
                        isSelected ? 'bg-white/20 text-white' : 'bg-slate-200/80 dark:bg-slate-700/80 text-slate-500 dark:text-slate-300'
                    }`;
                    b.textContent = String(subCount);
                    right.appendChild(b);
                }
                if (isSelected) {
                    const chk = document.createElement('span');
                    chk.className = 'text-xs font-bold pl-0.5';
                    chk.textContent = '✓';
                    right.appendChild(chk);
                }
                content.appendChild(right);
                row.appendChild(content);

                row.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    onSelectValue(pathVal);
                    if (hasChildren && !expandedSet.has(pathVal)) {
                        expandedSet.add(pathVal);
                        updateToggleBtnLabel();
                        renderTree();
                    } else {
                        closePopover();
                    }
                });

                return row;
            };

            // Hauptverzeichnis (Root)
            viewport.appendChild(makeRow({
                pathVal: '',
                label: rootLabel || 'Hauptverzeichnis',
                icon: '🏠',
                depth: 0,
                hasChildren: false,
                isExpanded: false,
                subCount: 0,
                onToggle: () => {}
            }));

            const matchesQuery = (n) => {
                if (!q) return true;
                if (n.name.toLowerCase().includes(q) || n.path.toLowerCase().includes(q)) return true;
                for (const c of n.children.values()) {
                    if (matchesQuery(c)) return true;
                }
                return false;
            };

            const walkNodes = (map, depth) => {
                map.forEach((n) => {
                    if (!matchesQuery(n)) return;
                    const hasC = n.children.size > 0;
                    const isExp = q !== '' ? true : expandedSet.has(n.path);
                    viewport.appendChild(makeRow({
                        pathVal: n.path,
                        label: n.name,
                        icon: isExp && hasC ? '📂' : '📁',
                        depth,
                        hasChildren: hasC,
                        isExpanded: isExp,
                        subCount: n.children.size,
                        onToggle: () => {
                            if (expandedSet.has(n.path)) {
                                expandedSet.delete(n.path);
                            } else {
                                expandedSet.add(n.path);
                            }
                            updateToggleBtnLabel();
                            renderTree();
                        }
                    }));
                    if (hasC && isExp) {
                        walkNodes(n.children, depth + 1);
                    }
                });
            };

            walkNodes(treeRoot.children, 0);
        };

        searchInput.addEventListener('input', renderTree);
        searchInput.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                closePopover();
            }
        });

        document.body.appendChild(popover);
        renderTree();
        positionPopover();
        setTimeout(() => searchInput.focus(), 20);

        document.addEventListener('mousedown', onDocMouseDown, true);
        window.addEventListener('resize', positionPopover);
        window.addEventListener('scroll', positionPopover, true);
        activePopoverCleanup = closePopover;
    };

    // A) Ordner-<select>-Menüs (z. B. in split.php, translate.php) mit aufklappbarem Ordnerbaum ausstatten
    const folderSelectSelector = 'select[name="target_folder"]:not(#transferTargetSelect), #saveTransFolder, select[data-folder-tree="1"]';
    document.querySelectorAll(folderSelectSelector).forEach((selectEl) => {
        if (selectEl.dataset.mcFolderTreeEnhanced === '1') return;
        selectEl.dataset.mcFolderTreeEnhanced = '1';

        const pathsSet = new Set(defaultFolderPaths);
        let rootLabel = 'Hauptverzeichnis';
        Array.from(selectEl.options).forEach((opt) => {
            const v = String(opt.value || '').trim();
            if (v === '') {
                const rawLbl = (opt.textContent || '').replace(/^[📁🏠\s]+/u, '').trim();
                if (rawLbl) rootLabel = rawLbl;
            } else {
                pathsSet.add(v);
            }
        });

        const triggerBtn = document.createElement('button');
        triggerBtn.type = 'button';
        triggerBtn.className = (selectEl.className || 'w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm')
            + ' flex items-center justify-between gap-2 text-left cursor-pointer';

        const labelSpan = document.createElement('span');
        labelSpan.className = 'truncate flex items-center gap-1.5';
        const arrowSpan = document.createElement('span');
        arrowSpan.className = 'text-slate-400 text-xs shrink-0';
        arrowSpan.textContent = '▾';
        triggerBtn.appendChild(labelSpan);
        triggerBtn.appendChild(arrowSpan);

        const updateTriggerText = () => {
            const val = String(selectEl.value || '').trim();
            labelSpan.textContent = val === '' ? `🏠 ${rootLabel}` : `📁 ${val}`;
        };
        updateTriggerText();

        selectEl.classList.add('hidden');
        selectEl.parentNode.insertBefore(triggerBtn, selectEl);

        triggerBtn.addEventListener('click', () => {
            openFloatingFolderTreePopover({
                anchorEl: triggerBtn,
                folderPaths: Array.from(pathsSet),
                getCurrentValue: () => selectEl.value,
                onSelectValue: (newVal) => {
                    // Falls Option noch nicht im Select existiert, ergänzen
                    if (!Array.from(selectEl.options).some((o) => o.value === newVal)) {
                        const o = document.createElement('option');
                        o.value = newVal;
                        o.textContent = newVal;
                        selectEl.appendChild(o);
                    }
                    selectEl.value = newVal;
                    updateTriggerText();
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                },
                rootLabel
            });
        });
    });

    // B) Ordner-Texteingabefelder (z. B. in office.php, video_studio.php, pdf_tools.php) mit Ordnerbaum-Button ausstatten
    const folderInputSelector = 'input[name="folder_path"][list], #vsExportFolder, input[name="target_folder"][type="text"], input[data-folder-tree="1"]';
    document.querySelectorAll(folderInputSelector).forEach((inputEl) => {
        if (inputEl.dataset.mcFolderTreeEnhanced === '1') return;
        inputEl.dataset.mcFolderTreeEnhanced = '1';

        const pathsSet = new Set(defaultFolderPaths);
        const listId = inputEl.getAttribute('list');
        if (listId) {
            const dl = document.getElementById(listId);
            if (dl) {
                Array.from(dl.querySelectorAll('option')).forEach((opt) => {
                    const v = String(opt.value || '').trim();
                    if (v) pathsSet.add(v);
                });
            }
            inputEl.removeAttribute('list');
        }

        const wrap = document.createElement('div');
        wrap.className = 'relative flex items-center w-full';
        inputEl.parentNode.insertBefore(wrap, inputEl);
        wrap.appendChild(inputEl);
        inputEl.classList.add('pr-9');

        const treeBtn = document.createElement('button');
        treeBtn.type = 'button';
        treeBtn.title = 'Zielordner im aufklappbaren Ordnerbaum wählen';
        treeBtn.className = 'absolute right-1.5 top-1/2 -translate-y-1/2 px-1.5 py-1 rounded-lg text-xs text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer';
        treeBtn.textContent = '📁▾';
        wrap.appendChild(treeBtn);

        const openForInput = () => {
            openFloatingFolderTreePopover({
                anchorEl: wrap,
                folderPaths: Array.from(pathsSet),
                getCurrentValue: () => inputEl.value,
                onSelectValue: (newVal) => {
                    inputEl.value = newVal;
                    inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                    inputEl.dispatchEvent(new Event('change', { bubbles: true }));
                },
                rootLabel: 'Hauptverzeichnis'
            });
        };

        treeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            openForInput();
        });
    });
})();
</script>

<?php
$currentScriptForAssistant = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$activeAssistantFileId = 0;
if (in_array($currentScriptForAssistant, ['office.php', 'ebooks.php', 'pdf_tools.php'], true)) {
    $activeAssistantFileId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0);
}
if (
    isLoggedIn()
    && (($footerSettings['enable_assistant'] ?? '1') === '1')
    && $currentScriptForAssistant !== 'assistant.php'
):
?>
<!-- Schwebendes Schnell-Chat-Widget: 🤖 Lokaler Cloud-Assistent -->
<style>
@keyframes mcTypingBounce {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-4px); opacity: 1; }
}
.mc-typing-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    background-color: currentColor;
    animation: mcTypingBounce 1.2s infinite ease-in-out;
}
.mc-typing-dot:nth-child(2) { animation-delay: 0.18s; }
.mc-typing-dot:nth-child(3) { animation-delay: 0.36s; }
</style>
<div id="mcQuickAssistantContainer" class="fixed bottom-4 right-4 z-40 flex flex-col items-end">
    <!-- Aufklappbares Chat-Fenster -->
    <div id="mcQuickAssistantPanel"
         class="hidden mb-3 w-[calc(100vw-2rem)] sm:w-96 max-h-[540px] flex-col rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <!-- Kopfleiste -->
        <div class="px-4 py-3 bg-gradient-to-r from-brand-600 to-indigo-600 text-white flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="text-lg">🤖</span>
                <div class="min-w-0">
                    <p class="text-xs font-bold truncate">Cloud-Assistent</p>
                    <p class="text-[10px] text-white/80 truncate">100 % Lokal · Live-Suche &amp; Hilfe</p>
                </div>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <a href="assistant.php<?= $activeAssistantFileId > 0 ? '?file_id=' . (int)$activeAssistantFileId : '' ?>"
                   class="px-2 py-1 rounded-lg bg-white/15 hover:bg-white/25 text-[11px] font-semibold text-white transition-colors"
                   title="Große Assistenten-Ansicht öffnen">
                    Vollbild ↗
                </a>
                <button type="button" onclick="toggleMcQuickAssistant(false)"
                        class="p-1 rounded-lg hover:bg-white/20 text-white text-xs font-bold cursor-pointer"
                        aria-label="Schließen">
                    ✕
                </button>
            </div>
        </div>

        <?php if ($activeAssistantFileId > 0): ?>
        <!-- Aktives Dokument erkannt (z. B. in Office Light) -->
        <div class="px-3 py-2 bg-emerald-50 dark:bg-emerald-950/50 border-b border-emerald-200/80 dark:border-emerald-800/60 flex flex-wrap items-center justify-between gap-1.5">
            <span class="text-[11px] font-bold text-emerald-800 dark:text-emerald-300 truncate">📄 Geöffnetes Dokument aktiv</span>
            <div class="flex items-center gap-1">
                <button type="button" onclick="askMcQuickChip('Fasse den Inhalt dieser Datei übersichtlich zusammen.')"
                        class="px-2 py-0.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-semibold cursor-pointer">
                    📋 Zusammenfassen
                </button>
                <button type="button" onclick="askMcQuickChip('Welche wichtigen Punkte, Zahlen oder Termine stehen in dieser Datei?')"
                        class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-800 hover:bg-emerald-100 dark:hover:bg-slate-700 border border-emerald-300 dark:border-emerald-700 text-[10px] font-semibold text-emerald-800 dark:text-emerald-300 cursor-pointer">
                    🔑 Kernpunkte
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Nachrichtenbereich -->
        <div id="mcQuickAssistantMessages" class="flex-1 overflow-y-auto p-3.5 space-y-3 min-h-[260px] max-h-[360px] bg-slate-50/60 dark:bg-slate-950/40"></div>

        <!-- Eingabezeile -->
        <form onsubmit="submitMcQuickAssistant(event)" class="p-2.5 border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center gap-2">
            <input type="text" id="mcQuickAssistantInput"
                   placeholder="Frage stellen oder „Suche …“ / „Notiz: …“"
                   autocomplete="off"
                   class="flex-1 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
            <button type="submit" id="mcQuickAssistantSendBtn"
                    class="px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer shrink-0">
                &rarr;
            </button>
        </form>
    </div>

    <!-- Schwebender Button -->
    <button type="button" id="mcQuickAssistantFab" onclick="toggleMcQuickAssistant()"
            class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-full bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white text-xs font-bold shadow-lg hover:shadow-xl transition-all cursor-pointer border border-white/20"
            title="Lokalen KI- &amp; Wissens-Assistenten öffnen">
        <span class="text-base">🤖</span>
        <span class="hidden sm:inline">Assistent</span>
    </button>
</div>

<script>
(() => {
    const csrf = <?= json_encode(getCsrfToken()) ?>;
    const uiLang = <?= json_encode(function_exists('getCurrentLang') ? getCurrentLang() : 'de') ?>;
    const storeKey = 'mc_assistant_chat_v2_' + <?= json_encode((int)$_SESSION['user_id']) ?> + '_' + uiLang;
    const userName = <?= json_encode((string)($_SESSION['username'] ?? 'Benutzer')) ?>;
    const activePageFileId = <?= json_encode((int)$activeAssistantFileId) ?>;
    let quickPageUnloading = false;
    window.addEventListener('beforeunload', () => { quickPageUnloading = true; });
    window.addEventListener('pagehide', () => { quickPageUnloading = true; });

    const quickGreets = {
        de: {
            reply: `Hallo **${userName}**! 👋 Wie kann ich dir auf dieser Seite helfen?`,
            followups: ['Wie viel Speicherplatz habe ich noch frei?', 'Welche Termine stehen bei mir an?', 'Wie funktioniert der E-Book-Reader (.epub)?']
        },
        en: {
            reply: `Hello **${userName}**! 👋 How can I help you on this page?`,
            followups: ['How much free storage space do I have left?', 'What appointments do I have coming up?', 'How does the E-Book Reader (.epub) work?']
        },
        fr: {
            reply: `Bonjour **${userName}** ! 👋 Comment puis-je vous aider sur cette page ?`,
            followups: ['Combien d’espace de stockage me reste-t-il ?', 'Quels sont mes prochains rendez-vous ?', 'Comment fonctionne le lecteur E-Book (.epub) ?']
        },
        es: {
            reply: `¡Hola **${userName}**! 👋 ¿Cómo puedo ayudarte en esta página?`,
            followups: ['¿Cuánto espacio de almacenamiento libre me queda?', '¿Qué citas tengo próximamente?', '¿Cómo funciona el lector de E-Books (.epub)?']
        },
        it: {
            reply: `Ciao **${userName}**! 👋 Come posso aiutarti in questa pagina?`,
            followups: ['Quanto spazio di archiviazione libero mi rimane?', 'Quali sono i miei prossimi appuntamenti?', 'Come funziona il lettore E-Book (.epub)?']
        },
        nl: {
            reply: `Hallo **${userName}**! 👋 Hoe kan ik je op deze pagina helpen?`,
            followups: ['Hoeveel vrije opslagruimte heb ik nog?', 'Welke afspraken staan er op mijn agenda?', 'Hoe werkt de E-Book-lezer (.epub)?']
        },
        pl: {
            reply: `Cześć **${userName}**! 👋 Jak mogę Ci pomóc na tej stronie?`,
            followups: ['Ile mam jeszcze wolnego miejsca?', 'Jakie mam nadchodzące terminy?', 'Jak działa czytnik E-Booków (.epub)?']
        },
        pt: {
            reply: `Olá **${userName}**! 👋 Como posso ajudar nesta página?`,
            followups: ['Quanto espaço de armazenamento livre ainda tenho?', 'Quais são os meus próximos compromissos?', 'Como funciona o leitor de E-Books (.epub)?']
        },
        cs: {
            reply: `Ahoj **${userName}**! 👋 Jak ti mohu na této stránce pomoci?`,
            followups: ['Kolik volného místa mi ještě zbývá?', 'Jaké události mě čekají?', 'Jak funguje čtečka E-knih (.epub)?']
        },
        tr: {
            reply: `Merhaba **${userName}**! 👋 Bu sayfada size nasıl yardımcı olabilirim?`,
            followups: ['Ne kadar boş depolama alanım kaldı?', 'Yaklaşan hangi randevularım var?', 'E-Kitap Okuyucu (.epub) nasıl çalışır?']
        },
        uk: {
            reply: `Привіт, **${userName}**! 👋 Як я можу допомогти на цій сторінці?`,
            followups: ['Скільки вільного місця у мене залишилося?', 'Які події у мене заплановані?', 'Як працює рідер електронних книг (.epub)?']
        }
    };

    const fmtMd = (t) => {
        let s = String(t || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');
        s = s.replace(/`([^`]+)`/g, '<code class="px-1 py-0.5 rounded bg-slate-200 dark:bg-slate-700 font-mono text-[10px]">$1</code>');
        return s.replace(/\n/g, '<br>');
    };

    const getHist = () => {
        try {
            const r = sessionStorage.getItem(storeKey);
            if (r) {
                const p = JSON.parse(r);
                if (Array.isArray(p) && p.length) {
                    const cleaned = p.filter((m) => !(m.role === 'assistant' && typeof m.reply === 'string' && m.reply.startsWith('⚠️ Verbindungsfehler')));
                    if (cleaned.length) {
                        if (cleaned.length !== p.length) {
                            sessionStorage.setItem(storeKey, JSON.stringify(cleaned));
                        }
                        return cleaned;
                    }
                }
            }
        } catch (e) {}
        const pack = quickGreets[uiLang] || quickGreets.de;
        return [{
            role: 'assistant',
            reply: pack.reply,
            actions: [],
            followups: pack.followups
        }];
    };

    const saveHist = (arr) => {
        try { sessionStorage.setItem(storeKey, JSON.stringify(arr.slice(-30))); } catch (e) {}
    };

    const renderQuick = () => {
        const box = document.getElementById('mcQuickAssistantMessages');
        if (!box) return;
        const hist = getHist();
        box.innerHTML = '';
        hist.forEach((m) => {
            const d = document.createElement('div');
            if (m.role === 'user') {
                d.className = 'flex justify-end';
                d.innerHTML = `<div class="max-w-[85%] px-3 py-2 rounded-2xl rounded-br-sm bg-brand-600 text-white text-xs shadow-2xs">${fmtMd(m.text)}</div>`;
            } else {
                d.className = 'flex items-start gap-2';
                let actHtml = '';
                if (Array.isArray(m.actions) && m.actions.length) {
                    actHtml = '<div class="flex flex-wrap gap-1.5 pt-2">' + m.actions.map((a) =>
                        `<a href="${a.url}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-[11px] font-bold">${fmtMd(a.label)} &rarr;</a>`
                    ).join('') + '</div>';
                }
                let folHtml = '';
                if (Array.isArray(m.followups) && m.followups.length) {
                    folHtml = '<div class="flex flex-wrap gap-1 pt-2 mt-2 border-t border-slate-200/70 dark:border-slate-700/70">' + m.followups.map((f) =>
                        `<button type="button" onclick="askMcQuickChip(${JSON.stringify(f).replace(/"/g, '&quot;')})" class="text-left px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-900 hover:bg-brand-50 dark:hover:bg-brand-950/50 border border-slate-200 dark:border-slate-700 text-[10px] font-medium text-slate-700 dark:text-slate-300 cursor-pointer">💬 ${fmtMd(f)}</button>`
                    ).join('') + '</div>';
                }
                d.innerHTML = `
                    <span class="w-6 h-6 rounded-lg bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-xs shrink-0 mt-0.5">🤖</span>
                    <div class="max-w-[88%] px-3 py-2.5 rounded-2xl rounded-tl-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-800 dark:text-slate-100 leading-relaxed">
                        <div>${fmtMd(m.reply)}</div>
                        ${actHtml}
                        ${folHtml}
                    </div>
                `;
            }
            box.appendChild(d);
        });
        box.scrollTop = box.scrollHeight;
    };

    window.toggleMcQuickAssistant = (forceState) => {
        const panel = document.getElementById('mcQuickAssistantPanel');
        if (!panel) return;
        const shouldOpen = typeof forceState === 'boolean' ? forceState : panel.classList.contains('hidden');
        panel.classList.toggle('hidden', !shouldOpen);
        panel.classList.toggle('flex', shouldOpen);
        if (shouldOpen) {
            renderQuick();
            setTimeout(() => document.getElementById('mcQuickAssistantInput')?.focus(), 60);
        }
    };

    window.askMcQuickChip = async (q) => {
        await sendQuickMsg(q);
    };

    window.summarizeCurrentOfficeDocWithAi = async () => {
        window.toggleMcQuickAssistant(true);
        await sendQuickMsg('Fasse den Inhalt dieser Datei übersichtlich zusammen.');
    };

    const extractLiveOfficeContext = () => {
        let liveText = '';
        let liveName = '';
        try {
            const titleEl = document.getElementById('officeDocTitleInput');
            if (titleEl && titleEl.value) {
                liveName = titleEl.value.trim();
            }
            const wordEl = document.getElementById('wordEditorSurface');
            const plainEl = document.getElementById('plainTextEditor');
            if (wordEl) {
                liveText = (wordEl.innerText || wordEl.textContent || '').trim();
            } else if (plainEl && typeof plainEl.value === 'string') {
                liveText = plainEl.value.trim();
            }
        } catch (e) {}
        return { liveText: liveText.slice(0, 6000), liveName };
    };

    window.submitMcQuickAssistant = async (ev) => {
        ev.preventDefault();
        const inp = document.getElementById('mcQuickAssistantInput');
        if (!inp) return;
        const txt = inp.value.trim();
        if (!txt) return;
        inp.value = '';
        await sendQuickMsg(txt);
    };

    const sendQuickMsg = async (txt) => {
        const hist = getHist();
        hist.push({ role: 'user', text: txt });
        saveHist(hist);
        renderQuick();

        const box = document.getElementById('mcQuickAssistantMessages');
        let typingEl = null;
        if (box) {
            typingEl = document.createElement('div');
            typingEl.className = 'flex items-start gap-2';
            typingEl.innerHTML = `
                <span class="w-6 h-6 rounded-lg bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-xs shrink-0 mt-0.5 animate-pulse">🤖</span>
                <div class="px-3.5 py-2.5 rounded-2xl rounded-tl-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2 shadow-2xs">
                    <span class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400">
                        <span class="mc-typing-dot"></span>
                        <span class="mc-typing-dot"></span>
                        <span class="mc-typing-dot"></span>
                    </span>
                    <span class="font-medium">Assistent denkt nach…</span>
                </div>
            `;
            box.appendChild(typingEl);
            box.scrollTop = box.scrollHeight;
        }

        const btn = document.getElementById('mcQuickAssistantSendBtn');
        if (btn) btn.disabled = true;

        try {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('csrf_token', csrf);
            fd.append('message', txt);
            if (activePageFileId > 0) {
                fd.append('file_id', String(activePageFileId));
            }
            const liveCtx = extractLiveOfficeContext();
            if (liveCtx.liveText !== '') {
                fd.append('doc_live_text', liveCtx.liveText);
                if (liveCtx.liveName !== '') {
                    fd.append('doc_live_name', liveCtx.liveName);
                }
            }
            const r = await fetch('assistant.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d && d.ok) {
                hist.push({
                    role: 'assistant',
                    reply: d.reply,
                    actions: d.actions || [],
                    followups: d.followups || []
                });
            } else {
                hist.push({ role: 'assistant', reply: '⚠️ ' + (d?.error || 'Fehler'), actions: [], followups: [] });
            }
        } catch (e) {
            if (quickPageUnloading || (e && e.name === 'AbortError')) {
                return;
            }
            hist.push({ role: 'assistant', reply: '⚠️ Verbindungsfehler.', actions: [], followups: [] });
        } finally {
            if (typingEl && typingEl.parentNode) {
                typingEl.parentNode.removeChild(typingEl);
            }
            if (btn) btn.disabled = false;
            if (!quickPageUnloading) {
                saveHist(hist);
                renderQuick();
            }
        }
    };
})();
</script>

<!-- ====================================================================== -->
<!-- GLOBALER PERSISTENTER WEBRADIO-MINI-PLAYER (MODULÜBERGREIFEND)         -->
<!-- ====================================================================== -->
<div id="mcPersistentRadioMiniBar"
     class="hidden fixed bottom-4 left-4 z-50 max-w-[calc(100vw-5.5rem)] sm:max-w-md rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white px-3.5 py-2.5 border border-indigo-500/40 shadow-2xl backdrop-blur-md">
    <div class="flex items-center gap-2.5">
        <!-- Play / Pause -->
        <button type="button" id="mcMiniRadioPlayBtn" onclick="window.McGlobalRadio && window.McGlobalRadio.toggle()"
                class="w-9 h-9 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-sm flex items-center justify-center shadow-md transition-transform active:scale-95 cursor-pointer shrink-0"
                title="Webradio abspielen / pausieren">
            ⏸️
        </button>

        <!-- Sender & aktueller Song -->
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <span id="mcMiniRadioDot" class="w-2 h-2 rounded-full bg-emerald-400 animate-ping shrink-0"></span>
                <span id="mcMiniRadioStation" class="text-xs font-extrabold truncate">Live-Webradio</span>
                <span id="mcMiniRadioGenre" class="hidden sm:inline-block px-1.5 py-0.2 rounded text-[9px] font-bold bg-white/10 text-indigo-200 truncate max-w-[90px]">Live</span>
            </div>
            <div id="mcMiniRadioSong" class="text-[11px] text-indigo-200/90 truncate mt-0.5">
                🎶 Live-Stream aktiv
            </div>
        </div>

        <!-- Lautstärke (Desktop) & Mute -->
        <div class="flex items-center gap-1 bg-white/10 px-2 py-1 rounded-xl shrink-0">
            <button type="button" id="mcMiniRadioMuteBtn" onclick="window.McGlobalRadio && window.McGlobalRadio.toggleMute()"
                    class="text-xs cursor-pointer" title="Stummschalten">🔊</button>
            <input type="range" id="mcMiniRadioVol" min="0" max="100" value="85"
                   oninput="window.McGlobalRadio && window.McGlobalRadio.setVolume(this.value)"
                   aria-label="Radio-Lautstärke"
                   class="hidden sm:block w-14 accent-emerald-400 cursor-pointer">
        </div>

        <!-- Zum Radio-Modul öffnen -->
        <a href="radio.php" id="mcMiniRadioOpenBtn"
           class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 text-white flex items-center justify-center text-xs font-bold transition-colors shrink-0"
           title="Webradio-Modul öffnen">
            📻
        </a>

        <!-- Radio komplett stoppen & schließen -->
        <button type="button" onclick="window.McGlobalRadio && window.McGlobalRadio.stopAndClose()"
                class="w-7 h-7 rounded-xl bg-rose-500/20 hover:bg-rose-500/40 text-rose-200 flex items-center justify-center text-xs font-bold transition-colors cursor-pointer shrink-0"
                title="Webradio ausschalten &amp; schließen">
            ✕
        </button>
    </div>
</div>
<audio id="mcGlobalPersistentAudio" preload="none" class="hidden"></audio>

<script>
(() => {
    const STATE_KEY = 'mc_global_radio_state_v1';

    // Falls diese Seite bereits im nahtlosen Radio-Viewport-Iframe läuft,
    // nutze direkt die Audio-Instanz des Elternfensters (0 ms Unterbrechung).
    try {
        if (window.parent && window.parent !== window && window.parent.McGlobalRadio) {
            window.McGlobalRadio = window.parent.McGlobalRadio;
            window.parent.McGlobalRadio.notifyChildPageLoaded(window);
            return;
        }
    } catch (e) {}

    let icyTimer = null;
    let appFrame = null;
    let reconnectTimer = null;
    let watchdogTimer  = null;
    let reconnectAttemptCount = 0;
    let lastAudioCurrentTime  = -1;
    let frozenTicksCount      = 0;
    let boundAudioEl          = null;

    const getState = () => {
        try {
            const raw = sessionStorage.getItem(STATE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    };

    const saveState = (patch) => {
        try {
            const prev = getState() || {};
            const next = Object.assign({}, prev, patch, { updatedAt: Date.now() });
            sessionStorage.setItem(STATE_KEY, JSON.stringify(next));
            return next;
        } catch (e) {
            return null;
        }
    };

    const getAudioEl = () => {
        return document.getElementById('globalRadioAudio') || document.getElementById('mcGlobalPersistentAudio');
    };

    const isCurrentViewRadioPage = () => {
        try {
            if (appFrame && !appFrame.classList.contains('hidden') && appFrame.contentWindow) {
                const p = appFrame.contentWindow.location.pathname || '';
                return p.endsWith('/radio.php') || p === 'radio.php';
            }
        } catch (e) {}
        const p = window.location.pathname || '';
        return p.endsWith('/radio.php') || p === 'radio.php';
    };

    const updateMiniBarUi = () => {
        const bar = document.getElementById('mcPersistentRadioMiniBar');
        if (!bar) return;

        const st = getState();
        const audio = getAudioEl();
        const isPlaying = Boolean(audio && !audio.paused && st && st.streamUrl);
        const shouldShowBar = Boolean(st && st.streamUrl && !st.closed && (isPlaying || st.playing) && !isCurrentViewRadioPage());

        bar.classList.toggle('hidden', !shouldShowBar);

        const playBtn = document.getElementById('mcMiniRadioPlayBtn');
        const dot     = document.getElementById('mcMiniRadioDot');
        const stEl    = document.getElementById('mcMiniRadioStation');
        const gnEl    = document.getElementById('mcMiniRadioGenre');
        const sgEl    = document.getElementById('mcMiniRadioSong');
        const volEl   = document.getElementById('mcMiniRadioVol');
        const muteEl  = document.getElementById('mcMiniRadioMuteBtn');

        if (playBtn) playBtn.textContent = isPlaying ? '⏸️' : '▶️';
        if (dot) {
            dot.className = isPlaying
                ? 'w-2 h-2 rounded-full bg-emerald-400 animate-ping shrink-0'
                : 'w-2 h-2 rounded-full bg-amber-400 shrink-0';
        }
        if (st && stEl) stEl.textContent = st.name || 'Live-Webradio';
        if (st && gnEl) gnEl.textContent = st.genre || 'Live';
        if (st && sgEl) sgEl.textContent = '🎶 ' + (st.songTitle || ('Live • ' + (st.name || 'Radio')));
        if (st && volEl && typeof st.volume === 'number') volEl.value = String(st.volume);
        if (muteEl) {
            const muted = Boolean((audio && audio.muted) || (st && (st.muted || st.volume === 0)));
            muteEl.textContent = muted ? '🔇' : '🔊';
        }
    };

    const pollIcyTitle = async () => {
        const st = getState();
        const audio = getAudioEl();
        if (!st || !st.streamUrl || !audio || audio.paused) return;
        try {
            const r = await fetch('radio.php?action=icy_meta&url=' + encodeURIComponent(st.streamUrl));
            const d = await r.json();
            if (d && d.ok && d.title) {
                saveState({ songTitle: d.title });
                updateMiniBarUi();
            }
        } catch (e) {}
    };

    const ensureIcyPolling = () => {
        if (icyTimer) clearInterval(icyTimer);
        icyTimer = setInterval(pollIcyTitle, 25000);
    };

    const clearReconnectSchedule = () => {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
    };

    const buildFreshStreamUrl = (rawUrl, attempt) => {
        if (!rawUrl) return '';
        // Beim 1., 3., 5. Versuch saubere Original-URL (für strikte Icecast-Server),
        // bei jedem geraden Versuch mit Cache-Buster, um festgefahrene Browser-/Proxy-Sockets aufzubrechen.
        if (attempt % 2 === 1) {
            return rawUrl;
        }
        try {
            const u = new URL(rawUrl, window.location.href);
            u.searchParams.set('_mc_recon', String(Date.now()));
            return u.toString();
        } catch (e) {
            const sep = rawUrl.includes('?') ? '&' : '?';
            return rawUrl + sep + '_mc_recon=' + Date.now();
        }
    };

    const attemptStreamReconnect = (reason) => {
        clearReconnectSchedule();
        const st = getState();
        const a  = getAudioEl();
        if (!st || !st.playing || st.closed || !st.streamUrl || !a) {
            return;
        }
        if (navigator.onLine === false) {
            saveState({
                reconnecting: true,
                songTitle: '📡 Netzwerk getrennt – warte auf Wiederverbindung…'
            });
            updateMiniBarUi();
            scheduleStreamReconnect(2500);
            return;
        }

        reconnectAttemptCount++;
        const nextUrl = buildFreshStreamUrl(st.streamUrl, reconnectAttemptCount);
        const vol = typeof st.volume === 'number' ? st.volume : 85;

        saveState({
            reconnecting: true,
            songTitle: '🔄 Verbindung unterbrochen – verbinde automatisch neu…'
        });
        updateMiniBarUi();

        try {
            a.pause();
            a.removeAttribute('src');
            a.load();
        } catch (e) {}

        a.src = nextUrl;
        a.volume = Math.max(0, Math.min(1, vol / 100));
        a.muted  = Boolean(st.muted);

        a.play().then(() => {
            reconnectAttemptCount = 0;
            frozenTicksCount      = 0;
            lastAudioCurrentTime  = a.currentTime;
            saveState({
                playing: true,
                closed: false,
                reconnecting: false,
                songTitle: 'Live-Übertragung • ' + (st.name || 'Radio')
            });
            updateMiniBarUi();
            pollIcyTitle();
            ensureIcyPolling();
        }).catch(() => {
            // Wichtig: playing bleibt true (Benutzer-Wunsch), wir versuchen es automatisch weiter!
            scheduleStreamReconnect(3000);
        });
    };

    const scheduleStreamReconnect = (delayMs = 2000) => {
        const st = getState();
        if (!st || !st.playing || st.closed || !st.streamUrl) return;
        clearReconnectSchedule();
        reconnectTimer = setTimeout(() => {
            attemptStreamReconnect('scheduled');
        }, delayMs);
    };

    const bindAudioRecoveryEvents = (a) => {
        if (!a || boundAudioEl === a) return;
        boundAudioEl = a;

        a.addEventListener('playing', () => {
            clearReconnectSchedule();
            reconnectAttemptCount = 0;
            frozenTicksCount      = 0;
            lastAudioCurrentTime  = a.currentTime;
            const st = getState();
            if (st && st.reconnecting) {
                saveState({
                    reconnecting: false,
                    songTitle: 'Live-Übertragung • ' + (st.name || 'Radio')
                });
                pollIcyTitle();
            }
            updateMiniBarUi();
        });

        // Wenn der Stream durch kurzen Netzwerkausfall abbricht, feuert der Browser error, ended oder stalled
        ['error', 'ended'].forEach((evName) => {
            a.addEventListener(evName, () => {
                const st = getState();
                if (st && st.playing && !st.closed && st.streamUrl) {
                    scheduleStreamReconnect(1500);
                }
            });
        });

        a.addEventListener('stalled', () => {
            const st = getState();
            if (st && st.playing && !st.closed && st.streamUrl) {
                scheduleStreamReconnect(3500);
            }
        });
    };

    const ensureStreamWatchdog = () => {
        if (watchdogTimer) return;
        watchdogTimer = setInterval(() => {
            const st = getState();
            const a  = getAudioEl();
            if (a) bindAudioRecoveryEvents(a);
            if (!st || !st.playing || st.closed || !st.streamUrl || !a) {
                frozenTicksCount = 0;
                return;
            }

            const curT = Number(a.currentTime || 0);
            if (a.paused || a.ended || a.error) {
                frozenTicksCount++;
            } else if (Math.abs(curT - lastAudioCurrentTime) < 0.01) {
                frozenTicksCount++;
            } else {
                frozenTicksCount = 0;
                lastAudioCurrentTime = curT;
                if (st.reconnecting) {
                    saveState({ reconnecting: false });
                    updateMiniBarUi();
                }
            }

            // Wenn der Stream seit >= 6 Sekunden (2 Watchdog-Ticks à 3s) stillsteht, sofort neu verbinden!
            if (frozenTicksCount >= 2 && !reconnectTimer) {
                frozenTicksCount = 0;
                attemptStreamReconnect('watchdog');
            }
        }, 3000);
    };

    // Sofortiger Auto-Reconnect, sobald das Betriebssystem / der Browser wieder Netzwerk meldet
    window.addEventListener('online', () => {
        const st = getState();
        if (st && st.playing && !st.closed && st.streamUrl) {
            clearReconnectSchedule();
            setTimeout(() => attemptStreamReconnect('online'), 250);
        }
    });

    window.addEventListener('offline', () => {
        const st = getState();
        if (st && st.playing && !st.closed && st.streamUrl) {
            saveState({
                reconnecting: true,
                songTitle: '📡 Netzwerk getrennt – warte auf Wiederverbindung…'
            });
            updateMiniBarUi();
        }
    });

    const openInSeamlessViewport = (targetUrl) => {
        const parsed = new URL(targetUrl, window.location.href);
        const hostPath = window.location.pathname;
        const targetPath = parsed.pathname;

        // Wenn das Ziel exakt die Basis-Host-Seite selbst ist (z.B. Rückkehr zu radio.php),
        // blende das Viewport-Iframe einfach wieder aus.
        if (appFrame && targetPath === hostPath && parsed.search === window.location.search) {
            appFrame.classList.add('hidden');
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
            window.history.pushState({ mcRadioShell: true }, '', parsed.href);
            updateMiniBarUi();
            return;
        }

        if (!appFrame) {
            appFrame = document.createElement('iframe');
            appFrame.id = 'mcRadioAppFrame';
            appFrame.className = 'fixed inset-0 w-full h-full border-0 z-40 bg-slate-100 dark:bg-slate-950';
            appFrame.setAttribute('allow', 'autoplay; clipboard-read; clipboard-write; fullscreen');
            document.body.appendChild(appFrame);
        }

        appFrame.classList.remove('hidden');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        appFrame.src = parsed.href;
        updateMiniBarUi();
    };

    const shouldInterceptLinkClick = (ev, anchor) => {
        if (!anchor || !anchor.href) return false;
        if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return false;
        if (anchor.hasAttribute('download')) return false;
        const targetAttr = (anchor.getAttribute('target') || '').toLowerCase();
        if (targetAttr && targetAttr !== '_self') return false;
        const rawHref = (anchor.getAttribute('href') || '').trim();
        if (!rawHref || rawHref.startsWith('#') || rawHref.startsWith('javascript:') || rawHref.startsWith('mailto:') || rawHref.startsWith('tel:')) {
            return false;
        }
        try {
            const u = new URL(anchor.href, window.location.href);
            if (u.origin !== window.location.origin) return false;
            if (u.pathname.endsWith('/logout.php') || u.pathname.endsWith('/login.php') || u.pathname.endsWith('/download.php')) {
                return false;
            }
            if (u.pathname === window.location.pathname && u.search === window.location.search && u.hash !== '') {
                return false;
            }
            return true;
        } catch (e) {
            return false;
        }
    };

    const api = {
        getAudio: () => getAudioEl(),
        isPlaying: () => {
            const a = getAudioEl();
            return Boolean(a && !a.paused);
        },
        playStation: (name, streamUrl, genre) => {
            const a = getAudioEl();
            if (!a || !streamUrl) return;
            bindAudioRecoveryEvents(a);
            ensureStreamWatchdog();
            clearReconnectSchedule();
            reconnectAttemptCount = 0;
            frozenTicksCount      = 0;

            const prev = getState() || {};
            const vol = typeof prev.volume === 'number' ? prev.volume : 85;
            a.src = streamUrl;
            a.volume = Math.max(0, Math.min(1, vol / 100));
            a.muted = Boolean(prev.muted);
            saveState({
                playing: true,
                closed: false,
                reconnecting: false,
                name: name || 'Live-Webradio',
                streamUrl: streamUrl,
                genre: genre || 'Live',
                volume: vol,
                muted: a.muted,
                songTitle: 'Verbinde mit ' + (name || 'Live-Stream') + '…'
            });
            updateMiniBarUi();
            a.play().then(() => {
                lastAudioCurrentTime = a.currentTime;
                saveState({
                    playing: true,
                    closed: false,
                    reconnecting: false,
                    songTitle: 'Live-Übertragung • ' + (name || 'Radio')
                });
                updateMiniBarUi();
                pollIcyTitle();
                ensureIcyPolling();
            }).catch(() => {
                // Falls Netzwerk beim Start kurz weg ist, automatisch weiterversuchen
                scheduleStreamReconnect(2500);
                updateMiniBarUi();
            });
        },
        toggle: () => {
            const a = getAudioEl();
            const st = getState();
            if (!a || !st || !st.streamUrl) return;
            bindAudioRecoveryEvents(a);
            ensureStreamWatchdog();
            if (a.paused && !st.reconnecting) {
                clearReconnectSchedule();
                a.src = st.streamUrl;
                a.volume = Math.max(0, Math.min(1, (typeof st.volume === 'number' ? st.volume : 85) / 100));
                a.muted = Boolean(st.muted);
                saveState({ playing: true, closed: false, reconnecting: false });
                a.play().then(() => {
                    lastAudioCurrentTime = a.currentTime;
                    updateMiniBarUi();
                    ensureIcyPolling();
                }).catch(() => {
                    scheduleStreamReconnect(2500);
                });
            } else {
                clearReconnectSchedule();
                a.pause();
                saveState({ playing: false, reconnecting: false });
                updateMiniBarUi();
            }
        },
        pause: () => {
            clearReconnectSchedule();
            const a = getAudioEl();
            if (a && !a.paused) a.pause();
            saveState({ playing: false, reconnecting: false });
            updateMiniBarUi();
        },
        setVolume: (val) => {
            const v = Math.max(0, Math.min(100, Number(val)));
            const a = getAudioEl();
            if (a) {
                a.muted = false;
                a.volume = v / 100;
            }
            saveState({ volume: v, muted: false });
            updateMiniBarUi();
        },
        toggleMute: () => {
            const a = getAudioEl();
            if (!a) return false;
            a.muted = !a.muted;
            saveState({ muted: a.muted });
            updateMiniBarUi();
            return a.muted;
        },
        stopAndClose: () => {
            clearReconnectSchedule();
            const a = getAudioEl();
            if (a) {
                a.pause();
                a.removeAttribute('src');
                a.load();
            }
            if (icyTimer) {
                clearInterval(icyTimer);
                icyTimer = null;
            }
            saveState({ playing: false, closed: true, reconnecting: false });
            updateMiniBarUi();
            if (typeof updateRadioUiPlaying === 'function') {
                updateRadioUiPlaying(false);
            }
        },
        syncFromState: () => {
            updateMiniBarUi();
        },
        notifyChildPageLoaded: (childWin) => {
            try {
                const childHref = childWin.location.href;
                const childPath = childWin.location.pathname || '';
                if (childPath.endsWith('/login.php') || childPath.endsWith('/logout.php')) {
                    api.stopAndClose();
                    window.top.location.href = childHref;
                    return;
                }
                if (childWin.document && childWin.document.title) {
                    document.title = childWin.document.title;
                }
                if (window.location.href !== childHref) {
                    window.history.replaceState({ mcRadioShell: true }, '', childHref);
                }
                // Abfangen von Logout-Klicks im Child-Dokument
                childWin.document.addEventListener('click', (ev) => {
                    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
                    if (!a) return;
                    const href = a.getAttribute('href') || '';
                    if (href.includes('logout.php')) {
                        api.stopAndClose();
                        window.top.location.href = a.href;
                    }
                }, true);
            } catch (e) {}
            updateMiniBarUi();
        }
    };

    window.McGlobalRadio = api;

    // Klick-Interceptor im Hauptfenster: Wenn das Radio aktiv spielt, öffne Modulwechsel
    // unterbrechungsfrei im Viewport, sodass der Audio-Stream zu 100 % ohne Aussetzer weiterläuft.
    document.addEventListener('click', (ev) => {
        const anchor = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
        if (!anchor) return;
        const href = anchor.getAttribute('href') || '';
        if (href.includes('logout.php')) {
            api.stopAndClose();
            return;
        }
        const st = getState();
        const isRadioActive = api.isPlaying() || Boolean(st && st.playing && st.streamUrl && !st.closed);
        if (!isRadioActive) return;

        if (shouldInterceptLinkClick(ev, anchor)) {
            ev.preventDefault();
            openInSeamlessViewport(anchor.href);
        }
    }, true);

    window.addEventListener('popstate', () => {
        if (appFrame && !appFrame.classList.contains('hidden')) {
            try {
                if (appFrame.contentWindow && appFrame.contentWindow.location.href !== window.location.href) {
                    appFrame.src = window.location.href;
                }
            } catch (e) {}
        }
        updateMiniBarUi();
    });

    // Auto-Resume bei vollständigem Seiten-Reload (F5 / direkter Aufruf), falls Radio aktiv war
    const initAutoResume = () => {
        const st = getState();
        if (!st || !st.streamUrl || st.closed) return;
        updateMiniBarUi();
        if (st.playing) {
            const a = getAudioEl();
            if (a) {
                bindAudioRecoveryEvents(a);
                ensureStreamWatchdog();
            }
            if (a && a.paused) {
                a.src = st.streamUrl;
                a.volume = Math.max(0, Math.min(1, (typeof st.volume === 'number' ? st.volume : 85) / 100));
                a.muted = Boolean(st.muted);
                a.play().then(() => {
                    lastAudioCurrentTime = a.currentTime;
                    updateMiniBarUi();
                    ensureIcyPolling();
                    if (typeof syncRadioDockFromState === 'function') {
                        syncRadioDockFromState();
                    }
                }).catch(() => {
                    // Falls der Browser nach F5 einen Klick benötigt, bleibt die Mini-Bar sichtbar (▶️ bereit)
                    updateMiniBarUi();
                });
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAutoResume);
    } else {
        initAutoResume();
    }
})();
</script>
<?php endif; ?>
</body>
</html>
