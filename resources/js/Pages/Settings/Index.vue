<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>تنظیمات</h1>

            <div class="report-tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="report-tabs__btn"
                    :class="{ 'is-active': active === tab.key }"
                    type="button"
                    @click="active = tab.key"
                >{{ tab.label }}</button>
            </div>
        </header>

        <NotificationPreferences v-if="active === 'notifications'" />
        <WebhookManager v-else-if="active === 'webhooks'" />
        <ProfilePanel v-else />
    </div>
</template>

<script setup>
/**
 * Settings — three tabs over three separate APIs.
 *
 * They are tabs rather than one long page because they answer to different
 * owners: notification preferences are USER-scoped (a colleague cannot change
 * yours), webhooks are ORGANISATION-scoped and gated on `user.manage`, and the
 * profile mixes the two. Stacking them would imply a single "my settings" scope
 * that does not exist.
 *
 * Each tab is mounted only while it is open, so opening the settings screen
 * does not fire six requests for panels nobody looked at — and the webhook tab
 * in particular is a credential surface that should not be fetched by accident.
 *
 * API KEYS are not here: the platform exposes no personal access-token
 * endpoints, so there is nothing to manage. See ProfilePanel.
 */
import { ref } from 'vue';

import NotificationPreferences from '../../Components/Settings/NotificationPreferences.vue';
import ProfilePanel from '../../Components/Settings/ProfilePanel.vue';
import WebhookManager from '../../Components/Settings/WebhookManager.vue';

const tabs = [
    { key: 'profile', label: 'حساب و سازمان' },
    { key: 'notifications', label: 'اعلان‌ها' },
    { key: 'webhooks', label: 'Webhookها' },
];

const active = ref('profile');
</script>
