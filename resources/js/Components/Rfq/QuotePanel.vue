<template>
    <section v-if="rfq" class="panel-card" dir="rtl">
        <header class="panel-card__header">
            <h2>
                پیشنهادهای {{ rfq.rfq_code }}
                <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
            </h2>
            <button class="btn btn--ghost btn--sm" type="button" @click="emit('close')">بستن</button>
        </header>

        <dl class="kv">
            <div><dt>ابزار</dt><dd dir="ltr">{{ rfq.instrument || '—' }}</dd></div>
            <div><dt>سمت</dt><dd>{{ rfq.side === 'BUY' ? 'خرید' : 'فروش' }}</dd></div>
            <div><dt>مقدار درخواستی</dt><dd><WeightCell :mg="rfq.quantity_mg" show-unit /></dd></div>
            <div><dt>باقی‌مانده</dt><dd><WeightCell :mg="rfq.remaining_mg" show-unit /></dd></div>
            <div><dt>پذیرش جزئی</dt><dd>{{ rfq.allow_partial ? 'بله' : 'خیر' }}</dd></div>
            <div><dt>مهلت</dt><dd><Countdown :expires-at="rfq.expires_at" /></dd></div>
        </dl>

        <table class="table table--dense">
            <thead>
                <tr>
                    <th>رتبه</th>
                    <th>کد</th>
                    <th class="align-end">مقدار</th>
                    <th class="align-end">قیمت</th>
                    <th class="align-end">ارزش</th>
                    <th>اعتبار</th>
                    <th>وضعیت</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-if="ranked.length === 0">
                    <td colspan="8" class="table__empty">هنوز پیشنهادی ثبت نشده است.</td>
                </tr>
                <tr
                    v-for="entry in ranked"
                    v-else
                    :key="entry.quote.id"
                    :class="entry.isBest ? 'row--attention' : null"
                >
                    <td>
                        <span v-if="entry.rank !== null" class="num" dir="ltr">{{ entry.rank }}</span>
                        <span v-else class="muted">—</span>
                    </td>
                    <td dir="ltr">{{ entry.quote.quote_code }}</td>
                    <td class="align-end"><WeightCell :mg="entry.quote.remaining_mg" /></td>
                    <td class="align-end"><MoneyCell :rial="entry.quote.price_per_gram_rial" /></td>
                    <td class="align-end"><MoneyCell :rial="valueOf(entry.quote)" /></td>
                    <td><Countdown :expires-at="entry.quote.valid_until" /></td>
                    <td><StatusBadge :status="entry.quote.status" :map="QUOTE_STATUS" /></td>
                    <td>
                        <div class="row-actions">
                            <button
                                v-if="rfq.is_mine && entry.rank !== null"
                                class="btn btn--sm btn--primary"
                                type="button"
                                @click="askAccept(entry)"
                            >پذیرش</button>
                            <button
                                v-if="entry.quote.is_mine && entry.quote.status === 'PENDING'"
                                class="btn btn--sm btn--ghost"
                                type="button"
                                @click="withdraw(entry.quote)"
                            >پس گرفتن</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Answering somebody else's request -->
        <div v-if="! rfq.is_mine && canQuote" class="panel-card__section">
            <h3>ثبت پیشنهاد</h3>

            <NumberField
                v-model="quote.quantityMg"
                label="مقدار خالص (گرم)"
                :scale="3"
                :error="quoteErrors.quantity_mg"
            />
            <NumberField
                v-model="quote.priceRial"
                label="قیمت (ریال بر گرم خالص)"
                :scale="0"
                :error="quoteErrors.price_per_gram_rial"
            />
            <label class="field">
                <span class="field__label">اعتبار پیشنهاد (دقیقه)</span>
                <select v-model.number="quote.validForMinutes" class="input">
                    <option :value="5">۵ دقیقه</option>
                    <option :value="15">۱۵ دقیقه</option>
                    <option :value="30">۳۰ دقیقه</option>
                    <option :value="60">۱ ساعت</option>
                </select>
            </label>

            <p class="muted">
                با ثبت پیشنهاد، موجودی شما «قفل نرم» می‌شود؛ در Order Book قابل استفاده می‌ماند
                اما در صورت پذیرش باید در دسترس باشد.
            </p>

            <p v-if="quoteError" class="order-form__error">{{ quoteError }}</p>

            <button
                class="btn btn--primary"
                type="button"
                :disabled="! quoteValid || quoting"
                @click="submitQuote"
            >ثبت پیشنهاد</button>
        </div>

        <ConfirmDialog
            :open="acceptingEntry !== null"
            title="پذیرش پیشنهاد"
            confirm-label="پذیرش و ایجاد معامله"
            @confirm="accept"
            @cancel="acceptingEntry = null"
        >
            <dl v-if="acceptingEntry" class="confirm-list">
                <div><dt>پیشنهاد</dt><dd dir="ltr">{{ acceptingEntry.quote.quote_code }}</dd></div>
                <div><dt>قیمت</dt><dd><MoneyCell :rial="acceptingEntry.quote.price_per_gram_rial" /></dd></div>
                <div>
                    <dt>مقدار پذیرفته‌شده</dt>
                    <dd><WeightCell :mg="acceptCoverage.fillableMg.toString()" show-unit /></dd>
                </div>
                <div><dt>ارزش</dt><dd><MoneyCell :rial="acceptValue" show-unit /></dd></div>
            </dl>
            <p v-if="acceptCoverage.isPartial" class="muted">
                این پیشنهاد کل درخواست را پوشش نمی‌دهد و به‌صورت جزئی پذیرفته می‌شود.
            </p>
        </ConfirmDialog>
    </section>
