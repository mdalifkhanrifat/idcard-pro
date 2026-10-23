import { http, toApiError } from '@/lib/http';
import type { Cardholder, CardholderStatus } from '@/types';

export function statusTone(status: CardholderStatus): 'success' | 'neutral' | 'warning' {
    return status === 'active' ? 'success' : status === 'retired' ? 'warning' : 'neutral';
}

/**
 * Why a cardholder is kept as a record instead of deleted — cards issued, appointments booked (for them or their
 * family; the server refuses the delete too) — or null when it can be deleted.
 */
export function keptAsRecord(cardholder: Pick<Cardholder, 'has_cards' | 'has_appointments'>, t: (key: string) => string): string | null {
    if (cardholder.has_cards) {
        return t('cardholders.hasCards');
    }

    return cardholder.has_appointments ? t('cardholders.hasAppointments') : null;
}

/** Upload limits in KB; keep in sync with config/cardholder_files.php. */
export const FILE_LIMITS = { photo: 5120, signature: 2048, document: 10240 } as const;

export type HolderImage = 'photo' | 'signature';

/**
 * Upload the photo and signature picked in a form, for the cardholder or one of their family members.
 * Each image is uploaded on its own, so one failing does not stop the other; failures are reported back.
 */
export async function uploadHolderImages(
    cardholderId: string,
    images: Partial<Record<HolderImage, File | null>>,
    onError: (collection: HolderImage, message: string) => void,
    familyMemberId: string | null = null,
): Promise<void> {
    for (const [collection, file] of Object.entries(images) as [HolderImage, File | null][]) {
        if (!file) {
            continue;
        }
        const body = new FormData();
        body.append('collection', collection);
        body.append('file', file);
        if (familyMemberId) {
            body.append('family_member', familyMemberId);
        }
        try {
            await http.post(`/cardholders/${cardholderId}/files`, body);
        } catch (error) {
            const apiError = toApiError(error);
            onError(collection, apiError.errors.file?.[0] ?? apiError.message);
        }
    }
}

/** Master data reference fields: form field => master data type. */
export const LOOKUP_FIELDS = {
    blood_group_id: 'blood_group',
    religion_id: 'religion',
    region_id: 'region',
    sub_region_id: 'sub_region',
    department_id: 'department',
    unit_id: 'unit',
    rank_id: 'rank',
    designation_id: 'designation',
    branch_id: 'branch',
    trade_id: 'trade',
    appointment_id: 'appointment',
} as const;

export type LookupField = keyof typeof LOOKUP_FIELDS;

const TEXT_FIELDS = [
    'full_name', 'full_name_local', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'national_id', 'passport_no',
    'email', 'personal_phone', 'emergency_phone', 'present_address', 'permanent_address', 'postal_code',
    'height', 'weight', 'eye_color', 'complexion', 'identification_mark',
    'employee_no', 'secondary_no', 'employment_type', 'joining_date', 'retirement_date', 'retired_id_no', 'posting', 'previous_office',
    'company_name', 'company_address', 'company_phone', 'status',
] as const;

export type CardholderForm = Record<(typeof TEXT_FIELDS)[number] | LookupField, string>;

export function blankForm(): CardholderForm {
    const form = Object.fromEntries([...TEXT_FIELDS, ...Object.keys(LOOKUP_FIELDS)].map((key) => [key, ''])) as CardholderForm;
    form.employment_type = 'staff';
    form.status = 'active';

    return form;
}

export function formFromCardholder(cardholder: Cardholder): CardholderForm {
    const form = blankForm();
    for (const key of TEXT_FIELDS) {
        form[key] = (cardholder[key] as string | null) ?? '';
    }
    for (const [field, type] of Object.entries(LOOKUP_FIELDS) as [LookupField, string][]) {
        form[field] = (cardholder[type as keyof Cardholder] as { id: string } | null | undefined)?.id ?? '';
    }

    return form;
}

/** Empty inputs are sent as null so optional columns are cleared. */
export function toPayload(form: CardholderForm): Record<string, string | null> {
    return Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]));
}
