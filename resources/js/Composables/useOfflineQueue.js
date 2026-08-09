import { onMounted, onUnmounted, ref } from 'vue';

/**
 * The four-hour offline queue (07-api-contracts §7).
 *
 * A loom does not stop when the wifi does. Writes are stamped with `occurred_at` at the moment
 * the operator presses the button and carry a stable `Idempotency-Key`, so a queue drained
 * after an outage lands in the right order and a retried request is a replay rather than a
 * second shift's output.
 */
const STORAGE_KEY = 'octa.offline_queue';
const MAX_AGE_MS = 4 * 60 * 60 * 1000;

function readQueue() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
}

function writeQueue(queue) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(queue));
}

function session() {
    return JSON.parse(localStorage.getItem('octa.device_session') ?? 'null');
}

function idempotencyKey() {
    return crypto.randomUUID();
}

export function useOfflineQueue() {
    const pending = ref(readQueue().length);
    const online = ref(navigator.onLine);

    async function post(entry) {
        const auth = session();

        return fetch(entry.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'Idempotency-Key': entry.key,
                Authorization: `Bearer ${auth?.token}`,
            },
            body: JSON.stringify({ ...entry.payload, occurred_at: entry.occurredAt }),
        });
    }

    async function flush() {
        const queue = readQueue();

        if (queue.length === 0) {
            return;
        }

        // Oldest first: the server orders by occurred_at, and so does the drain.
        queue.sort((a, b) => a.occurredAt.localeCompare(b.occurredAt));

        const remaining = [];

        for (const entry of queue) {
            // An entry older than the window is dropped rather than posted: a shift's output
            // arriving half a day late is worse than a gap someone has to reconcile.
            if (Date.now() - Date.parse(entry.occurredAt) > MAX_AGE_MS) {
                continue;
            }

            try {
                const response = await post(entry);

                if (!response.ok && response.status >= 500) {
                    remaining.push(entry);
                }
            } catch {
                remaining.push(entry);
            }
        }

        writeQueue(remaining);
        pending.value = remaining.length;
    }

    async function send(url, payload) {
        const entry = {
            url,
            payload,
            key: idempotencyKey(),
            occurredAt: new Date().toISOString(),
        };

        if (!navigator.onLine) {
            const queue = readQueue();
            queue.push(entry);
            writeQueue(queue);
            pending.value = queue.length;

            return { queued: true };
        }

        try {
            const response = await post(entry);

            if (!response.ok) {
                if (response.status >= 500) {
                    const queue = readQueue();
                    queue.push(entry);
                    writeQueue(queue);
                    pending.value = queue.length;
                }

                return { error: await response.json().catch(() => ({})) };
            }

            return await response.json();
        } catch {
            const queue = readQueue();
            queue.push(entry);
            writeQueue(queue);
            pending.value = queue.length;

            return { queued: true };
        }
    }

    function onOnline() {
        online.value = true;
        flush();
    }

    function onOffline() {
        online.value = false;
    }

    onMounted(() => {
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);
        flush();
    });

    onUnmounted(() => {
        window.removeEventListener('online', onOnline);
        window.removeEventListener('offline', onOffline);
    });

    return { send, flush, pending, online };
}
