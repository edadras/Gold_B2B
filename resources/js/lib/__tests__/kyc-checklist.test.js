/**
 * Turning `missing_items` into instructions — §2.2.
 *
 * KycSubmissionService emits machine codes; the onboarding screen has to tell a
 * member what to do about each one. The case worth pinning is the code this
 * module has never seen: the server may add a requirement at any time, and a
 * dossier blocked by a requirement the panel silently drops is a member with no
 * way to find out why they cannot submit.
 *
 *     node --test resources/js/lib/__tests__/kyc-checklist.test.js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { describeChecklist, describeMissingItem, statusGuidance } from '../kyc-checklist.js';

describe('describeMissingItem', () => {
    it('names the document and points at the documents section', () => {
        const item = describeMissingItem('document:NATIONAL_CARD_FRONT', () => 'تصویر روی کارت ملی');

        assert.equal(item.section, 'documents');
        assert.equal(item.documentType, 'NATIONAL_CARD_FRONT');
        assert.ok(item.label.includes('تصویر روی کارت ملی'));
    });

    it('falls back to the raw document type when no resolver is given', () => {
        const item = describeMissingItem('document:SOMETHING_NEW');

        assert.ok(item.label.includes('SOMETHING_NEW'));
        assert.equal(item.section, 'documents');
    });

    it('describes each of the non-document codes the service emits', () => {
        const cases = {
            'business_license:missing': 'licenses',
            'business_license:expired': 'licenses',
            'bank_account:missing': 'bank',
            'signatory:missing': 'identity',
            'identity:national_id': 'identity',
            'identity:legal_id': 'identity',
            'identity:registration_no': 'identity',
        };

        for (const [code, section] of Object.entries(cases)) {
            const item = describeMissingItem(code);

            assert.equal(item.section, section, code);
            assert.notEqual(item.label, code, `${code} should not be shown raw`);
        }
    });

    it('surfaces an unknown code rather than swallowing it', () => {
        const item = describeMissingItem('something:brand_new');

        assert.equal(item.label, 'something:brand_new');
        assert.equal(item.section, null);
    });

    it('tolerates null', () => {
        assert.equal(describeMissingItem(null).label, '');
    });
});

describe('describeChecklist', () => {
    it('maps the whole list and survives a missing one', () => {
        const items = describeChecklist(['bank_account:missing', 'signatory:missing']);

        assert.equal(items.length, 2);
        assert.deepEqual(describeChecklist(null), []);
        assert.deepEqual(describeChecklist(undefined), []);
    });
});

describe('statusGuidance', () => {
    it('lets a complete DRAFT be submitted', () => {
        const guidance = statusGuidance({ status: 'DRAFT', is_complete: true, is_editable: true });

        assert.equal(guidance.canSubmit, true);
        assert.equal(guidance.canEdit, true);
    });

    it('refuses to submit an incomplete DRAFT', () => {
        const guidance = statusGuidance({ status: 'DRAFT', is_complete: false, is_editable: true });

        assert.equal(guidance.canSubmit, false);
    });

    it('locks the dossier while an officer holds it', () => {
        for (const status of ['SUBMITTED', 'IN_REVIEW']) {
            const guidance = statusGuidance({ status, is_complete: true, is_editable: false });

            assert.equal(guidance.canEdit, false, status);
            assert.equal(guidance.canSubmit, false, status);
        }
    });

    it('reopens editing for INFO_REQUIRED — the resubmission path', () => {
        const guidance = statusGuidance({ status: 'INFO_REQUIRED', is_complete: true, is_editable: true });

        assert.equal(guidance.canEdit, true);
        assert.equal(guidance.canSubmit, true);
        assert.equal(guidance.tone, 'warn');
    });

    it('closes both approved and rejected dossiers', () => {
        assert.equal(statusGuidance({ status: 'APPROVED', is_complete: true }).canEdit, false);
        assert.equal(statusGuidance({ status: 'REJECTED', is_complete: true }).canSubmit, false);
    });

    it('does not throw on a null profile or an unknown status', () => {
        assert.equal(statusGuidance(null).canSubmit, false);
        assert.equal(statusGuidance({ status: 'WHO_KNOWS' }).tone, 'muted');
    });
});
