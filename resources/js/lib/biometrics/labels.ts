/**
 * Names of registry entries (config/biometrics.php) for screens. A kind, position or driver added to
 * the registry without a translation still shows, as its key in words ("right_palm" → "Right palm").
 */
export type BiometricLabelKind = 'modalities' | 'positions' | 'groups' | 'drivers' | 'modes' | 'reasons';

export function biometricLabel(kind: BiometricLabelKind, key: string, t: (key: string) => string, te: (key: string) => boolean): string {
    const path = `biometrics.${kind}.${key}`;
    if (te(path)) {
        return t(path);
    }
    const words = key.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * Name of a card designer biometric element's field (`biometric.<modality>.<position|auto>`), e.g.
 * "Fingerprint · First captured" or "Fingerprint · Right thumb".
 */
export function biometricFieldLabel(field: string, t: (key: string) => string, te: (key: string) => boolean): string {
    const [, modality = '', position = ''] = field.split('.');

    return `${biometricLabel('modalities', modality, t, te)} · ${position === 'auto' ? t('biometrics.autoPosition') : biometricLabel('positions', position, t, te)}`;
}

/** Positions of a modality laid out in rows: the registry's groups, then any position no group holds. */
export function positionRows(positions: string[], groups: Record<string, string[]> | []): { group: string | null; positions: string[] }[] {
    const rows = Object.entries(groups).map(([group, members]) => ({ group, positions: members.filter((position) => positions.includes(position)) }));
    const grouped = new Set(rows.flatMap((row) => row.positions));
    const rest = positions.filter((position) => !grouped.has(position));

    return [...rows.filter((row) => row.positions.length > 0), ...(rest.length > 0 ? [{ group: null, positions: rest }] : [])];
}
