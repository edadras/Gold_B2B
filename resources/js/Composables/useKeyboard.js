/**
 * The keyboard layer — doc §1.3.
 *
 *   B / خ       buy form           S / ف       sell form
 *   Esc         clear the form     Enter       submit (with confirmation)
 *   Ctrl+Enter  submit unconfirmed ↑ / ↓       price ± one tick
 *   Shift+↑/↓   price ± ten ticks  Ctrl+A      cancel all (with confirmation)
 *   1..9        select instrument  /           search
 *   ?           this help
 *
 * Two rules make the difference between a professional tool and an annoyance:
 *
 *   1. **Typing never triggers a shortcut.** While focus is in an input, a
 *      textarea, a select or anything contenteditable, only Escape and the
 *      modified combinations reach the handlers. A trader typing a quantity
 *      types "300" and gets 300, not three instrument switches.
 *   2. **Ctrl+A is intercepted, not shadowed.** The browser's select-all is
 *      suppressed only outside a text field, so Ctrl+A still selects text where
 *      a user expects it to.
 *
 * @module Composables/useKeyboard
 */

import { onBeforeUnmount, onMounted } from 'vue';

const EDITABLE = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

export function isTypingIn(target) {
    if (!target || !target.tagName) {
        return false;
    }
    return EDITABLE.has(target.tagName) || target.isContentEditable === true;
}

/**
 * Persian keyboard equivalents. A trader on a Persian layout pressing the key
 * physically labelled B emits «خ»; without this the shortcuts would only work
 * for people who switch layouts to trade.
 */
const PERSIAN_ALIASES = {
    'خ': 'b',
    'ف': 's',
    'ذ': '?',
};

export function normaliseKey(event) {
    const key = event.key;

    if (PERSIAN_ALIASES[key]) {
        return PERSIAN_ALIASES[key];
    }

    return key.length === 1 ? key.toLowerCase() : key;
}

/**
 * @param {Record<string, (event: KeyboardEvent) => void>} bindings
 *   keys are descriptors: 'b', 's', 'Escape', 'ctrl+a', 'shift+ArrowUp', 'digit'
 */
export function useKeyboard(bindings) {
    const handler = (event) => {
        const typing = isTypingIn(event.target);
        const key = normaliseKey(event);

        const parts = [];
        if (event.ctrlKey || event.metaKey) {
            parts.push('ctrl');
        }
        if (event.shiftKey && key.startsWith('Arrow')) {
            parts.push('shift');
        }
        parts.push(key);
        const descriptor = parts.join('+');

        // Digits are one binding, not nine.
        const isDigit = /^[1-9]$/.test(key) && !event.ctrlKey && !event.metaKey;

        const candidate = bindings[descriptor]
            ?? (isDigit ? bindings.digit : undefined);

        if (candidate === undefined) {
            return;
        }

        // Rule 1: while typing, only Escape and modified chords get through.
        const modified = event.ctrlKey || event.metaKey;
        if (typing && key !== 'Escape' && !modified) {
            return;
        }

        // Rule 2: leave the browser's Ctrl+A alone inside a text field.
        if (descriptor === 'ctrl+a' && typing) {
            return;
        }

        event.preventDefault();
        candidate(event, isDigit ? Number(key) : undefined);
    };

    onMounted(() => window.addEventListener('keydown', handler));
    onBeforeUnmount(() => window.removeEventListener('keydown', handler));

    return { handler };
}

/** The rows rendered by the `?` overlay. */
export const SHORTCUTS = [
    { keys: 'B / خ', label: 'فرم خرید' },
    { keys: 'S / ف', label: 'فرم فروش' },
    { keys: 'Esc', label: 'پاک کردن فرم' },
    { keys: 'Enter', label: 'ثبت سفارش (با تأیید)' },
    { keys: 'Ctrl+Enter', label: 'ثبت بدون تأیید' },
    { keys: '↑ / ↓', label: 'تغییر قیمت به اندازه یک tick' },
    { keys: 'Shift+↑ / ↓', label: 'تغییر قیمت ۱۰ tick' },
    { keys: 'Ctrl+A', label: 'لغو همه سفارش‌ها (با تأیید)' },
    { keys: '۱..۹', label: 'انتخاب ابزار' },
    { keys: '/', label: 'جستجو' },
    { keys: '?', label: 'راهنمای شورتکات' },
];
