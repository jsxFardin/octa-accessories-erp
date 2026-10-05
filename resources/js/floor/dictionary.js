import { ref } from 'vue';

/**
 * Every sentence the shop-floor terminal says, in Bangla and in English, in one place.
 *
 * The terminal's words were scattered through three pages as hand-written "বাংলা · English"
 * pairs, with the longest guidance — why the queue is empty, what to do when a record will not
 * send — in English only, and the server's refusals arriving as English prose. A new line was a
 * new chance to forget the Bangla half. Now a line that is not in here cannot be shown.
 *
 * Two forms, by length:
 *
 *  - `label(key)` — a short label, both languages on one line: "ভালো · Good". The operator reads
 *    the Bangla; the supervisor, the engineer and the next person to debug this read the English.
 *  - `guide(key)` — a sentence or more. Bangla by default, the whole terminal switched to English
 *    with one button, because two full paragraphs side by side is a wall nobody reads.
 *
 * `{name}` in a line is filled from the params.
 */
const LINES = {
    // --- Labels ---------------------------------------------------------------------------
    work_queue: ['কাজের তালিকা', 'Work queue'],
    end_shift: ['শিফট শেষ', 'END SHIFT'],
    back_to_queue: ['কাজের তালিকা', 'QUEUE'],
    online: ['অনলাইন', 'ONLINE'],
    offline: ['অফলাইন', 'OFFLINE'],
    send_now: ['এখন পাঠান', 'SEND NOW'],
    view: ['দেখুন', 'VIEW'],
    planned: ['পরিকল্পিত', 'Planned'],
    input: ['ইনপুট', 'Input'],
    good: ['ভালো', 'Good'],
    waste: ['নষ্ট', 'Waste'],
    start: ['শুরু', 'START'],
    output: ['আউটপুট', 'OUTPUT'],
    downtime: ['ডাউনটাইম', 'DOWNTIME'],
    finish: ['শেষ', 'FINISH'],
    save: ['সেভ', 'SAVE'],
    cancel: ['বাতিল', 'CANCEL'],
    running: ['চলছে', 'RUNNING'],
    not_started: ['শুরু হয়নি', 'NOT STARTED'],
    due: ['ডেলিভারি', 'Due'],
    made: ['তৈরি', 'Made'],
    step: ['ধাপ', 'Step'],
    machine: ['মেশিন', 'Machine'],
    not_sent_title: ['পাঠানো যায়নি', 'Not sent'],
    dismiss: ['তালিকা থেকে সরান', 'REMOVE FROM LIST'],
    unit_m: ['মিটার', 'm'],
    unit_pcs: ['পিস', 'pcs'],
    action_log: ['আউটপুট', 'Output'],
    action_start: ['শুরু', 'Start'],
    action_finish: ['শেষ', 'Finish'],
    action_downtime: ['ডাউনটাইম', 'Downtime'],
    reason_expired: ['সময় পেরিয়ে গেছে', 'Too old to send'],
    reason_refused: ['সার্ভার নেয়নি', 'Refused by the server'],

    terminal: ['শপ ফ্লোর টার্মিনাল', 'Shop floor terminal'],
    badge: ['ব্যাজ', 'Badge'],
    pin: ['পিন', 'PIN'],
    sign_in: ['শুরু', 'SIGN IN'],
    any_machine: ['যেকোনো', 'any'],
    continue_no_badge: ['ব্যাজ ছাড়া চালিয়ে যান', 'CONTINUE WITHOUT A BADGE'],
    artwork: ['আর্টওয়ার্ক', 'Artwork'],
    input_received: ['ইনপুট', 'Input received'],
    open_job: ['কাজ খুলুন', 'OPEN JOB'],
    close: ['বন্ধ', 'CLOSE'],
    booked_at: ['বুক করা হয়েছে', 'Booked'],
    booked_by: ['অপারেটর', 'Operator'],
    yes_remove: ['হ্যাঁ, সরান', 'YES, REMOVE'],
    unknown_job: ['কাজ #{id}', 'Operation #{id}'],
    waste_setup: ['সেটআপ', 'Setup'],
    waste_shade: ['শেড', 'Shade'],
    waste_weave_defect: ['বুনন ত্রুটি', 'Weave defect'],
    waste_print_defect: ['প্রিন্ট ত্রুটি', 'Print defect'],
    waste_cutting: ['কাটিং', 'Cutting'],
    waste_edge_trim: ['ধার', 'Edge trim'],
    waste_damaged: ['ক্ষতিগ্রস্ত', 'Damaged'],
    waste_expired: ['মেয়াদোত্তীর্ণ', 'Expired'],
    waste_other: ['অন্যান্য', 'Other'],

    // --- Status lines ---------------------------------------------------------------------
    waiting_count: ['{n}টি অপেক্ষায়', '{n} WAITING'],
    not_sent_count: ['{n}টি রেকর্ড পাঠানো যায়নি — সুপারভাইজারকে জানান', '{n} record(s) not sent — tell your supervisor'],
    sent: ['রেকর্ড পাঠানো হয়েছে', 'Sent'],
    started: ['শুরু হয়েছে', 'Started'],
    downtime_sent: ['ডাউনটাইম পাঠানো হয়েছে', 'Downtime sent'],
    saved_on_device: ['এই ডিভাইসে সেভ হয়েছে — সংযোগ ফিরলে পাঠানো হবে', 'Saved on this device — it will be sent when the connection is back'],
    waiting_marker: ['অপেক্ষায়', 'waiting'],
    nothing_to_run: ['কোনো কাজ নেই', 'Nothing to run'],
    already_running: ['এই ধাপ চলছে', 'This step is running'],

    // --- Guidance (Bangla, or English when switched) ---------------------------------------
    scan_badge: ['ব্যাজ স্ক্যান করুন, তারপর পিন দিন', 'Scan your badge, then enter your PIN'],
    what_is_this: [
        'এটি অপারেটরের স্ক্রিন — মেশিনের পাশের টার্মিনাল, যেখানে চলমান জব কার্ডের আউটপুট, ডাউনটাইম ও নষ্ট লেখা হয়। জব কার্ড পরিকল্পনা করতে বা অগ্রগতি দেখতে চাইলে এই ট্যাব বন্ধ করে ডেস্কে Production → Job cards খুলুন।',
        'This is the operator screen — the kiosk beside the machine, where output, downtime and waste are booked against a running job card. Planning a job card or reading its progress instead? Close this tab and use Production → Job cards at your desk.',
    ],
    pin_help: [
        'ব্যাজ নম্বর ও পিন অ্যাডমিনিস্ট্রেটর দেন। ভুলে গেলে সুপারভাইজারকে বলুন।',
        'Badge number and PIN are issued by an administrator. If you have forgotten yours, ask your supervisor.',
    ],
    machine_help: [
        'এই মেশিনের কাজ, আর এর গ্রুপের যে কাজ এখনো কোনো মেশিনে দেওয়া হয়নি, সেগুলো দেখাবে। ইউনিটের সব মেশিন দেখতে "যেকোনো" রাখুন। শেষবার যে মেশিন বেছেছিলেন সেটি মনে রাখা হয়।',
        'Shows the work for this machine, and work for its group that has not been given to a machine yet. Leave on "any" to see every machine in your unit. The machine you chose last time is remembered.',
    ],
    no_badge: ['ব্যাজ নেই? আপনি {name} হিসেবে সাইন ইন আছেন', 'No badge? You are already signed in as {name}'],
    continue_as: [
        '{name} হিসেবে চালিয়ে যান। যা বুক করবেন তা এই নামে রেকর্ড হবে।',
        'Continue as {name}. Anything booked will be recorded under that name.',
    ],
    screen_unreachable: [
        'স্ক্রিন খোলা যায়নি — সংযোগ নেই, আর এটি এই ডিভাইসে সেভ করা নেই। যা বুক করেছেন তা নিরাপদ আছে, সংযোগ ফিরলে পাঠানো হবে।',
        'That screen could not be opened — no connection, and it is not saved on this device. Work you have already booked is safe and will be sent when the connection is back.',
    ],
    no_saved_queue: [
        'সংযোগ নেই, আর এই ডিভাইসে কাজের তালিকা সেভ করা নেই। সংযোগ দিন, অথবা সুপারভাইজারের কাছে জব কার্ড চান।',
        'No connection, and no saved work queue on this device. Reconnect, or ask your supervisor for the job card.',
    ],
    queue_failed: ['কাজের তালিকা আসেনি। সুপারভাইজারকে ডাকুন।', 'The work queue could not be loaded. Call your supervisor.'],
    not_sent_operator: [
        'নিচের রেকর্ডগুলো সার্ভারে পৌঁছায়নি। এগুলো হারায়নি — এই ডিভাইসে আছে। সুপারভাইজারকে এই স্ক্রিন দেখান।',
        'These records did not reach the server. They are not lost — they are kept on this device. Show this screen to your supervisor.',
    ],
    running_hint: ['এই ধাপ চলছে। আউটপুট লিখতে OUTPUT চাপুন।', 'This step is running. Press OUTPUT to book what was made.'],
    queue_empty_why: [
        'এখন {machine}-এর জন্য কোনো কাজ দেওয়া নেই, অথবা জব কার্ড এখনো ছাড়া হয়নি, অথবা আগের ধাপ শেষ হয়নি। সুপারভাইজারকে প্ল্যানিং বোর্ড দেখতে বলুন।',
        'Either nothing is scheduled for {machine} right now, the job cards for it are not released yet, or the step before this one has not finished. Ask your supervisor to check the planning board.',
    ],
    saved_list: [
        '{time}-এর সংরক্ষিত তালিকা — এখনকার নয়। সংযোগ না ফেরা পর্যন্ত নতুন বা বাতিল কাজ দেখা যাবে না।',
        'Saved list from {time} — not live. New or cancelled work will not show until the connection returns.',
    ],
    end_blocked_pending: [
        '{n}টি রেকর্ড এখনো পাঠানো হয়নি — এখন শিফট শেষ করা যাবে না। সংযোগ ফিরলে আবার চেষ্টা করুন।',
        '{n} record(s) not sent yet — the shift cannot be ended. Try again when the connection is back.',
    ],
    end_blocked_offline: [
        'সংযোগ নেই — সংযোগ ফিরলে শিফট শেষ করুন।',
        'No connection — end the shift when the connection is back.',
    ],
    not_sent_explain: [
        'নিচের রেকর্ডগুলো সার্ভারে পৌঁছায়নি। এগুলো হারায়নি — এই ডিভাইসে আছে। সুপারভাইজার ডেস্ক থেকে জব কার্ডে হাতে লিখে দেবেন, তারপর এখান থেকে সরাবেন।',
        'These records did not reach the server. They are not lost — they are kept on this device. A supervisor books each one by hand on the job card at the desk, then removes it from this list.',
    ],
    not_sent_empty: ['সব রেকর্ড পাঠানো হয়েছে।', 'Every record has been sent.'],
    dismiss_confirm: [
        'সুপারভাইজার কি এটি ডেস্ক থেকে লিখে নিয়েছেন? সরানোর পর এটি আর ফেরত আনা যাবে না।',
        'Has a supervisor booked this at the desk? Once removed it cannot be brought back.',
    ],
    finish_question: ['এই ধাপ শেষ করবেন?', 'Finish this step?'],
    finish_consequence: [
        'শেষ করার পর এই টার্মিনাল থেকে এই ধাপে আর কিছু বুক করা যাবে না।',
        'After this, nothing more can be booked on this step from the terminal.',
    ],
    finish_no: ['না, ফিরে যান', 'NO, GO BACK'],
    finish_yes: ['হ্যাঁ, শেষ', 'YES, FINISH'],
    nothing_booked: ['কিছু রেকর্ড হয়নি। কেন, লিখুন।', 'Nothing was booked against this step. Say why.'],
    reason: ['কারণ', 'Reason'],
    waste_what: ['নষ্টের কারণ', 'What was the waste?'],
    choose_reason: ['কারণ', 'reason'],
    minutes: ['মিনিট', 'minutes'],
    choose_shift: ['শিফট', 'shift'],
    allowance: ['এই ধাপে আর সর্বোচ্চ {qty} {unit} বুক করা যাবে', 'At most {qty} {unit} more can be booked on this step'],
    why_more: ['পরিকল্পনার ({qty} {unit}) চেয়ে বেশি কেন?', 'Why more than the planned {qty} {unit}?'],

    // --- Refusals, by the code the server sends (OperationRefused) --------------------------
    'error.output_exceeds_input': [
        'আউটপুট ({output}) ইনপুটের ({input}) চেয়ে বেশি। আগে ইনপুট লিখুন।',
        'Output ({output}) is more than the input ({input}). Enter the input first.',
    ],
    'error.input_exceeds_plan': [
        'ইনপুট ({input}) পরিকল্পনার ({ceiling}) চেয়ে বেশি। সংখ্যা আবার দেখুন, অথবা কারণ লিখুন।',
        'Input ({input}) is more than planned ({ceiling}). Check the figure, or give a reason.',
    ],
    'error.output_exceeds_ceiling': [
        'মোট উৎপাদন ({produced}) এই কাজের সর্বোচ্চ সীমা ({ceiling}) ছাড়িয়ে যাবে।',
        'Total output ({produced}) would pass this job\'s limit ({ceiling}).',
    ],
    'error.job_card_not_released': [
        'এই জব কার্ড এখনো ছাড়া হয়নি। সুপারভাইজারকে জানান।',
        'This job card has not been released yet. Tell your supervisor.',
    ],
    'error.step_not_open': [
        'এই ধাপ এখন খোলা নেই। যে ধাপ চলছে সেটিতে লিখুন।',
        'This step is not open. Book against the step that is running.',
    ],
    'error.earlier_step_unfinished': [
        'আগের ধাপ ({blocker}) এখনো শেষ হয়নি। আগে সেটি শেষ করুন।',
        'The step before ({blocker}) is not finished. Finish it first.',
    ],
    'error.inspection_needed': [
        'আগের ধাপের কিউসি পরীক্ষা বাকি। কিউসিকে জানান।',
        'An earlier step is waiting for a QC inspection. Tell QC.',
    ],
    'error.input_exceeds_previous_output': [
        'আগের ধাপে ({previous}) মাত্র {available} তৈরি হয়েছে। আগে সেই ধাপের আউটপুট লিখুন।',
        'The step before ({previous}) has made only {available}. Book that step\'s output first.',
    ],
    'error.waste_reason_needed': ['নষ্টের কারণ বেছে নিন।', 'Choose what the waste was.'],
    'error.nothing_booked': ['এই ধাপে কিছু রেকর্ড হয়নি।', 'Nothing has been booked on this step.'],
    'error.no_machine': [
        'এই ধাপে এখনো মেশিন দেওয়া হয়নি। আগে শুরু করুন।',
        'This step has no machine yet. Start it first.',
    ],
    'error.unknown': ['সার্ভার এটি নেয়নি। সুপারভাইজারকে জানান।', 'The server did not accept this. Tell your supervisor.'],
};

