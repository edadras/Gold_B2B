<template>
    <section v-if="caseId !== null" class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>
                پرونده {{ dispute ? dispute.case_number : '' }}
                <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
            </h2>
            <button class="btn btn--ghost btn--sm" type="button" @click="emit('close')">بستن</button>
        </header>

        <p v-if="dispute === null" class="muted">در حال بارگذاری…</p>

        <template v-else>
            <dl class="kv">
                <div><dt>نوع</dt><dd>{{ enumLabel('dispute_type', dispute.dispute_type) }}</dd></div>
                <div><dt>وضعیت</dt><dd><StatusBadge :status="dispute.status" :map="STATUS_LABELS" /></dd></div>
                <div><dt>نقش شما</dt><dd>{{ dispute.my_role === 'CLAIMANT' ? 'شاکی' : 'طرف شکایت' }}</dd></div>
                <div><dt>طلای مورد ادعا</dt><dd><WeightCell :mg="dispute.claim_gold_mg" show-unit /></dd></div>
                <div><dt>مبلغ مورد ادعا</dt><dd><MoneyCell :rial="dispute.claim_rial" show-unit /></dd></div>
                <div><dt>وجه مسدود</dt><dd>{{ dispute.funds_held ? (dispute.hold_released ? 'آزاد شده' : 'مسدود') : 'ندارد' }}</dd></div>
                <div><dt>مهلت پاسخ</dt><dd><JalaliCell :iso="dispute.reply_deadline_at" /></dd></div>
                <div><dt>ثبت</dt><dd><JalaliCell :iso="dispute.opened_at" /></dd></div>
            </dl>

            <p class="dispute__claim">{{ dispute.claim_description }}</p>

            <div v-if="dispute.decision" class="reconciliation reconciliation--ok">
                رأی: {{ dispute.decision_display || dispute.decision }}
                <span v-if="dispute.decision_rationale"> — {{ dispute.decision_rationale }}</span>
            </div>

            <!-- Actions available to this side, in this state -->
            <div class="row-actions">
                <button
                    v-if="canReply"
                    class="btn btn--sm btn--primary"
                    type="button"
                    @click="replying = true"
                >پاسخ / رد ادعا</button>
                <button
                    v-if="canAccept"
                    class="btn btn--sm btn--ghost"
                    type="button"
                    @click="askAccept"
                >پذیرش کامل ادعا ✍️</button>
                <button
                    v-if="canEscalate"
                    class="btn btn--sm btn--ghost"
                    type="button"
                    @click="escalate"
                >ارجاع به میانجی</button>
                <button
                    v-if="canWithdraw"
                    class="btn btn--sm btn--ghost"
                    type="button"
                    @click="withdraw"
                >پس گرفتن شکایت</button>
            </div>

            <!-- Reply — the only endpoint that carries member prose into a case -->
            <div v-if="replying" class="panel-card__section">
                <h3>پاسخ به ادعا</h3>
                <textarea
                    v-model="replyBody"
                    class="input"
                    rows="4"
                    maxlength="5000"
                    placeholder="حداقل ۵ نویسه — دلیل رد یا پذیرش جزئی ادعا"
                />
                <p class="muted">
                    این پاسخ به معنای «ادعا را به این شکل نمی‌پذیرم» است و پرونده را به مرحله
                    مذاکره می‌برد. پذیرش کامل ادعا دکمه جداگانه‌ای دارد.
                </p>
                <p v-if="replyError" class="order-form__error">{{ replyError }}</p>
                <div class="row-actions">
                    <button
                        class="btn btn--sm btn--primary"
                        type="button"
                        :disabled="replyBody.trim().length < 5 || posting"
                        @click="postReply"
                    >ارسال پاسخ</button>
                    <button class="btn btn--sm btn--ghost" type="button" @click="replying = false">انصراف</button>
                </div>
            </div>

            <!-- Transcript: timeline and messages, merged in time order -->
            <h3>سیر پرونده</h3>
            <ol class="timeline">
                <li v-for="item in transcript" :key="item.key" class="timeline__item" :class="`timeline__item--${item.kind}`">
                    <span class="timeline__when num" dir="ltr">{{ jalali(item.at) }}</span>
                    <span class="timeline__what">
                        <strong>{{ item.title }}</strong>
                        <span v-if="item.body" class="timeline__body">{{ item.body }}</span>
                        <span v-if="item.transition" class="muted">{{ item.transition }}</span>
                    </span>
                </li>
                <li v-if="transcript.length === 0" class="muted">رویدادی ثبت نشده است.</li>
            </ol>

            <!-- Evidence -->
            <h3>مدارک</h3>
            <table class="table table--dense">
                <thead>
                    <tr>
                        <th>نوع</th>
                        <th>شرح</th>
                        <th>ثبت‌کننده</th>
                        <th>اثر انگشت فایل</th>
                        <th>زمان</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="evidences.length === 0">
                        <td colspan="5" class="table__empty">مدرکی ثبت نشده است.</td>
                    </tr>
                    <tr v-for="item in evidences" v-else :key="item.id">
                        <td>{{ item.evidence_type_display || enumLabel('evidence_type', item.evidence_type) }}</td>
                        <td>{{ item.description }}</td>
                        <td>
                            <span v-if="item.is_system_generated" class="badge badge--info">سامانه</span>
                            <span v-else class="num" dir="ltr">#{{ item.submitted_by_org_id }}</span>
                        </td>
                        <td class="num" dir="ltr">{{ item.file_hash ? item.file_hash.slice(0, 12) + '…' : '—' }}</td>
                        <td><JalaliCell :iso="item.submitted_at" /></td>
                    </tr>
                </tbody>
            </table>

            <div class="panel-card__section">
                <h3>افزودن مدرک</h3>

                <label class="field">
                    <span class="field__label">نوع مدرک</span>
                    <select v-model="evidence.type" class="input">
                        <option v-for="option in evidenceTypes" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="field">
                    <span class="field__label">شرح</span>
                    <input v-model="evidence.description" class="input" type="text" maxlength="2000">
                </label>

                <label v-if="hashingAvailable" class="field">
                    <span class="field__label">فایل مدرک (برای محاسبه اثر انگشت)</span>
                    <input class="input" type="file" @change="onFile">
                    <span class="field__hint">
                        فایل ارسال نمی‌شود؛ فقط اثر انگشت SHA-256 آن ثبت می‌شود تا بعداً بتوان
                        اثبات کرد همان فایل است.
                    </span>
                </label>

                <p v-if="evidence.fileHash" class="muted num" dir="ltr">{{ evidence.fileHash }}</p>

                <label class="field">
                    <span class="field__label">شناسه سند بارگذاری‌شده (اختیاری)</span>
                    <input v-model.number="evidence.documentId" class="input num" dir="ltr" type="number" min="1">
                </label>

                <p v-if="evidenceError" class="order-form__error">{{ evidenceError }}</p>

                <button
                    class="btn btn--sm btn--primary"
                    type="button"
                    :disabled="evidence.description.trim().length < 3 || filing"
                    @click="fileEvidence"
                >ثبت مدرک</button>
            </div>
        </template>

        <ConfirmDialog
            :open="accepting"
            title="پذیرش کامل ادعا"
            confirm-label="پذیرش ادعا"
            @confirm="acceptClaim"
            @cancel="accepting = false"
        >
            <p>
                پذیرش کامل ادعا به معنای قبول مسئولیت است: مبلغ مسدودشده به نفع طرف مقابل آزاد
                می‌شود و این تصمیم بازگشت‌پذیر نیست.
            </p>
            <dl v-if="dispute" class="confirm-list">
                <div><dt>طلا</dt><dd><WeightCell :mg="dispute.claim_gold_mg" show-unit /></dd></div>
                <div><dt>مبلغ</dt><dd><MoneyCell :rial="dispute.claim_rial" show-unit /></dd></div>
            </dl>
            <p class="muted">این عملیات نیاز به تأیید تراکنش (کد یکبارمصرف) دارد.</p>
        </ConfirmDialog>
    </section>
