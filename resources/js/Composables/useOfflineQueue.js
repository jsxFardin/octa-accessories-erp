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
// Writes the server actively rejected. They are not retried — a 500 is not a wifi problem,
// and replaying it forever is what hid an unfinishable job card behind a silent spinner.
const REJECTED_KEY = 'octa.offline_rejected';
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

function readRejected() {
    try {
        return JSON.parse(localStorage.getItem(REJECTED_KEY) ?? '[]');
    } catch {
        return [];
    }
}

function reject(entry, status, body, reason = 'refused') {
    const rejected = readRejected();
    rejected.push({ ...entry, status, body, reason, rejectedAt: new Date().toISOString() });
    localStorage.setItem(REJECTED_KEY, JSON.stringify(rejected));
}

/**
 * Splits a queue into what may still be posted and what has outlived the window.
 *
 * An entry older than the window is not posted: a shift's output arriving half a day late is
 * worse than a gap someone reconciles. It is not thrown away either — it used to be, silently,
 * and an overnight outage lost the output with no trace. The expired half goes to the rejected
 * list, where a supervisor can see it and key it in by hand.
 */
export function splitExpired(queue, now = Date.now(), maxAgeMs = MAX_AGE_MS) {
    const fresh = [];
    const expired = [];

    for (const entry of queue) {
        (now - Date.parse(entry.occurredAt) > maxAgeMs ? expired : fresh).push(entry);
    }

    return { fresh, expired };
}

function session() {
    return JSON.parse(localStorage.getItem('octa.device_session') ?? 'null');
}

function idempotencyKey() {
    return crypto.randomUUID();
}

export function useOfflineQueue() {
    const pending = ref(readQueue().length);
    const rejected = ref(readRejected().length);
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
        const { fresh, expired } = splitExpired(queue);

        for (const entry of expired) {
            reject(entry, 0, '', 'expired');
        }

        for (const entry of fresh) {
            try {
                const response = await post(entry);

                // Only a transport failure earns a retry. A server that answered — with
                // anything — has seen this write, and repeating it will not change its mind.
                if (!response.ok) {
                    reject(entry, response.status, await response.text().catch(() => ''));
                }
            } catch {
                remaining.push(entry);
            }
        }

        writeQueue(remaining);
        pending.value = remaining.length;
        rejected.value = readRejected().length;
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

            // The server answered and refused. Hand the refusal back so the terminal can show
            // it; queueing a rejected write only buries the reason.
            if (!response.ok) {
                const body = await response.json().catch(() => ({}));

                if (response.status >= 500) {
                    reject(entry, response.status, JSON.stringify(body));
                    rejected.value = readRejected().length;
                }

                return {
                    error: true,
                    status: response.status,
                    message: body.message ?? `The server refused this (HTTP ${response.status}).`,
                };
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

    return { send, flush, pending, rejected, online };
}
