<template>
    <div class="data-table" dir="rtl">
        <div class="data-table__toolbar">
            <div class="data-table__title">
                <slot name="title">{{ title }}</slot>
            </div>

            <input
                v-if="searchable"
                ref="searchField"
                v-model="search"
                class="input input--search"
                type="search"
                :placeholder="searchPlaceholder"
                @input="onSearchInput"
            >

            <div v-if="filters.length" class="data-table__filters">
                <label v-for="filter in filters" :key="filter.key" class="filter">
                    <span class="filter__label">{{ filter.label }}</span>
                    <select
                        v-if="filter.options"
                        class="input input--sm"
                        :value="filterValues[filter.key] ?? ''"
                        @change="setFilter(filter.key, $event.target.value)"
                    >
                        <option value="">همه</option>
                        <option v-for="option in filter.options" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <input
                        v-else
                        class="input input--sm"
                        :type="filter.type || 'text'"
                        :value="filterValues[filter.key] ?? ''"
                        @change="setFilter(filter.key, $event.target.value)"
                    >
                </label>
            </div>

            <div class="data-table__spacer" />

            <StaleBadge v-if="ageMs !== null" :age-ms="ageMs" :stale-after-ms="staleAfterMs" />

            <button v-if="exportPath" class="btn btn--ghost" type="button" @click="requestExport">
                خروجی
            </button>

            <button class="btn btn--ghost" type="button" :disabled="loading" @click="reload">
                <span v-if="loading">…</span><span v-else>تازه‌سازی</span>
            </button>
        </div>

        <div class="data-table__scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="[alignClass(column), { 'is-sortable': column.sortable }]"
                            @click="column.sortable && toggleSort(column.key)"
                        >
                            {{ column.label }}
                            <span v-if="column.sortable" class="sort-icon">{{ sortIcon(column.key) }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-if="loading && rows.length === 0">
                        <td :colspan="columns.length" class="table__empty">در حال بارگذاری…</td>
                    </tr>
                    <tr v-else-if="rows.length === 0">
                        <td :colspan="columns.length" class="table__empty">{{ emptyText }}</td>
                    </tr>

                    <tr
                        v-for="(row, index) in rows"
                        v-else
                        :key="rowKey(row, index)"
                        :class="rowClass ? rowClass(row) : null"
                        @click="$emit('rowClick', row)"
                    >
                        <td v-for="column in columns" :key="column.key" :class="alignClass(column)">
                            <slot :name="`cell:${column.key}`" :row="row" :value="valueOf(row, column)">
                                <WeightCell
                                    v-if="column.type === 'weight'"
                                    :mg="valueOf(row, column)"
                                    :show-unit="column.unit === true"
                                />
                                <MoneyCell
                                    v-else-if="column.type === 'money'"
                                    :rial="valueOf(row, column)"
                                    :compact="column.compact === true"
                                />
                                <JalaliCell
                                    v-else-if="column.type === 'date'"
                                    :iso="valueOf(row, column)"
                                    :with-time="column.withTime !== false"
                                />
                                <StatusBadge
                                    v-else-if="column.type === 'status'"
                                    :status="valueOf(row, column)"
                                    :map="column.statusMap || {}"
                                />
                                <NumCell v-else-if="column.type === 'number'" :value="valueOf(row, column)" />
                                <span v-else>{{ valueOf(row, column) ?? '—' }}</span>
                            </slot>
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="showTotals && rows.length">
                    <tr>
                        <td v-for="(column, index) in columns" :key="column.key" :class="alignClass(column)">
                            <span v-if="index === 0" class="table__total-label">جمع</span>
                            <WeightCell
                                v-else-if="column.total && column.type === 'weight'"
                                :mg="totals[column.key]"
                            />
                            <MoneyCell
                                v-else-if="column.total && column.type === 'money'"
                                :rial="totals[column.key]"
                            />
                            <NumCell v-else-if="column.total" :value="totals[column.key]" />
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="data-table__footer">
            <span class="muted">{{ rows.length }} ردیف</span>
            <div class="data-table__spacer" />
            <button class="btn btn--ghost" type="button" :disabled="!links.prev || loading" @click="go('prev')">
                قبلی
            </button>
            <button class="btn btn--ghost" type="button" :disabled="!links.next || loading" @click="go('next')">
                بعدی
            </button>
        </div>
    </div>
</template>

<script setup>
/**
 * The standard data table — doc §1.4.
 *
 * Sortable and filterable, with the four specialised cell renderers the doc
 * names (weight, money, Jalali date, status badge), a totals row and cursor
 * pagination.
 *
 * TOTALS COME FROM THE SERVER WHEN THE SERVER OFFERS THEM. A total summed over
 * the rows on screen is a total for *this page*, which on a cursor-paginated
 * ledger is a different number from the one the member expects and is the kind
 * of quietly-wrong figure that ends up in a dispute. `meta.totals` wins; the
 * page-local sum is a labelled fallback.
 *
 * Cursor pagination, not offset: §1.8. The cursor is opaque and is round-
 * tripped from `links.next` exactly as received.
 */
import { computed, onMounted, ref, watch } from 'vue';

import JalaliCell from './JalaliCell.vue';
import MoneyCell from './MoneyCell.vue';
import NumCell from './NumCell.vue';
import StatusBadge from './StatusBadge.vue';
import WeightCell from './WeightCell.vue';
import StaleBadge from '../Common/StaleBadge.vue';
import { uuid } from '../../lib/api.js';
import { notify, useApi } from '../../Stores/panel.js';

const props = defineProps({
    /** API path, e.g. '/orders'. */
    endpoint: { type: String, required: true },
    /** @type {Array<{key:string,label:string,type?:string,sortable?:boolean,align?:string,total?:boolean}>} */
    columns: { type: Array, required: true },
    title: { type: String, default: '' },
    filters: { type: Array, default: () => [] },
    baseQuery: { type: Object, default: () => ({}) },
    defaultSort: { type: String, default: null },
    searchable: { type: Boolean, default: true },
    searchPlaceholder: { type: String, default: 'جستجو…' },
    emptyText: { type: String, default: 'رکوردی یافت نشد.' },
    showTotals: { type: Boolean, default: false },
    limit: { type: Number, default: 50 },
    exportPath: { type: String, default: null },
    rowClass: { type: Function, default: null },
    /** Milliseconds since the data was refreshed; null hides the badge. */
    ageMs: { type: Number, default: null },
    staleAfterMs: { type: Number, default: 30000 },
});

const emit = defineEmits(['rowClick', 'loaded']);

const api = useApi();

const rows = ref([]);
const links = ref({ next: null, prev: null });
const meta = ref({});
const loading = ref(false);
const search = ref('');
const sort = ref(props.defaultSort);
const cursor = ref(null);
const filterValues = ref({});
const searchField = ref(null);

let searchTimer = null;

const alignClass = (column) => `align-${column.align || (isNumeric(column) ? 'end' : 'start')}`;

function isNumeric(column) {
    return ['weight', 'money', 'number'].includes(column.type);
}

function valueOf(row, column) {
    if (typeof column.value === 'function') {
        return column.value(row);
    }
    return column.key.split('.').reduce((acc, part) => (acc === null || acc === undefined ? acc : acc[part]), row);
}

function rowKey(row, index) {
    return row.id ?? row.code ?? index;
}

const totals = computed(() => {
    const serverTotals = meta.value.totals;
    if (serverTotals && typeof serverTotals === 'object') {
        return serverTotals;
    }

    const sums = {};
    for (const column of props.columns) {
        if (!column.total) {
            continue;
        }
        let sum = 0n;
        for (const row of rows.value) {
            const value = valueOf(row, column);
            if (value !== null && value !== undefined && value !== '') {
                sum += BigInt(value);
            }
        }
        sums[column.key] = sum.toString();
    }
    return sums;
});

function sortIcon(key) {
    if (sort.value === key) {
        return '▲';
    }
    if (sort.value === `-${key}`) {
        return '▼';
    }
    return '⇅';
}

function toggleSort(key) {
    if (sort.value === key) {
        sort.value = `-${key}`;
    } else if (sort.value === `-${key}`) {
        sort.value = null;
    } else {
        sort.value = key;
    }
    cursor.value = null;
    void load();
}

function setFilter(key, value) {
    if (value === '') {
        delete filterValues.value[key];
    } else {
        filterValues.value[key] = value;
    }
    cursor.value = null;
    void load();
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        cursor.value = null;
        void load();
    }, 300);
}