</template>

<script setup>
/**
 * The quotes on one RFQ — §4.7.
 *
 * RANKING IS SIDE-DEPENDENT, and that is the one thing on this screen that has
 * to be right: a requester who is BUYING wants the lowest price and one who is
 * SELLING wants the highest. `rankQuotes` owns that rule and is unit-tested,
 * because inverting it would put a green "best" marker on the worst quote.
 * Withdrawn and expired quotes are ranked `null` and never marked best — the
 * accept button they would carry is one the server refuses.
 *
 * IDEMPOTENCY. Both writes here are 🔑 routes. `/rfq-quotes/{id}/accept` mints
 * its key when the confirm dialog opens; `/rfqs/{id}/quotes` mints one when the
 * panel opens on a given RFQ. A quote soft-locks the quoter's balance and an
 * acceptance converts that to a hard lock, so a double submit of either is a
 * double reservation. `/withdraw` carries no idempotency middleware — the
 * second call finds a withdrawn quote — so it is sent without a key.
 *
 * LIVE DATA. Quotes expire while the panel is open, so they arrive through a
 * `Feed`: the age is on the header, and a failed refresh keeps the last list
 * and lets the age climb rather than blanking a table somebody is reading.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import ConfirmDialog from '../Common/ConfirmDialog.vue';
import Countdown from '../Common/Countdown.vue';
import MoneyCell from '../Data/MoneyCell.vue';
import NumberField from '../Common/NumberField.vue';
import StaleBadge from '../Common/StaleBadge.vue';
import StatusBadge from '../Data/StatusBadge.vue';
import WeightCell from '../Data/WeightCell.vue';
import { Feed, resolveEcho } from '../../lib/feed.js';
import { coverage, notionalValue, rankQuotes, remainingMs } from '../../lib/negotiation.js';
import { notify, panel, useApi } from '../../Stores/panel.js';
import { uuid } from '../../lib/api.js';

const props = defineProps({
    /** The RfqResource whose quotes are shown, or null when closed. */
    rfq: { type: [Object, null], default: null },
});

const emit = defineEmits(['accepted', 'quoted', 'close']);

const api = useApi();

const QUOTE_STATUS = {
    PENDING: 'در انتظار',
    ACCEPTED: 'پذیرفته شده',
    REJECTED: 'رد شده',
    WITHDRAWN: 'پس گرفته شده',
    EXPIRED: 'منقضی',
};

const quotes = ref([]);
const ageMs = ref(Infinity);
const staleAfterMs = panel.realtime.stale_after_ms || 30000;

const acceptingEntry = ref(null);
const acceptKey = ref(null);

const quoting = ref(false);
const quoteError = ref('');
const quoteErrors = ref({});
const quote = ref({ quantityMg: null, priceRial: null, validForMinutes: 15 });
/** §1.10 — one key per RFQ this panel is answering. */
const quoteKey = ref(uuid());

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

watch(() => (props.rfq ? props.rfq.id : null), (id) => {
    stopFeed();

    quotes.value = [];
    ageMs.value = Infinity;
    acceptingEntry.value = null;
    quoteError.value = '';
    quoteErrors.value = {};
    quoteKey.value = uuid();
    quote.value = {
        quantityMg: props.rfq ? BigInt(props.rfq.remaining_mg || props.rfq.quantity_mg || 0) : null,
        priceRial: null,
        validForMinutes: 15,
    };

    if (id === null) {
        return;
    }

    feed = new Feed({
        // Quotes are minutes-scale, not ticks: a two-second poll on a list
        // endpoint would cost far more than the freshness is worth.
        intervalMs: Math.max((panel.realtime.poll_interval_ms || 2000) * 5, 10000),
        staleAfterMs,
        echo: resolveEcho(panel.realtime),
        channel: `organization.${panel.organization ? panel.organization.id : 0}`,
        events: ['.rfq.quoted'],
        load: async () => (await api.get(`/rfqs/${id}/quotes`)).data,
    });

    feed.subscribe((source) => {
        if (Array.isArray(source.data)) {
            quotes.value = source.data;
        }
        ageMs.value = source.ageMs;
    });

    feed.start();

    ageTimer = setInterval(() => {
        ageMs.value = feed === null ? Infinity : feed.ageMs;
    }, 1000);
}, { immediate: true });

