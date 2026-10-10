import type { TenantBiometrics } from '@/types';

/**
 * Add the organization's biometric settings to the settings form. A missing list means none on the
 * server (a form cannot send an empty list). Modalities are sent only while biometrics are on: when
 * off, the kept settings stay as they are.
 */
export function appendBiometricSettings(body: FormData, settings: TenantBiometrics): void {
    const flag = (value: boolean) => (value ? '1' : '0');
    body.append('biometrics[enabled]', flag(settings.enabled));
    body.append('biometrics[family_members]', flag(settings.family_members));
    body.append('biometrics[print_on_card]', flag(settings.print_on_card));
    settings.required_before.forEach((moment) => body.append('biometrics[required_before][]', moment));
    if (!settings.enabled) {
        return;
    }
    for (const [name, modality] of Object.entries(settings.modalities)) {
        const key = `biometrics[modalities][${name}]`;
        body.append(`${key}[mode]`, modality.mode);
        body.append(`${key}[count]`, String(modality.count));
        body.append(`${key}[substitutes]`, flag(modality.substitutes));
        body.append(`${key}[min_quality]`, String(modality.min_quality));
        body.append(`${key}[max_attempts]`, String(modality.max_attempts));
        modality.positions.forEach((position) => body.append(`${key}[positions][]`, position));
        modality.drivers.forEach((driver) => body.append(`${key}[drivers][]`, driver));
    }
}