</template>

<script setup>
/**
 * One dispute case — §13.9's case screen.
 *
 * THE TRANSCRIPT IS TWO SOURCES MERGED. `GET /disputes/{id}` returns a
 * `timeline` (state transitions, with an actor ROLE and never an actor user id)
 * and `messages` (what the parties said). Rendering them as two lists would
 * make the case unreadable, because a reply and the state change it caused are
 * one event to a human; they are merged on `occurred_at`/`created_at` and
 * tagged so a system transition still looks different from a party's words.
 *
 * WHOSE BUTTON IS WHICH. `my_role` and the status decide what is offered:
 * only a respondent replies or accepts, only a claimant withdraws, and neither
 * acts on a closed case. Accepting a claim is `POST /disputes/{id}/accept`,
 * which the routes gate behind BOTH `transaction.sign` and `idempotency` — it
 * concedes money — so it is a separate, confirmed button, and an
 * `AUTH_TRANSACTION_SIGN_REQUIRED` response is reported as the second factor it
 * is rather than as a failure.
 *
 * IDEMPOTENCY. `/accept` mints its key when the confirm dialog opens and keeps
 * it across retries; `/withdraw` mints one per attempt because it is only
 * reachable once. `/reply`, `/escalate` and `/evidences` carry no idempotency
 * middleware and are sent without a key.
 *
 * EVIDENCE FILES. `POST /disputes/{id}/evidences` takes a `document_id` and a
 * `file_hash`, not bytes. The hash is computed in the browser from the member's
 * own copy (see lib/digest.js) — a hash the server derived from its own copy
 * would prove nothing about the artefact a mediator is shown.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import ConfirmDialog from '../Common/ConfirmDialog.vue';
import JalaliCell from '../Data/JalaliCell.vue';
import MoneyCell from '../Data/MoneyCell.vue';
import StaleBadge from '../Common/StaleBadge.vue';
import StatusBadge from '../Data/StatusBadge.vue';
import WeightCell from '../Data/WeightCell.vue';
import { Feed } from '../../lib/feed.js';
import { canDigest, sha256OfFile } from '../../lib/digest.js';
import { enumLabel, enumOptions, loadEnums } from '../../Stores/enums.js';
import { notify, panel, useApi } from '../../Stores/panel.js';
import { restrictTo } from '../../lib/enum-catalogue.js';
import { useFormatting } from '../../Composables/useFormatting.js';
import { uuid } from '../../lib/api.js';

const props = defineProps({
    caseId: { type: [Number, null], default: null },
});

const emit = defineEmits(['changed', 'close']);

const api = useApi();
const { jalali } = useFormatting();

const STATUS_LABELS = {
    OPENED: 'ثبت شده',
    AWAITING_REPLY: 'در انتظار پاسخ',
    ACCEPTED_BY_RESPONDENT: 'پذیرفته‌شده توسط طرف مقابل',
    NEGOTIATION: 'در مذاکره',
    UNDER_MEDIATION: 'در میانجی‌گری',
    AWAITING_EVIDENCE: 'در انتظار مدرک',
    AWAITING_REASSAY: 'در انتظار ری‌گیری ثالث',
    RESOLVED: 'رأی صادر شده',
    EXECUTED: 'اجرا شده',
    WITHDRAWN: 'پس گرفته شده',
};

/**
 * SYSTEM_LOG is excluded, exactly as SubmitEvidenceRequest excludes it: a
 * member-filed "system log" would carry the platform's authority without being
 * from it. Offering it here would produce a field error at best.
 */