/** Pull the opaque cursor back out of the link the server handed us. */
function cursorFrom(link) {
    if (!link) {
        return null;
    }
    const query = link.includes('?') ? link.slice(link.indexOf('?')) : '';
    return new URLSearchParams(query).get('cursor');
}

function go(direction) {
    cursor.value = cursorFrom(links.value[direction]);
    void load();
}

async function load() {
    loading.value = true;
    try {
        const response = await api.get(props.endpoint, {
            ...props.baseQuery,
            filter: { ...(props.baseQuery.filter || {}), ...filterValues.value },
            q: search.value || null,
            sort: sort.value,
            limit: props.limit,
            cursor: cursor.value,
        });

        rows.value = Array.isArray(response.data) ? response.data : [];
        links.value = response.links || { next: null, prev: null };
        meta.value = response.meta || {};
        emit('loaded', { rows: rows.value, meta: meta.value });
    } catch (error) {
        // 401 is handled globally by the API client's redirect; anything else
        // is worth telling the user about rather than showing an empty table.
        if (error.status !== 401) {
            notify(error.message || 'خطا در دریافت اطلاعات', 'error');
        }
    } finally {
        loading.value = false;
    }
}

async function requestExport() {
    try {
        await api.post('/reports/export', {
            report: props.exportPath,
            format: 'XLSX',
            filters: { ...filterValues.value },
        }, { idempotencyKey: uuid() });
        notify('درخواست خروجی ثبت شد؛ پس از آماده شدن اطلاع داده می‌شود.', 'info');
    } catch (error) {
        notify(error.message || 'ثبت درخواست خروجی ناموفق بود', 'error');
    }
}

function reload() {
    cursor.value = null;
    return load();
}

function focusSearch() {
    if (searchField.value) {
        searchField.value.focus();
    }
}

watch(() => props.endpoint, () => reload());
watch(() => props.baseQuery, () => reload(), { deep: true });

onMounted(() => load());

defineExpose({ reload, focusSearch, rows });
</script>
