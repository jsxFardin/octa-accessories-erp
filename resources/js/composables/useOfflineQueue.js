import { onMounted, onUnmounted, ref } from 'vue';

/**
 * The offline queue (07-api-contracts §7, NFR-9).
 *
 * A loom does not stop when the wifi does. Writes are stamped with `occurred_at` at the moment
 * the operator presses the button and carry a stable `Idempotency-Key`, so a queue drained
 * after an outage lands in the right order and a retried request is a replay rather than a
 * second shift's output.
 *
 * Each record also keeps the device token it was booked under. The server attributes a write
 * to whoever holds the token that posts it, and a second badge can sign in at this kiosk
 * without the first ending their shift — so a queue drained with whatever token happened to be
 * live booked one operator's output under the next one's name, and changed the idempotency
 * scope under a half-sent queue besides.
 */
const STORAGE_KEY = 'octa.offline_queue';
// Writes the server answered and refused. A 4xx is not a wifi problem, and replaying it forever
// is what hid an unfinishable job card behind a silent spinner.
const REJECTED_KEY = 'octa.offline_rejected';
/*
 * How long a record may wait before it is handed to a supervisor instead. NFR-9 asks for at
 * least four hours; the server accepts any past `occurred_at` and keeps an idempotency key for
 * twenty-four, so that is the window — an overnight outage no longer costs a morning of
 * re-keying.
 */
const MAX_AGE_MS = 24 * 60 * 60 * 1000;

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

/** Told when the rejected list changes, so every screen's count follows a removal. */
const CHANGED = 'octa:offline-queue-changed';

function announce() {
    if (typeof window !== 'undefined') window.dispatchEvent(new Event(CHANGED));
}

const ACTIONS = { start: 'start', log: 'log', finish: 'finish', downtime: 'downtime' };

/**
 * One rejected record, in the shape the "Not sent" screen shows it.
 *
 * The list used to be a count and nothing else: "2 records not sent — call your supervisor",
 * and a supervisor who came had no way to learn which job, what quantity or why. Everything
 * needed to book it by hand at the desk is read back out here. Records filed before the
 * terminal started keeping `meta` still show their action, figures and time.
 */
export function describeRejected(entry) {
    const match = /\/operations\/(\d+)\/(\w+)$/.exec(entry.url ?? '');
    let body = {};

    try {
        body = typeof entry.body === 'string' && entry.body !== '' ? JSON.parse(entry.body) : (entry.body ?? {});
    } catch {
        body = {};
    }

    return {
        key: entry.key,
        operationId: match ? Number(match[1]) : null,
        action: ACTIONS[match?.[2]] ?? 'log',
        occurredAt: entry.occurredAt,
        rejectedAt: entry.rejectedAt,
        expired: entry.reason === 'expired',
        // The token it was booked under had gone by the time it could be sent.
        sessionLost: entry.reason === 'session',
        status: entry.status ?? 0,
        code: body?.code ?? null,
        params: body?.params ?? {},
        payload: entry.payload ?? {},
        job: entry.meta?.job ?? null,
        step: entry.meta?.step ?? null,
        unit: entry.meta?.unit ?? null,
        operator: entry.meta?.operator ?? null,
    };
}

/** Every record this device could not send, oldest first. */
export function rejectedRecords(list = readRejected()) {
    return list
        .map(describeRejected)
        .sort((a, b) => String(a.occurredAt).localeCompare(String(b.occurredAt)));
}

/**
 * Takes one record off the rejected list — after a supervisor has booked it at the desk.
 * This is the only place a record leaves the device without the server having accepted it.
 */