const MEMBER_EVIDENCE_TYPES = [
    'DOCUMENT', 'PHOTO', 'VIDEO', 'ASSAY_REPORT', 'BANK_STATEMENT', 'WITNESS_STATEMENT',
];

const dispute = ref(null);
const timeline = ref([]);
const messages = ref([]);
const evidences = ref([]);
const ageMs = ref(Infinity);
const staleAfterMs = panel.realtime.stale_after_ms || 30000;

const replying = ref(false);
const replyBody = ref('');
const replyError = ref('');
const posting = ref(false);

const accepting = ref(false);
const acceptKey = ref(null);

const filing = ref(false);
const evidenceError = ref('');
const evidence = ref({ type: 'DOCUMENT', description: '', documentId: null, fileHash: null });

const hashingAvailable = canDigest();

let feed = null;
let ageTimer = null;

function stopFeed() {
    if (feed !== null) {
        feed.stop();
        feed = null;
    }
    if (ageTimer !== null) {
        clearInterval(ageTimer);
        ageTimer = null;
    }
}

watch(() => props.caseId, (id) => {
    stopFeed();

    dispute.value = null;
    timeline.value = [];
    messages.value = [];
    evidences.value = [];
    ageMs.value = Infinity;
    replying.value = false;
    replyBody.value = '';
    replyError.value = '';
    evidenceError.value = '';
    evidence.value = { type: 'DOCUMENT', description: '', documentId: null, fileHash: null };

    if (id === null) {
        return;
    }

    void loadEnums();

    feed = new Feed({
        // A case moves in hours, not seconds. Slow polling keeps the age
        // honest without hammering an endpoint that loads a whole transcript.
        intervalMs: 20000,
        staleAfterMs,
        load: async () => {
            const [detail, filed] = await Promise.all([
                api.get(`/disputes/${id}`),
                api.get(`/disputes/${id}/evidences`),
            ]);

            return { detail: detail.data, evidences: filed.data };
        },
    });

    feed.subscribe((source) => {
        if (source.data && source.data.detail) {
            dispute.value = source.data.detail.dispute || null;
            timeline.value = Array.isArray(source.data.detail.timeline) ? source.data.detail.timeline : [];
            messages.value = Array.isArray(source.data.detail.messages) ? source.data.detail.messages : [];
            evidences.value = Array.isArray(source.data.evidences) ? source.data.evidences : [];
        }
        ageMs.value = source.ageMs;
    });

    feed.start();

    ageTimer = setInterval(() => {
        ageMs.value = feed === null ? Infinity : feed.ageMs;
    }, 1000);
}, { immediate: true });