onBeforeUnmount(stopFeed);

const ranked = computed(() => (props.rfq ? rankQuotes(quotes.value, props.rfq.side) : []));

const canQuote = computed(() => props.rfq
    && ['OPEN', 'QUOTED', 'PARTIALLY_ACCEPTED'].includes(props.rfq.status)
    && remainingMs(props.rfq.expires_at) !== 0);

const quoteValid = computed(() => quote.value.quantityMg !== null && BigInt(quote.value.quantityMg) > 0n
    && quote.value.priceRial !== null && BigInt(quote.value.priceRial) > 0n);

function valueOf(row) {
    try {
        return notionalValue(row.remaining_mg ?? 0, row.price_per_gram_rial ?? 0).toString();
    } catch {
        return null;
    }
}

const acceptCoverage = computed(() => coverage(props.rfq, acceptingEntry.value ? acceptingEntry.value.quote : null));

const acceptValue = computed(() => {
    if (acceptingEntry.value === null) {
        return null;
    }
    try {
        return notionalValue(
            acceptCoverage.value.fillableMg,
            acceptingEntry.value.quote.price_per_gram_rial,
        ).toString();
    } catch {
        return null;
    }
});

function askAccept(entry) {
    acceptingEntry.value = entry;
    // §1.10 — the key belongs to this acceptance.
    acceptKey.value = uuid();
}

async function accept() {
    const entry = acceptingEntry.value;

    if (entry === null) {
        return;
    }

    const cover = acceptCoverage.value;
    // Send the quantity only for a partial acceptance; omitting it means "all
    // of this quote", which is what AcceptRfqQuoteRequest documents.
    const body = cover.isPartial ? { quantity_mg: Number(cover.fillableMg) } : {};

    try {
        const { data, replayed } = await api.post(`/rfq-quotes/${entry.quote.id}/accept`, body, {
            idempotencyKey: acceptKey.value,
        });

        notify(
            replayed
                ? 'این پیشنهاد قبلاً پذیرفته شده بود؛ همان معامله بازگردانده شد.'
                : `معامله ${data && data.trade_code ? data.trade_code : ''} ایجاد شد.`,
            'success',
        );

        acceptingEntry.value = null;
        acceptKey.value = null;
        emit('accepted', data);
        void feed?.refresh();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            notify('درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.', 'warn');
            return;
        }

        notify(error.message || 'پذیرش پیشنهاد ناموفق بود.', 'error');
        acceptingEntry.value = null;
        acceptKey.value = null;
        void feed?.refresh();
    }
}

async function submitQuote() {
    if (! quoteValid.value || quoting.value || ! props.rfq) {
        return;
    }

    quoting.value = true;
    quoteError.value = '';
    quoteErrors.value = {};

    try {
        const { replayed } = await api.post(`/rfqs/${props.rfq.id}/quotes`, {
            quantity_mg: Number(quote.value.quantityMg),
            price_per_gram_rial: Number(quote.value.priceRial),
            valid_for_minutes: quote.value.validForMinutes,
        }, { idempotencyKey: quoteKey.value });

        notify(replayed ? 'این پیشنهاد قبلاً ثبت شده بود.' : 'پیشنهاد ثبت شد.', 'success');
        emit('quoted');
        // A submitted quote cannot be edited (§4.7 rule ۳); the next one is a
        // different quote and needs its own key.
        quoteKey.value = uuid();
        void feed?.refresh();
    } catch (error) {
        if (error.code === 'IDEMPOTENCY_IN_PROGRESS') {
            quoteError.value = 'درخواست مشابه در حال پردازش است؛ چند لحظه صبر کنید.';
        } else {
            quoteError.value = error.message || 'ثبت پیشنهاد ناموفق بود.';
            quoteErrors.value = error.fieldErrors
                ? Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]]))
                : {};
            if (error.status === 422) {
                quoteKey.value = uuid();
            }
        }
    } finally {
        quoting.value = false;
    }
}

async function withdraw(row) {
    try {
        await api.post(`/rfq-quotes/${row.id}/withdraw`, {});
        notify('پیشنهاد پس گرفته شد.', 'success');
    } catch (error) {
        notify(error.message || 'پس گرفتن پیشنهاد ناموفق بود.', 'error');
    } finally {
        void feed?.refresh();
    }
}
</script>
