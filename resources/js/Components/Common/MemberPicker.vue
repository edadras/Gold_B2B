<template>
    <div class="member-picker" dir="rtl">
        <label class="field">
            <span class="field__label">{{ label }}</span>
            <input
                v-model="term"
                class="input"
                type="search"
                :placeholder="placeholder"
                @input="onInput"
            >
            <span class="field__hint">{{ hint }}</span>
            <span v-if="error" class="field__error">{{ error }}</span>
        </label>

        <ul v-if="results.length" class="member-picker__results">
            <li v-for="member in results" :key="member.organization_id">
                <button
                    class="member-picker__result"
                    :class="{ 'is-chosen': isChosen(member.organization_id) }"
                    type="button"
                    @click="choose(member)"
                >
                    <span class="member-picker__name">{{ member.display_name }}</span>
                    <span class="muted">{{ member.city }}</span>
                    <span class="num" dir="ltr">#{{ member.organization_id }}</span>
                </button>
            </li>
        </ul>

        <p v-else-if="searched && ! searching" class="muted">عضوی با این نام یافت نشد.</p>

        <ul v-if="chosen.length" class="member-picker__chosen">
            <li v-for="member in chosen" :key="member.organization_id" class="chip">
                {{ member.display_name }}
                <span class="num" dir="ltr">#{{ member.organization_id }}</span>
                <button class="chip__remove" type="button" @click="remove(member.organization_id)">×</button>
            </li>
        </ul>
    </div>
</template>

<script setup>
/**
 * Choosing a counterparty — for an OTC offer's recipient and an RFQ's invite
 * list.
 *
 * Backed by `GET /members/search`, which refuses a query shorter than two
 * characters. That floor is a privacy control on the server (a one-character
 * query pages through the whole membership), so the field states it rather than
 * letting the member type one character and read a validation error.
 *
 * The picker emits ORGANISATION IDS, never names: `counterparty_organization_id`
 * and `recipient_organization_ids` are what the two endpoints accept, and a
 * display name is not unique.
 */
import { computed, ref } from 'vue';

import { useApi } from '../../Stores/panel.js';

const props = defineProps({
    modelValue: { type: [Number, Array, null], default: null },
    label: { type: String, default: 'طرف مقابل' },
    placeholder: { type: String, default: 'نام عضو…' },
    multiple: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const api = useApi();

const term = ref('');
const results = ref([]);
const chosen = ref([]);
const searching = ref(false);
const searched = ref(false);

let timer = null;

const hint = computed(() => (term.value.length > 0 && term.value.length < 2
    ? 'برای جستجو حداقل دو نویسه وارد کنید.'
    : 'با نام عضو جستجو کنید.'));

const selectedIds = computed(() => (props.multiple
    ? (Array.isArray(props.modelValue) ? props.modelValue : [])
    : (props.modelValue === null ? [] : [props.modelValue])));

function isChosen(id) {
    return selectedIds.value.includes(id);
}

function onInput() {
    clearTimeout(timer);

    if (term.value.trim().length < 2) {
        results.value = [];
        searched.value = false;
        return;
    }

    timer = setTimeout(() => void search(), 300);
}

async function search() {
    searching.value = true;
    try {
        const { data } = await api.get('/members/search', { q: term.value.trim(), limit: 20 });
        results.value = Array.isArray(data) ? data : [];
    } catch {
        // The API client has already surfaced the failure; an empty result list
        // with the "not found" line is a truthful thing to show either way.
        results.value = [];
    } finally {
        searching.value = false;
        searched.value = true;
    }
}

function choose(member) {
    if (props.multiple) {
        if (isChosen(member.organization_id)) {
            return;
        }
        chosen.value = [...chosen.value, member];
        emit('update:modelValue', [...selectedIds.value, member.organization_id]);
        return;
    }

    chosen.value = [member];
    emit('update:modelValue', member.organization_id);
    results.value = [];
    term.value = member.display_name;
}

function remove(id) {
    chosen.value = chosen.value.filter((member) => member.organization_id !== id);

    if (props.multiple) {
        emit('update:modelValue', selectedIds.value.filter((selected) => selected !== id));
    } else {
        emit('update:modelValue', null);
        term.value = '';
    }
}

function reset() {
    term.value = '';
    results.value = [];
    chosen.value = [];
    searched.value = false;
    emit('update:modelValue', props.multiple ? [] : null);
}

defineExpose({ reset });
</script>