onBeforeUnmount(stopFeed);

const evidenceTypes = computed(() => {
    const options = restrictTo(enumOptions('evidence_type'), MEMBER_EVIDENCE_TYPES);
    return options.length > 0 ? options : MEMBER_EVIDENCE_TYPES.map((value) => ({ value, label: value }));
});

const isOpen = computed(() => Boolean(dispute.value && dispute.value.is_open));
const isRespondent = computed(() => Boolean(dispute.value && dispute.value.my_role === 'RESPONDENT'));
const isClaimant = computed(() => Boolean(dispute.value && dispute.value.my_role === 'CLAIMANT'));

const canReply = computed(() => isOpen.value && isRespondent.value && ! replying.value);
const canAccept = computed(() => isOpen.value && isRespondent.value);
const canEscalate = computed(() => isOpen.value
    && ['NEGOTIATION', 'AWAITING_REPLY', 'OPENED'].includes(dispute.value ? dispute.value.status : ''));
const canWithdraw = computed(() => isOpen.value && isClaimant.value);

/** Timeline entries and messages, one list, oldest first. */
const transcript = computed(() => {
    const entries = timeline.value.map((item) => ({
        key: `t-${item.id}`,
        kind: item.actor_type === 'SYSTEM' ? 'system' : 'action',
        at: item.occurred_at,
        title: `${item.actor_type_display || item.actor_type} — ${item.action}`,
        body: item.message,
        transition: item.from_status && item.to_status
            ? `${item.from_status_display || item.from_status} ← ${item.to_status_display || item.to_status}`
            : null,
    }));

    const said = messages.value.map((item) => ({
        key: `m-${item.id}`,
        kind: item.is_proposal ? 'proposal' : 'message',
        at: item.created_at,
        title: item.message_type_display || item.message_type,
        body: item.body,
        transition: null,
    }));

    return [...entries, ...said].sort((a, b) => Date.parse(a.at || 0) - Date.parse(b.at || 0));
});

