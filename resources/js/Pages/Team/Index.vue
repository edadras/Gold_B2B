<template>
    <div class="page" dir="rtl">
        <header class="page__header">
            <h1>کاربران</h1>
            <button class="btn btn--primary btn--sm" type="button" @click="inviting = ! inviting">
                {{ inviting ? 'بستن فرم' : 'دعوت همکار' }}
            </button>
        </header>

        <section v-if="inviting" class="panel-card">
            <header class="panel-card__header">
                <h2>دعوت همکار جدید</h2>
                <button class="btn btn--ghost btn--sm" type="button" @click="inviting = false">بستن</button>
            </header>

            <label class="field">
                <span class="field__label">نام و نام خانوادگی</span>
                <input v-model="invite.fullName" class="input" type="text" maxlength="191">
                <span v-if="inviteErrors.full_name" class="field__error">{{ inviteErrors.full_name }}</span>
            </label>

            <label class="field">
                <span class="field__label">شماره موبایل</span>
                <input v-model="invite.mobile" class="input num" dir="ltr" type="tel" maxlength="20" placeholder="09121234567">
                <span v-if="inviteErrors.mobile" class="field__error">{{ inviteErrors.mobile }}</span>
            </label>

            <label class="field">
                <span class="field__label">ایمیل (اختیاری)</span>
                <input v-model="invite.email" class="input" dir="ltr" type="email" maxlength="191">
                <span v-if="inviteErrors.email" class="field__error">{{ inviteErrors.email }}</span>
            </label>

            <fieldset class="field">
                <legend class="field__label">نقش‌ها</legend>
                <label v-for="role in roles" :key="role.value" class="field field--inline">
                    <input
                        type="checkbox"
                        :value="role.value"
                        :checked="invite.roles.includes(role.value)"
                        @change="toggleInviteRole(role.value)"
                    >
                    <span>{{ role.label }}</span>
                </label>
                <span v-if="inviteErrors.roles" class="field__error">{{ inviteErrors.roles }}</span>
            </fieldset>

            <p v-if="inviteError" class="order-form__error">{{ inviteError }}</p>

            <button
                class="btn btn--primary"
                type="button"
                :disabled="! inviteValid || sending"
                @click="sendInvite"
            >ارسال دعوت</button>

            <p v-if="invitationToken" class="page__note">
                کد دعوت:
                <span class="num" dir="ltr">{{ invitationToken }}</span>
                — این کد را به همکار خود بدهید. پس از بستن این پیام دوباره نمایش داده نمی‌شود.
            </p>
        </section>

        <section class="panel-card">
            <header class="panel-card__header">
                <h2>اعضای سازمان</h2>
                <StaleBadge :age-ms="ageMs" :stale-after-ms="staleAfterMs" />
                <button class="btn btn--ghost btn--sm" type="button" @click="reload">تازه‌سازی</button>
            </header>

            <table class="table">
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>موبایل</th>
                        <th>نقش‌ها</th>
                        <th>وضعیت</th>
                        <th>ورود دوعاملی</th>
                        <th>آخرین ورود</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading && users.length === 0">
                        <td colspan="7" class="table__empty">در حال بارگذاری…</td>
                    </tr>
                    <tr v-else-if="users.length === 0">
                        <td colspan="7" class="table__empty">کاربری ثبت نشده است.</td>
                    </tr>
                    <tr
                        v-for="member in users"
                        v-else
                        :key="member.id"
                        :class="member.status === 'DISABLED' ? 'row--danger' : null"
                    >
                        <td>{{ member.full_name }}</td>
                        <td class="num" dir="ltr">{{ member.mobile }}</td>
                        <td>{{ (member.role_labels || member.roles || []).join('، ') }}</td>
                        <td><StatusBadge :status="member.status" :map="USER_STATUS" /></td>
                        <td>{{ member.two_factor_enabled ? 'فعال' : 'غیرفعال' }}</td>
                        <td><JalaliCell :iso="member.last_login_at" /></td>
                        <td>
                            <div class="row-actions">
                                <button
                                    class="btn btn--sm btn--ghost"
                                    type="button"
                                    @click="startEditingRoles(member)"
                                >نقش‌ها</button>
                                <button
                                    v-if="member.id !== myUserId && member.status !== 'DISABLED'"
                                    class="btn btn--sm btn--ghost"
                                    type="button"
                                    @click="deactivating = member"
                                >غیرفعال‌سازی</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Role editor -->
        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <div class="modal" role="dialog" aria-modal="true" dir="rtl">
                <h2 class="modal__title">نقش‌های {{ editing.full_name }}</h2>
                <div class="modal__body">
                    <label v-for="role in roles" :key="role.value" class="field field--inline">
                        <input
                            type="checkbox"
                            :value="role.value"
                            :checked="editingRoles.includes(role.value)"
                            @change="toggleEditingRole(role.value)"
                        >
                        <span>{{ role.label }}</span>
                    </label>
                    <p v-if="editing.id === myUserId" class="page__note">
                        در حال ویرایش نقش‌های حساب خودتان هستید؛ برداشتن نقش مدیریتی می‌تواند
                        دسترسی شما را از بین ببرد.
                    </p>
                    <p v-if="roleError" class="order-form__error">{{ roleError }}</p>
                </div>
                <div class="modal__actions">
                    <button
                        class="btn btn--primary"
                        type="button"
                        :disabled="editingRoles.length === 0 || savingRoles"
                        @click="saveRoles"
                    >ذخیره</button>
                    <button class="btn btn--ghost" type="button" @click="editing = null">انصراف</button>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :open="deactivating !== null"
            title="غیرفعال‌سازی کاربر"
            confirm-label="غیرفعال کن"
            @confirm="deactivate"
            @cancel="deactivating = null"
        >
            <p v-if="deactivating">
                حساب «{{ deactivating.full_name }}» غیرفعال می‌شود و همه نشست‌های فعال او بسته
                می‌شود. سوابق و ثبت‌های او در دفتر کل دست‌نخورده باقی می‌ماند.
            </p>
        </ConfirmDialog>
    </div>
