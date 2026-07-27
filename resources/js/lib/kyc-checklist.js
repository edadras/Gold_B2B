/**
 * Reading `missing_items` from `GET /organization/kyc`.
 *
 * KycSubmissionService::missingItems() emits machine codes, not prose:
 *
 *   document:NATIONAL_CARD_FRONT   a required upload is absent
 *   business_license:missing       no guild licence on file
 *   business_license:expired       there is one, and it has lapsed
 *   bank_account:missing           no account to settle into
 *   signatory:missing              no active authorised signatory
 *   identity:national_id           no national id on file (individual)
 *   identity:legal_id              no legal id on file (legal entity)
 *   identity:registration_no       no registration number (legal entity)
 *
 * The onboarding screen has to say what each one means AND where to fix it,
 * because "bank_account:missing" is not an instruction. Doing that translation
 * here rather than in the template keeps it testable and keeps one Persian
 * string per condition instead of one per place it is shown.
 *
 * UNKNOWN CODES ARE NOT SWALLOWED. A code this module has never seen is
 * returned with the raw value as its label and `section: null`. §1.11 makes
 * adding one a non-breaking change on the server, and a member whose dossier is
 * blocked by a requirement the panel hides would be stuck with no way to see
 * why.
 *
 * @module lib/kyc-checklist
 */

const PLAIN = {
    'business_license:missing': { label: 'جواز کسب ثبت نشده است.', section: 'licenses' },
    'business_license:expired': { label: 'جواز کسب منقضی شده است؛ جواز معتبر ثبت کنید.', section: 'licenses' },
    'bank_account:missing': { label: 'حساب بانکی ثبت نشده است.', section: 'bank' },
    'signatory:missing': { label: 'صاحب امضای فعال ثبت نشده است.', section: 'identity' },
    'identity:national_id': { label: 'کد ملی در پرونده ثبت نشده است.', section: 'identity' },
    'identity:legal_id': { label: 'شناسه ملی شخص حقوقی ثبت نشده است.', section: 'identity' },
    'identity:registration_no': { label: 'شماره ثبت شرکت وارد نشده است.', section: 'identity' },
};

/**
 * One missing item, described.
 *
 * @param {string} code
 * @param {(type: string) => string} [documentLabel] resolves a DocumentType to
 *   its Persian name — normally `enumLabel('document_type', …)`, so the list
 *   stays correct when a new document type is added server-side.
 * @returns {{code: string, label: string, section: string|null, documentType: string|null}}
 */
export function describeMissingItem(code, documentLabel = (type) => type) {
    const text = String(code ?? '');

    if (text.startsWith('document:')) {
        const type = text.slice('document:'.length);

        return {
            code: text,
            label: `بارگذاری «${documentLabel(type)}» انجام نشده است.`,
            section: 'documents',
            documentType: type,
        };
    }

    const known = PLAIN[text];

    return {
        code: text,
        label: known ? known.label : text,
        section: known ? known.section : null,
        documentType: null,
    };
}

/** The whole list, described and grouped by the section that fixes it. */
export function describeChecklist(missingItems, documentLabel) {
    const items = Array.isArray(missingItems) ? missingItems : [];

    return items.map((code) => describeMissingItem(code, documentLabel));
}

/**
 * What the member can do next, from the dossier's status.
 *
 * The four states differ in what they permit, and the difference is not
 * cosmetic: KycStatus::isEditableByMember() is DRAFT and INFO_REQUIRED only, so
 * a screen offering an upload button on a SUBMITTED dossier is offering a
 * button the server refuses.
 *
 * @returns {{canEdit: boolean, canSubmit: boolean, tone: string, message: string}}
 */
export function statusGuidance(profile) {
    const status = profile ? profile.status : null;
    const complete = Boolean(profile && profile.is_complete);
    const editable = Boolean(profile && profile.is_editable);

    switch (status) {
        case 'DRAFT':
            return {
                canEdit: editable,
                canSubmit: complete,
                tone: 'info',
                message: complete
                    ? 'پرونده کامل است و آماده ارسال برای بررسی است.'
                    : 'موارد زیر را تکمیل کنید تا پرونده قابل ارسال شود.',
            };
        case 'SUBMITTED':
        case 'IN_REVIEW':
            return {
                canEdit: false,
                canSubmit: false,
                tone: 'warn',
                message: 'پرونده در صف بررسی کارشناس انطباق است؛ تا اعلام نتیجه قابل ویرایش نیست.',
            };
        case 'INFO_REQUIRED':
            return {
                canEdit: true,
                canSubmit: complete,
                tone: 'warn',
                message: 'کارشناس انطباق اطلاعات تکمیلی خواسته است. موارد زیر را اصلاح و پرونده را دوباره ارسال کنید.',
            };
        case 'APPROVED':
            return {
                canEdit: false,
                canSubmit: false,
                tone: 'good',
                message: 'پرونده تأیید شده است.',
            };
        case 'REJECTED':
            return {
                canEdit: false,
                canSubmit: false,
                tone: 'danger',
                message: 'پرونده رد شده است. برای بررسی مجدد با پشتیبانی تماس بگیرید.',
            };
        default:
            return {
                canEdit: editable,
                canSubmit: complete && editable,
                tone: 'muted',
                message: 'وضعیت پرونده نامشخص است.',
            };
    }
}
