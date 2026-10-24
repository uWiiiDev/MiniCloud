const CACHE_NAME = 'minicloud-pwa-v1';

const OFFLINE_HTML = `<!DOCTYPE html>
<html lang="de" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline – Cloud-Speicher</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding: 1.5rem;
            box-sizing: border-box;
        }
        .card {
            max-width: 24rem;
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 1rem;
            padding: 2rem 1.5rem;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
        }
        .icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1rem;
            border-radius: 0.875rem;
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        h1 { font-size: 1.125rem; margin: 0 0 0.5rem; }
        p { font-size: 0.875rem; color: #94a3b8; line-height: 1.5; margin: 0 0 1.5rem; }
        button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 0.75rem;
            padding: 0.75rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }
        button:active { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">☁️</div>
        <h1>Keine Internetverbindung</h1>
        <p>Ihr Gerät ist momentan offline. Bitte prüfen Sie Ihre WLAN- oder Mobilfunkverbindung, um auf Ihre Cloud-Dateien zuzugreifen.</p>
        <button onclick="window.location.reload()">Erneut versuchen</button>
    </div>
</body>
</html>`;

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Nur GET-Anfragen berücksichtigen; Datei-Downloads niemals cachen
    if (req.method !== 'GET' || req.url.includes('download.php')) {
        return;
    }

    // Für Seitennavigationen: Network-First mit sauberem Offline-Fallback
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() =>
                new Response(OFFLINE_HTML, {
                    status: 503,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                })
            )
        );
        return;
    }

    // Für App-Icons & lokale Assets (Tailwind): Network-First mit Cache-Fallback
    if (req.url.includes('app-icon.php') || req.url.includes('assets/tailwind.js')) {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    if (res && res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
                    }
                    return res;
                })
                .catch(() => caches.match(req))
        );
    }
});