</template>

<script setup>
/**
 * Team — `/organization/users`, §2.2.
 *
 * DEACTIVATION, NEVER DELETION. `DELETE /organization/users/{id}` sets the user
 * DISABLED and revokes their sessions; it does not remove the row, because
 * audit entries and ledger rows reference the user id and must keep resolving.
 * The confirmation says so, so nobody presses it expecting the person to vanish
 * from the history.
 *
 * THE SELF-DEACTIVATION BUTTON IS ABSENT rather than disabled: the server
 * answers `OPERATION_NOT_PERMITTED` with `cannot_disable_self`, and a button
 * that exists only to produce an error is a worse explanation than no button.
 * Role editing on one's own account IS offered — it is legitimate, occasionally
 * necessary and reversible by a colleague — but it is warned about, because
 * dropping one's own OWNER role is the one way to lock the organisation out.
 *
 * ROLES COME FROM `/meta/enums`, filtered to the organisation roles. The
 * platform roles (PLATFORM_ADMIN and friends) are refused by InviteUserRequest
 * at validation — «a member must never be able to name one» — so offering them
 * would be offering a choice that cannot be made.
 *
 * A plain table rather than `DataTable`: this endpoint is an unpaginated
 * collection with no sort, filter or cursor support, and the §1.4 table's
 * toolbar would advertise four controls that do nothing.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import ConfirmDialog from '../../Components/Common/ConfirmDialog.vue';
import JalaliCell from '../../Components/Data/JalaliCell.vue';
import StaleBadge from '../../Components/Common/StaleBadge.vue';
import StatusBadge from '../../Components/Data/StatusBadge.vue';
import { enumOptions, loadEnums } from '../../Stores/enums.js';
import { notify, panel, useApi } from '../../Stores/panel.js';
import { restrictTo } from '../../lib/enum-catalogue.js';

const api = useApi();

const USER_STATUS = {
    ACTIVE: 'فعال',
    PENDING: 'در انتظار فعال‌سازی',
    INVITED: 'دعوت‌شده',
    SUSPENDED: 'معلق',
    DISABLED: 'غیرفعال',
};

/** Organisation roles only — InviteUserRequest rejects the platform ones. */
const ORGANIZATION_ROLES = ['OWNER', 'MANAGER', 'TRADER', 'ACCOUNTANT', 'TREASURER', 'OPERATOR', 'VIEWER'];

const ROLE_FALLBACK = {
    OWNER: 'مالک',
    MANAGER: 'مدیر',
    TRADER: 'معامله‌گر',
    ACCOUNTANT: 'حسابدار',
    TREASURER: 'خزانه‌دار',
    OPERATOR: 'اپراتور',
    VIEWER: 'ناظر',
};

