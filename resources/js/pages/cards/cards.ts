import { ref } from 'vue';
import { http } from '@/lib/http';
import { router } from '@/router';
import type { CardLayout, CardSide, CardValues, IssuedCard } from '@/types';

export interface CardReasonOption {
    key: string;
    /** A note is required. */
    note: boolean;
    /** A proof document may be attached. */
    document: boolean;
}

/** A paper several cards are laid out on, with crop marks (config/cards.php → print.sheets; portrait, mm). */
export interface PrintSheetSize {
    key: string;
    width_mm: number;
    height_mm: number;
}

/** Page layout of a print: "card" = one card side per page (card printers), or a paper key. */
export const SHEET_CARD = 'card';

/** Printing limits from config/cards.php → print. */
export interface CardPrintOptions {
    methods: string[];
    max_copies: number;
    max_batch: number;
    sheets: PrintSheetSize[];
    /** Server PDFs are switched on (config/pdf.php). */
    pdf: boolean;
}

const reasons = ref<CardReasonOption[]>([]);
const printOptions = ref<CardPrintOptions>({ methods: ['browser'], max_copies: 10, max_batch: 200, sheets: [], pdf: false });
let loading: Promise<void> | null = null;

/**
 * Card reasons from the server (config/cards.php), loaded once per page, so a reason added there
 * appears in every form without a frontend change.
 */
export function useCardReasons() {
    loading ??= http.get('/cards/options').then(
        ({ data }) => {
            reasons.value = data.data.reasons;
            printOptions.value = data.data.print;
        },
        () => {
            loading = null;
        },
    );

    return {
        reasons,
        printOptions,
        ready: loading,
        needs: (key: string): CardReasonOption => reasons.value.find((reason) => reason.key === key) ?? { key, note: false, document: false },
    };
}

/** Address of the print page for these cards. */
export function printPageHref(cardIds: string[]): string {
    return router.resolve({ name: 'cards.print', query: { ids: cardIds.join(',') } }).href;
}

/** Opens the print page for these cards in a new tab, so the page the user is on stays as it is. */
export function openPrintPage(cardIds: string[]): void {
    window.open(printPageHref(cardIds), '_blank');
}

/**
 * Downloads a PDF of these cards made on the server (same drawing as printing). `record` also logs it as
 * a print (method "pdf"); a download alone is not counted as a printed card.
 */
export async function downloadCardsPdf(cardIds: string[], options: { sides: PrintSides; record: boolean; sheet?: string }): Promise<void> {
    try {
        const response =
            cardIds.length === 1
                ? await http.get<Blob>(`/cards/${cardIds[0]}/pdf`, { params: { sides: options.sides, sheet: options.sheet, record: options.record ? 1 : 0 }, responseType: 'blob' })
                : await http.post<Blob>('/cards/pdf', { cards: cardIds, ...options }, { responseType: 'blob' });
        const filename = /filename="([^"]+)"/.exec(String(response.headers['content-disposition'] ?? ''))?.[1] ?? 'cards.pdf';
        const url = URL.createObjectURL(response.data);
        Object.assign(document.createElement('a'), { href: url, download: filename }).click();
        setTimeout(() => URL.revokeObjectURL(url), 10_000);
    } catch (error) {
        throw await readBlobError(error);
    }
}

/** A failed blob request carries its JSON error as a Blob; read it so the message can be shown. */
async function readBlobError(error: unknown): Promise<Error> {
    const data = (error as { response?: { data?: unknown } }).response?.data;
    if (data instanceof Blob) {
        try {
            const body = JSON.parse(await data.text()) as { message?: string };
            if (body.message) {
                return new Error(body.message);
            }
        } catch {
            // Not JSON: fall through to the generic message
        }
    }

    return error instanceof Error ? error : new Error(String(error));
}

/** Status shown to people: an active card past its expiry shows as expired. */
export function displayStatus(card: Pick<IssuedCard, 'status' | 'is_expired'>): 'active' | 'replaced' | 'revoked' | 'expired' {
    return card.status === 'active' && card.is_expired ? 'expired' : card.status;
}

export function statusTone(status: ReturnType<typeof displayStatus>): 'success' | 'neutral' | 'danger' | 'warning' {
    return ({ active: 'success', replaced: 'neutral', revoked: 'danger', expired: 'warning' } as const)[status];
}

export type CardImageKey = keyof CardValues['images'];

/** Sides printed in one pass: both, or front then back for printers without duplex. */
export type PrintSides = 'both' | 'front' | 'back';

/** An issued card ready to be laid out for printing. */
export interface SheetCard {
    id: string;
    layout: CardLayout;
    values: CardValues;
}