export function removeRejected(key, rejectedAt) {
    const kept = readRejected().filter((entry) => !(entry.key === key && entry.rejectedAt === rejectedAt));

    localStorage.setItem(REJECTED_KEY, JSON.stringify(kept));
    announce();

    return kept.length;
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

/**
 * What an HTTP answer means for a queued record (07-api-contracts §4).
 *
 *  - `retry`  — the server did not get to decide: it was busy, throttling, or broken. The
 *               record stays and is tried again later, with the pause below.
 *  - `session`— the token the record was booked under is gone (ended shift, expired). The
 *               booking cannot be re-attributed from here; a supervisor books it by hand.
 *  - `reject` — the server decided, and said no. Nothing about waiting changes that.
 */
export function retryDecision(status) {
    if (status === 401) return 'session';
    if (status === 408 || status === 425 || status === 429 || status >= 500) return 'retry';

    return 'reject';
}

/** Thirty seconds, doubling per failed attempt, never more than ten minutes between tries. */
export function retryDelayMs(attempts) {
    return Math.min(30 * 1000 * 2 ** Math.max(0, attempts - 1), 10 * 60 * 1000);
}

/** The records whose pause, if any, has run out. */
export function dueEntries(queue, now = Date.now()) {
    return queue.filter((entry) => !entry.nextAttemptAt || Date.parse(entry.nextAttemptAt) <= now);
}

/**
 * A key that identifies one intended write.
 *
 * It is made when the operator opens the form, not when they press the button, and handed to
 * `send`. Every press of SAVE on that form then carries the same key, so a double tap, or a
 * retry after a timeout, is recognised by the server as the same booking rather than a second
 * shift's output.
 */
export function idempotencyKey() {
    return crypto.randomUUID();
}

/** How often unsent records are tried again while any are waiting. */
const RETRY_MS = 30 * 1000;

/**
 * Good, waste and input still waiting on this device for one operation.
 *
 * The tiles on the operation screen come from the server, so a booking that has only been
 * queued did not show in them — the operator saw "Logged", saw no change, and booked it again.
 */
export function queuedOutputFor(operationId, queue = readQueue()) {
    const totals = { good: 0, waste: 0, input: 0, count: 0 };
    const url = `/api/v1/operations/${operationId}/log`;

    for (const entry of queue) {
        if (entry.url !== url) continue;

        totals.good += Number(entry.payload?.good_qty) || 0;
        totals.waste += Number(entry.payload?.waste_qty) || 0;
        totals.input += Number(entry.payload?.input_qty) || 0;
        totals.count += 1;
    }

    return totals;
}

export function useOfflineQueue() {
    const pending = ref(readQueue().length);
    const rejected = ref(readRejected().length);
    const online = ref(navigator.onLine);
    /** Bumped whenever the queue changes, so screens that read it can recompute. */
    const revision = ref(0);
    let flushing = false;
    let retryTimer = null;

    function sync(queue = readQueue()) {
        pending.value = queue.length;
        rejected.value = readRejected().length;
        revision.value += 1;
    }

    async function post(entry) {
        // The token the record was booked under, so it is attributed to that operator even if
        // another badge has signed in here since. Records filed before tokens were kept fall
        // back to whoever is signed in now.
        const token = entry.token ?? session()?.token;

        return fetch(entry.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'Idempotency-Key': entry.key,
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ ...entry.payload, occurred_at: entry.occurredAt }),
        });
    }

    async function flush() {
        // One drain at a time: the timer, the `online` event and "Send now" can all ask at once,
        // and two drains over the same queue would post every entry twice.
        if (flushing) return;

        const queue = readQueue();

        if (queue.length === 0) {
            sync(queue);

            return;
        }

        flushing = true;

        try {
            // Oldest first: the server orders by occurred_at, and so does the drain.
            queue.sort((a, b) => a.occurredAt.localeCompare(b.occurredAt));

            const remaining = [];
            const { fresh, expired } = splitExpired(queue);

            for (const entry of expired) {
                reject(entry, 0, '', 'expired');
            }

            const due = new Set(dueEntries(fresh).map((entry) => entry.key));

            for (const entry of fresh) {
                // Still inside its pause after a failed try: kept, not posted.
                if (!due.has(entry.key)) {
                    remaining.push(entry);

                    continue;
                }

                try {
                    const response = await post(entry);

                    if (response.ok) continue;

                    const decision = retryDecision(response.status);

                    // A busy, throttling or broken server has not decided anything. The record
                    // stays, and waits a little longer each time. A ten-minute 503 at reconnect
                    // used to turn a whole shift's queue into "Not sent".
                    if (decision === 'retry') {
                        const attempts = (entry.attempts ?? 0) + 1;

                        remaining.push({
                            ...entry,
                            attempts,
                            nextAttemptAt: new Date(Date.now() + retryDelayMs(attempts)).toISOString(),
                        });

                        continue;
                    }

                    reject(entry, response.status, await response.text().catch(() => ''), decision);
                } catch {
                    remaining.push(entry);
                }
            }

            // Anything queued while this drain was running is kept, not overwritten.
            const drained = new Set(queue.map((entry) => entry.key));
            const added = readQueue().filter((entry) => !drained.has(entry.key));

            writeQueue([...remaining, ...added]);
        } finally {
            flushing = false;
            sync();
        }
    }

    function enqueue(entry) {
        const queue = readQueue();

        // The same key twice is the same booking pressed twice: keep one.
        if (!queue.some((queued) => queued.key === entry.key)) queue.push(entry);

        writeQueue(queue);
        sync(queue);
    }

    /**
     * @param {string} url
     * @param {object} payload
     * @param {string|null} key  from `idempotencyKey()`, made when the form was opened
     * @param {object|null} meta  `{ job, step, unit }` — shown if the record cannot be sent
     * @returns {Promise<object>} `{ queued: true }` when it is saved on this device only,
     *   `{ error: true, … }` when the server refused it, otherwise the server's answer.
     */
    async function send(url, payload, key = null, meta = null) {
        const auth = session();
        const entry = {
            url,
            payload,
            key: key ?? idempotencyKey(),
            occurredAt: new Date().toISOString(),
            // Whose booking this is, whoever is signed in by the time it is sent.
            token: auth?.token ?? null,
            // Never posted. Kept so that a record which ends up on the "Not sent" screen can
            // say which job and step it was for, in what unit, and who booked it.
            meta: { ...(meta ?? {}), operator: auth?.employee_name ?? null },
        };

        if (!navigator.onLine) {
            enqueue(entry);

            return { queued: true };
        }

        try {
            const response = await post(entry);

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                const decision = retryDecision(response.status);

                // A busy or broken server has not refused anything: the record is saved here
                // and tried again on the timer, exactly as if the link had dropped.
                if (decision === 'retry') {
                    enqueue({ ...entry, attempts: 1, nextAttemptAt: new Date(Date.now() + retryDelayMs(1)).toISOString() });

                    return { queued: true };
                }

                // The server answered and refused. Hand the refusal back so the terminal can
                // show it; queueing a rejected write only buries the reason.
                return {
                    error: true,
                    status: response.status,
                    // What the floor rules named it, and the figures behind it — the terminal
                    // says the sentence itself, in both languages (`floor/dictionary.js`).
                    code: body.code ?? (decision === 'session' ? 'session_expired' : null),
                    params: body.params ?? {},
                    message: body.message ?? `The server refused this (HTTP ${response.status}).`,
                };
            }

            return await response.json();
        } catch {
            // The browser said it was online and the request still did not get through —
            // wifi up, server unreachable. Saved here and tried again on the timer.
            enqueue(entry);

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

    function onChanged() {
        sync();
    }

    onMounted(() => {
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);
        window.addEventListener(CHANGED, onChanged);
        flush();

        // `online` only fires when the browser's own idea of the network changes. A server that
        // is down behind working wifi never fires it, so waiting records are retried on a timer.
        retryTimer = setInterval(() => {
            if (pending.value > 0) flush();
        }, RETRY_MS);
    });

    onUnmounted(() => {
        window.removeEventListener('online', onOnline);
        window.removeEventListener('offline', onOffline);
        window.removeEventListener(CHANGED, onChanged);
        clearInterval(retryTimer);
    });

    return { send, flush, pending, rejected, online, revision };
}