const users = ref([]);
const loading = ref(false);

const ageMs = ref(Infinity);
const staleAfterMs = panel.realtime.stale_after_ms || 30000;
let lastUpdate = null;
let ageTimer = null;

const inviting = ref(false);
const sending = ref(false);
const inviteError = ref('');
const inviteErrors = ref({});
const invitationToken = ref(null);
const invite = ref({ fullName: '', mobile: '', email: '', roles: ['TRADER'] });

const editing = ref(null);
const editingRoles = ref([]);
const savingRoles = ref(false);
const roleError = ref('');

const deactivating = ref(null);

const myUserId = computed(() => (panel.user ? panel.user.id : null));

const roles = computed(() => {
    const options = restrictTo(enumOptions('role'), ORGANIZATION_ROLES);

    return options.length > 0
        ? options
        : ORGANIZATION_ROLES.map((value) => ({ value, label: ROLE_FALLBACK[value] || value }));
});

const inviteValid = computed(() => invite.value.fullName.trim() !== ''
    && invite.value.mobile.trim() !== ''
    && invite.value.roles.length > 0);

async function reload() {
    loading.value = true;

    try {
        const { data } = await api.get('/organization/users');
        users.value = Array.isArray(data) ? data : [];
        lastUpdate = Date.now();
        ageMs.value = 0;
    } catch (error) {
        // The previous list stays and its age keeps climbing; blanking the
        // table on one failed refresh would look like an empty organisation.
        notify(error.message || 'دریافت فهرست کاربران ناموفق بود.', 'error');
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    void loadEnums();
    void reload();

    ageTimer = setInterval(() => {
        ageMs.value = lastUpdate === null ? Infinity : Date.now() - lastUpdate;
    }, 1000);
});

onBeforeUnmount(() => clearInterval(ageTimer));

function toggleInviteRole(role) {
    invite.value.roles = invite.value.roles.includes(role)
        ? invite.value.roles.filter((value) => value !== role)
        : [...invite.value.roles, role];
}

function toggleEditingRole(role) {
    editingRoles.value = editingRoles.value.includes(role)
        ? editingRoles.value.filter((value) => value !== role)
        : [...editingRoles.value, role];
}

async function sendInvite() {
    if (! inviteValid.value || sending.value) {
        return;
    }

    sending.value = true;
    inviteError.value = '';
    inviteErrors.value = {};
    invitationToken.value = null;

    const body = {
        full_name: invite.value.fullName.trim(),
        mobile: invite.value.mobile.trim(),
        roles: invite.value.roles,
    };

    if (invite.value.email.trim() !== '') {
        body.email = invite.value.email.trim();
    }

    try {
        const { data } = await api.post('/organization/users', body);

        notify('دعوت ارسال شد.', 'success');
        // Null in production, where the token is delivered out of band.
        invitationToken.value = data && data.invitation_token ? data.invitation_token : null;
        invite.value = { fullName: '', mobile: '', email: '', roles: ['TRADER'] };
        void reload();
    } catch (error) {
        inviteError.value = error.message || 'ارسال دعوت ناموفق بود.';
        inviteErrors.value = error.fieldErrors
            ? Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]]))
            : {};
    } finally {
        sending.value = false;
    }
}

function startEditingRoles(member) {
    editing.value = member;
    editingRoles.value = [...(member.roles || [])];
    roleError.value = '';
}

async function saveRoles() {
    if (editing.value === null || savingRoles.value) {
        return;
    }

    savingRoles.value = true;
    roleError.value = '';

    try {
        await api.put(`/organization/users/${editing.value.id}/roles`, { roles: editingRoles.value });
        notify('نقش‌ها به‌روزرسانی شد.', 'success');
        editing.value = null;
        void reload();
    } catch (error) {
        roleError.value = error.message || 'به‌روزرسانی نقش‌ها ناموفق بود.';
    } finally {
        savingRoles.value = false;
    }
}

async function deactivate() {
    const member = deactivating.value;
    deactivating.value = null;

    if (member === null) {
        return;
    }

    try {
        await api.delete(`/organization/users/${member.id}`);
        notify('کاربر غیرفعال شد.', 'success');
    } catch (error) {
        notify(error.message || 'غیرفعال‌سازی ناموفق بود.', 'error');
    } finally {
        void reload();
    }
}
</script>