/** Pages a card takes in a pass; a single-sided card has no back to print in a "back only" pass. */
export function sheetSides(layout: CardLayout, sides: PrintSides): CardSide[] {
    if (!layout.is_double_sided) {
        return sides === 'back' ? [] : ['front'];
    }

    return sides === 'both' ? ['front', 'back'] : [sides];
}

/** On a paper sheet: margin around the cards and gap between them (mm); crop marks sit in the gaps. */
export const SHEET_MARGIN_MM = 10;
export const SHEET_GAP_MM = 6;

/** A card side placed on a page, at mm from the page's top left. */
export interface PlacedCard {
    key: string;
    card: SheetCard;
    side: CardSide;
    x: number;
    y: number;
}

/** One printed page: its size (mm) and the card sides on it. `marks` = draw crop marks around the cards. */
export interface PrintPage {
    key: string;
    width: number;
    height: number;
    items: PlacedCard[];
    marks: boolean;
}

/**
 * Pages of a print. Without a sheet, each card side is its own page at the card's size (card printers).
 * On a sheet, cards of the same size share pages in a grid centred across the paper; each front page is
 * followed by its back page with the columns mirrored, so the sides meet when the paper is turned over
 * its long edge (duplex), and a "back only" pass fits a re-fed front stack. Cards without a back leave
 * their place empty, and back pages are kept even when empty, so fronts and backs never shift.
 */
export function printPages(cards: SheetCard[], sides: PrintSides, copies: number, sheet: PrintSheetSize | null): PrintPage[] {
    const all = Array.from({ length: copies }, (_, copy) => cards.map((card) => ({ card, copy }))).flat();

    if (!sheet) {
        return all.flatMap(({ card, copy }) =>
            sheetSides(card.layout, sides).map((side) => {
                const key = `${copy}-${card.id}-${side}`;

                return { key, width: card.layout.size_mm.width, height: card.layout.size_mm.height, items: [{ key, card, side, x: 0, y: 0 }], marks: false };
            }),
        );
    }

    const withBacks = cards.some((card) => card.layout.is_double_sided);
    const passes: CardSide[] = sides === 'both' ? (withBacks ? ['front', 'back'] : ['front']) : sides === 'back' && !withBacks ? [] : [sides];

    // Same-size cards together, in the order their size first appears
    const groups = new Map<string, typeof all>();
    for (const entry of all) {
        const size = `${entry.card.layout.size_mm.width}x${entry.card.layout.size_mm.height}`;
        groups.set(size, [...(groups.get(size) ?? []), entry]);
    }

    const pages: PrintPage[] = [];
    for (const [size, entries] of groups) {
        const { width, height } = entries[0].card.layout.size_mm;
        const columns = Math.max(1, Math.floor((sheet.width_mm - 2 * SHEET_MARGIN_MM + SHEET_GAP_MM) / (width + SHEET_GAP_MM)));
        const rows = Math.max(1, Math.floor((sheet.height_mm - 2 * SHEET_MARGIN_MM + SHEET_GAP_MM) / (height + SHEET_GAP_MM)));
        const left = (sheet.width_mm - (columns * width + (columns - 1) * SHEET_GAP_MM)) / 2;

        for (let start = 0, number = 0; start < entries.length; start += columns * rows, number++) {
            const chunk = entries.slice(start, start + columns * rows);
            for (const side of passes) {
                const items = chunk.flatMap(({ card, copy }, index) => {
                    if (side === 'back' && !card.layout.is_double_sided) {
                        return [];
                    }
                    const column = side === 'back' ? columns - 1 - (index % columns) : index % columns;

                    return [{ key: `${copy}-${card.id}-${side}`, card, side, x: left + column * (width + SHEET_GAP_MM), y: SHEET_MARGIN_MM + Math.floor(index / columns) * (height + SHEET_GAP_MM) }];
                });
                pages.push({ key: `${size}-${number}-${side}`, width: sheet.width_mm, height: sheet.height_mm, items, marks: side === 'front' });
            }
        }
    }

    return pages;
}

/**
 * Image elements of a card (photo, signatures) that have nothing to print for this holder, e.g. a family
 * member without their own photo. Hidden elements are ignored; the element's placeholder image, if any,
 * prints instead.
 */
export function missingImages(layout: CardLayout, values: CardValues): CardImageKey[] {
    const keys = Object.keys(values.images) as CardImageKey[];
    const missing = layout.elements
        .filter((element) => !element.style.hidden && keys.includes(element.type as CardImageKey) && !values.images[element.type as CardImageKey])
        .map((element) => element.type as CardImageKey);

    return [...new Set(missing)];
}