export const WASTE_TYPES = ['setup', 'shade', 'weave_defect', 'print_defect', 'cutting', 'edge_trim', 'damaged', 'expired', 'other'];

/** "মিটার · m" — the unit beside a figure. An unknown unit is shown as it came. */
export function unitLabel(unit) {
    return LINES[`unit_${unit}`] ? label(`unit_${unit}`) : (unit ?? '');
}

const ENGLISH_KEY = 'octa.floor.english';

function stored() {
    try {
        return localStorage.getItem(ENGLISH_KEY) === '1';
    } catch {
        return false;
    }
}

/** Whether long guidance is shown in English. One switch for the whole terminal, per device. */
export const showEnglish = ref(typeof localStorage === 'undefined' ? false : stored());

export function toggleEnglish() {
    showEnglish.value = !showEnglish.value;

    try {
        localStorage.setItem(ENGLISH_KEY, showEnglish.value ? '1' : '0');
    } catch {
        // Storage is blocked: the choice lasts until the page is reloaded.
    }
}

function fill(text, params) {
    return text.replace(/\{(\w+)\}/g, (match, name) => {
        const value = params[name];

        if (value === undefined || value === null) return match;

        // A figure from the server is grouped like every other figure on the terminal.
        return typeof value === 'number' ? value.toLocaleString() : value;
    });
}

