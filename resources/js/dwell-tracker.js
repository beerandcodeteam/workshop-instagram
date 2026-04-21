// Buffer + flusher para sinais de dwell time enviados por post.card
// (consumido pelo endpoint POST /api/rec/view-events).
//
// Os cards Alpine emitem CustomEvents `dwell-enter` / `dwell-leave` no window;
// aqui acumulamos a duração total visível por post e enviamos em batch.

const FLUSH_INTERVAL_MS = 15000;
const ENDPOINT = '/api/rec/view-events';

const state = {
    sessionId: null,
    open: new Map(), // post_id -> { enteredAt: epoch_ms }
    pending: new Map(), // post_id -> { duration_ms: int }
};

function ensureSessionId() {
    if (state.sessionId !== null) {
        return state.sessionId;
    }

    const stored = sessionStorage.getItem('rec.session_id');

    if (stored) {
        state.sessionId = stored;
    } else {
        state.sessionId = crypto.randomUUID();
        sessionStorage.setItem('rec.session_id', state.sessionId);
    }

    return state.sessionId;
}

function recordEnter(postId) {
    if (state.open.has(postId)) {
        return;
    }

    state.open.set(postId, { enteredAt: Date.now() });
}

function recordLeave(postId) {
    const opened = state.open.get(postId);

    if (! opened) {
        return;
    }

    const duration = Date.now() - opened.enteredAt;
    state.open.delete(postId);

    const existing = state.pending.get(postId);

    if (existing) {
        existing.duration_ms += duration;
    } else {
        state.pending.set(postId, { duration_ms: duration });
    }
}

function snapshotPending() {
    // Snapshot dos buffers + fechar janelas abertas computando o tempo até agora.
    const now = Date.now();
    const events = [];

    state.pending.forEach((value, postId) => {
        events.push({ post_id: postId, duration_ms: value.duration_ms });
    });

    state.open.forEach((value, postId) => {
        const duration = now - value.enteredAt;
        events.push({ post_id: postId, duration_ms: duration });
        value.enteredAt = now; // re-zera para a próxima janela
    });

    state.pending.clear();

    return events;
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : '';
}

async function flush() {
    const events = snapshotPending();

    if (events.length === 0) {
        return;
    }

    const payload = JSON.stringify({
        session_id: ensureSessionId(),
        events,
    });

    try {
        await fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload,
            keepalive: true,
        });
    } catch (err) {
        // Silencioso: dwell tracking nunca deve quebrar a UI.
    }
}

function flushBeacon() {
    const events = snapshotPending();

    if (events.length === 0) {
        return;
    }

    const payload = JSON.stringify({
        session_id: ensureSessionId(),
        events,
    });

    if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon(ENDPOINT, blob);
    }
}

window.addEventListener('dwell-enter', (event) => {
    const postId = Number(event.detail?.postId);

    if (Number.isFinite(postId) && postId > 0) {
        recordEnter(postId);
    }
});

window.addEventListener('dwell-leave', (event) => {
    const postId = Number(event.detail?.postId);

    if (Number.isFinite(postId) && postId > 0) {
        recordLeave(postId);
    }
});

window.addEventListener('beforeunload', flushBeacon);
window.addEventListener('pagehide', flushBeacon);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        flushBeacon();
    }
});

setInterval(flush, FLUSH_INTERVAL_MS);