function refresh() {
    emit('changed');
    return feed === null ? Promise.resolve() : feed.refresh();
}

async function postReply() {
    if (replyBody.value.trim().length < 5 || posting.value) {
        return;
    }

    posting.value = true;
    replyError.value = '';

    try {
        // No idempotency middleware on this route; a repeated reply is a second
        // message, which is a truthful record of what happened.
        await api.post(`/disputes/${props.caseId}/reply`, { message: replyBody.value.trim() });
        notify('پاسخ ثبت شد.', 'success');
        replying.value = false;
        replyBody.value = '';
        void refresh();
    } catch (error) {
        replyError.value = error.message || 'ثبت پاسخ ناموفق بود.';
    } finally {
        posting.value = false;
    }
}

function askAccept() {
    accepting.value = true;
    // §1.10 — the key belongs to this acceptance and survives a retry.
    acceptKey.value = uuid();
}

async function acceptClaim() {
    accepting.value = false;

    try {
        await api.post(`/disputes/${props.caseId}/accept`, {}, { idempotencyKey: acceptKey.value });
        notify('ادعا پذیرفته شد.', 'success');
        acceptKey.value = null;
        void refresh();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            // Keep the key; retrying with it is what resolves this.
            notify('درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.', 'warn');
            return;
        }
        if (error.code === 'AUTH_TRANSACTION_SIGN_REQUIRED') {
            notify('پذیرش ادعا نیاز به تأیید تراکنش دارد. لطفاً کد یکبارمصرف را وارد کنید.', 'warn');
            return;
        }
        notify(error.message || 'پذیرش ادعا ناموفق بود.', 'error');
        acceptKey.value = null;
    }
}

async function escalate() {
    const reason = window.prompt('دلیل ارجاع به میانجی (اختیاری):') ?? '';

    try {
        await api.post(`/disputes/${props.caseId}/escalate`, reason === '' ? {} : { reason });
        notify('درخواست میانجی‌گری ثبت شد.', 'success');
    } catch (error) {
        notify(error.message || 'ارجاع ناموفق بود.', 'error');
    } finally {
        void refresh();
    }
}

async function withdraw() {
    const reason = window.prompt('دلیل پس گرفتن شکایت (اختیاری):') ?? '';

    try {
        await api.post(`/disputes/${props.caseId}/withdraw`, reason === '' ? {} : { reason }, {
            // 🔑 route: a withdrawal releases the hold, and a double submit
            // against a mid-flight release is exactly what the key prevents.
            idempotencyKey: uuid(),
        });
        notify('شکایت پس گرفته شد.', 'success');
    } catch (error) {
        notify(error.message || 'پس گرفتن شکایت ناموفق بود.', 'error');
    } finally {
        void refresh();
    }
}

async function onFile(event) {
    const file = event.target.files && event.target.files[0];

    if (! file) {
        evidence.value.fileHash = null;
        return;
    }

    evidence.value.fileHash = await sha256OfFile(file);

    if (evidence.value.fileHash === null) {
        notify('محاسبه اثر انگشت فایل در این مرورگر ممکن نیست؛ مدرک بدون اثر انگشت ثبت می‌شود.', 'warn');
    }
}

async function fileEvidence() {
    if (evidence.value.description.trim().length < 3 || filing.value) {
        return;
    }

    filing.value = true;
    evidenceError.value = '';

    const body = {
        evidence_type: evidence.value.type,
        description: evidence.value.description.trim(),
    };

    if (evidence.value.documentId) {
        body.document_id = evidence.value.documentId;
    }
    if (evidence.value.fileHash) {
        body.file_hash = evidence.value.fileHash;
    }

    try {
        await api.post(`/disputes/${props.caseId}/evidences`, body);
        notify('مدرک ثبت شد.', 'success');
        evidence.value = { type: 'DOCUMENT', description: '', documentId: null, fileHash: null };
        void refresh();
    } catch (error) {
        evidenceError.value = error.message || 'ثبت مدرک ناموفق بود.';
    } finally {
        filing.value = false;
    }
}
</script>