function pair(key) {
    const line = LINES[key];

    // A missing key is shown as itself, loudly, so it is noticed and added — never as a blank.
    return line ?? [`[${key}]`, `[${key}]`];
}

/** Both languages on one line — for a label a few words long. */
export function label(key, params = {}) {
    const [bangla, english] = pair(key);

    return `${fill(bangla, params)} · ${fill(english, params)}`;
}

/** One language — Bangla unless the terminal has been switched — for a sentence or more. */
export function guide(key, params = {}) {
    const [bangla, english] = pair(key);

    return fill(showEnglish.value ? english : bangla, params);
}

/** The Bangla half alone, for use in a pair built by hand. */
export function bangla(key, params = {}) {
    return fill(pair(key)[0], params);
}

/** The English half alone. */
export function english(key, params = {}) {
    return fill(pair(key)[1], params);
}

/**
 * What to tell the operator about a refused booking.
 *
 * @param {{code?: string, params?: object, message?: string}} result  as returned by the queue's `send`
 */
export function refusal(result) {
    const key = `error.${result?.code ?? 'unknown'}`;

    return LINES[key] ? label(key, result?.params ?? {}) : label('error.unknown');
}

/** True when the dictionary has this key — used by its own test. */
export function has(key) {
    return key in LINES;
}

export function keys() {
    return Object.keys(LINES);
}
