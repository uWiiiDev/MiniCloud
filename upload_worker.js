/**
 * MiniCloud – Persistent Background Upload SharedWorker (upload_worker.js)
 * Keeps file & folder uploads running across page switches and broadcasts live progress
 * to all connected MiniCloud tabs/frames via includes/footer.php.
 */

const ports = new Set();
let activeXhr = null;
let uploadStartTime = 0;

let state = {
    active: false,
    status: 'idle', // 'idle' | 'uploading' | 'done' | 'error' | 'cancelled'
    pct: 0,
    loaded: 0,
    total: 0,
    speedBps: 0,
    fileCount: 0,
    targetDisplay: 'Hauptverzeichnis',
    redirectUrl: 'index.php',
    message: '',
    updatedAt: 0
};

function broadcastState() {
    state.updatedAt = Date.now();
    const msg = { type: 'upload_state', state };
    ports.forEach((p) => {
        try {
            p.postMessage(msg);
        } catch (e) {
            ports.delete(p);
        }
    });
}

function startWorkerUpload(payload) {
    if (state.active && activeXhr) {
        return;
    }

    const items = Array.isArray(payload.items) ? payload.items : [];
    if (items.length === 0) return;

    const totalBytes = items.reduce((acc, it) => acc + ((it.file && it.file.size) || 0), 0);

    state = {
        active: true,
        status: 'uploading',
        pct: 0,
        loaded: 0,
        total: totalBytes,
        speedBps: 0,
        fileCount: items.length,
        targetDisplay: payload.targetDisplay || 'Hauptverzeichnis',
        redirectUrl: 'index.php',
        message: `Lade ${items.length} Datei(en) in ${payload.targetDisplay || 'Hauptverzeichnis'} hoch…`,
        updatedAt: Date.now()
    };
    uploadStartTime = Date.now();
    broadcastState();

    const fd = new FormData();
    fd.append('csrf_token', payload.csrfToken || '');
    fd.append('target_folder', payload.targetFolder || '');
    fd.append('bg_worker', '1');
    if (payload.sharedFolderId) {
        fd.append('shared_folder_id', String(payload.sharedFolderId));
    }
    if (payload.groupFolderId) {
        fd.append('group_folder_id', String(payload.groupFolderId));
    }
    if (payload.vaultPassword) {
        fd.append('vault_password', String(payload.vaultPassword));
    }

    for (const item of items) {
        if (item && item.file) {
            fd.append('files[]', item.file, item.file.name);
            fd.append('relative_paths[]', item.relativePath || item.file.name);
        }
    }

    const xhr = new XMLHttpRequest();
    activeXhr = xhr;
    xhr.open('POST', 'upload.php', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.addEventListener('progress', (evt) => {
        if (evt.lengthComputable) {
            const pct = Math.min(99, Math.round((evt.loaded / Math.max(1, evt.total)) * 100));
            const elapsedSec = Math.max(0.25, (Date.now() - uploadStartTime) / 1000);
            state.pct = pct;
            state.loaded = evt.loaded;
            state.total = evt.total;
            state.speedBps = Math.round(evt.loaded / elapsedSec);
            broadcastState();
        }
    });

    xhr.addEventListener('load', () => {
        activeXhr = null;
        let resp = null;
        try {
            resp = JSON.parse(xhr.responseText);
        } catch (e) {}

        const ok = resp ? Boolean(resp.success) : (xhr.status >= 200 && xhr.status < 300);
        state.active = false;
        state.status = ok ? 'done' : 'error';
        state.pct = ok ? 100 : state.pct;
        state.loaded = ok ? state.total : state.loaded;
        state.redirectUrl = (resp && resp.redirect) ? resp.redirect : 'index.php';
        state.message = (resp && resp.message)
            ? resp.message
            : (ok ? `${state.fileCount} Datei(en) erfolgreich in ${state.targetDisplay} hochgeladen!` : 'Fehler beim Hochladen.');
        broadcastState();
    });

    xhr.addEventListener('error', () => {
        activeXhr = null;
        state.active = false;
        state.status = 'error';
        state.message = 'Netzwerkfehler beim Hochladen.';
        broadcastState();
    });

    xhr.addEventListener('abort', () => {
        activeXhr = null;
        state.active = false;
        state.status = 'cancelled';
        state.message = 'Upload abgebrochen.';
        broadcastState();
    });

    xhr.send(fd);
}

self.onconnect = (connectEvent) => {
    const port = connectEvent.ports[0];
    ports.add(port);
    port.start();

    // Send current state immediately upon connection
    port.postMessage({ type: 'upload_state', state });

    port.onmessage = (e) => {
        const data = e.data || {};
        if (data.type === 'start_upload') {
            startWorkerUpload(data.payload || {});
        } else if (data.type === 'cancel_upload') {
            if (activeXhr) {
                activeXhr.abort();
            }
        } else if (data.type === 'dismiss') {
            if (!state.active) {
                state.status = 'idle';
                state.pct = 0;
                state.loaded = 0;
                state.total = 0;
                state.message = '';
                broadcastState();
            }
        } else if (data.type === 'get_state') {
            port.postMessage({ type: 'upload_state', state });
        }
    };
};
