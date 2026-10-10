export type UserStatus = 'active' | 'inactive';

export interface Tenant {
    id: string;
    name: string;
    slug: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    country_code: string | null;
    timezone: string;
    locale: string;
    primary_color: string;
    logo_url: string | null;
    card_number_format?: string;
    /** Number when a holder gets another card from a template with the same code. */
    card_number_on_reissue?: 'keep' | 'new';
    /** Landscape default for new templates, in mm. */
    default_card_size?: { width: number; height: number };
    /** Public card verification (3.7), chosen by the organization. */
    verification?: TenantVerification;
    /** Details the verification page may show. */
    verification_fields?: string[];
    /** Biometric capture (Phase 4), chosen by the organization. */
    biometrics?: TenantBiometrics;
    /** What this installation can capture and with what (registry). */
    biometric_options?: BiometricOptions;
    status: 'active' | 'suspended';
    users_count?: number;
    created_at: string;
}

/** How one kind of biometric (fingerprint, face, iris… from the registry) is captured. */
export interface BiometricModalitySettings {
    /** One of BiometricOptions.modes (off / optional / required). */
    mode: string;
    /** How many positions are needed. */
    count: number;
    /** Positions to capture, preferred first. */
    positions: string[];
    /** Another position may stand in for a preferred one that cannot be captured. */
    substitutes: boolean;
    /** 0-100; below it the station asks for another try. */
    min_quality: number;
    max_attempts: number;
    drivers: string[];
}

export interface TenantBiometrics {
    enabled: boolean;
    modalities: Record<string, BiometricModalitySettings>;
    family_members: boolean;
    /** Moments complete biometrics are needed before (issue, print). */
    required_before: string[];
    print_on_card: boolean;
}

export interface BiometricOptions {
    modalities: Record<string, {
        positions: string[];
        /** Screen layout: group => positions (e.g. a row per hand); may be empty. */
        groups: Record<string, string[]> | [];
        printable: boolean;
        drivers: string[];
        /** Formats a capture may be sent as. */
        image_formats: string[];
        /** Template formats; empty = image only. */
        template_formats: string[];
    }>;
    drivers: Record<string, { modalities: string[]; local_url: string | null; testing: boolean }>;
    modes: string[];
    required_before: string[];
    unavailable_reasons: string[];
    /** Reasons that need a note. */
    unavailable_reasons_with_note: string[];
    /** Image format => first bytes (hex), content type, and whether a browser can show it. */
    image_types: Record<string, { signature: string; mime: string; displayable: boolean }>;
    max_image_kb: number;
    max_template_kb: number;
}

/** A current capture (or a position marked unavailable) of a cardholder or family member. */
export interface BiometricCapture {
    id: string;
    modality: string;
    position: string;
    /** ISO/IEC 19794 position code. */
    position_code: number | null;
    status: 'captured' | 'unavailable';
    unavailable_reason: string | null;
    note: string | null;
    driver: string | null;
    device: { vendor?: string | null; model?: string | null; serial?: string | null } | null;
    image_format: string | null;
    image_width: number | null;
    image_height: number | null;
    image_dpi: number | null;
    /** Short-lived signed link; null when not shown in a browser (e.g. WSQ). */
    image_url: string | null;
    has_template: boolean;
    template_format: string | null;
    quality: number | null;
    low_quality: boolean;
    attempts: number;
    captured_by?: { id: string; name: string } | null;
    captured_at: string;
}

/** Whether a person meets the organization's biometric settings (`meta.status`). */
export interface BiometricStatus {
    enabled: boolean;
    /** False for family members when the organization does not capture them. */
    applies: boolean;
    complete: boolean;
    modalities: Record<string, {
        mode: string;
        required: boolean;
        needed: number;
        captured: string[];
        unavailable: string[];
        counted: number;
        complete: boolean;
        /** Complete because every possible position was taken or marked unavailable. */
        with_exceptions: boolean;
        /** Positions to take next, preferred first. */
        next: string[];
    }>;
}

export interface TenantVerification {
    enabled: boolean;
    /** Details shown on the public page, in this order. */
    fields: string[];
    show_revoke_reason: boolean;
    /** Own address with {code}; null = this application's page. */
    link: string | null;
    note: string | null;
    /** Keep a log of checks (shown in card details). */
    log_checks: boolean;
}

export interface User {
    id: string;
    name: string;
    email: string;
    username: string | null;
    phone: string | null;
    locale: string | null;
    status: UserStatus;
    is_platform_admin: boolean;
    is_locked: boolean;
    two_factor_enabled: boolean;
    last_login_at: string | null;
    roles?: string[];
    created_at: string;
}

export interface Role {
    id: number;
    name: string;
    is_system: boolean;
    permissions?: string[];
    users_count?: number;
}

export interface Paginated<T> {
    data: T[];
    meta?: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
    from?: number | null;
    to?: number | null;
}

export interface Session {
    user: User;
    tenant: Tenant | null;
    permissions: string[];
}

export interface MasterDataType {
    type: string;
    parent: string | null;
    has_code: boolean;
    count: number;
}

export interface MasterDataItem {
    id: string;
    type: string;
    name: string;
    name_local: string | null;
    code: string | null;
    sort_order: number;
    is_active: boolean;
    parent: { id: string; name: string } | null;
    children_count?: number;
    updated_at: string;
}

export interface LookupRef {
    id: string;
    name: string;
    name_local: string | null;
}

export interface FileSummary {
    id: string;
    url: string;
    width: number | null;
    height: number | null;
}

export type CardholderStatus = 'active' | 'inactive' | 'retired';
export type EmploymentType = 'staff' | 'contractor' | 'visitor' | 'student' | 'other';

export interface Cardholder {
    id: string;
    full_name: string;
    full_name_local: string | null;
    date_of_birth: string | null;
    gender: 'male' | 'female' | 'other' | null;
    marital_status: 'single' | 'married' | 'divorced' | 'widowed' | null;
    nationality: string | null;
    national_id: string | null;
    passport_no: string | null;
    email: string | null;
    personal_phone: string | null;
    emergency_phone: string | null;
    present_address: string | null;
    permanent_address: string | null;
    postal_code: string | null;
    height: string | null;
    weight: string | null;
    eye_color: string | null;
    complexion: string | null;
    identification_mark: string | null;
    employee_no: string | null;
    secondary_no: string | null;
    employment_type: EmploymentType;
    joining_date: string | null;
    retirement_date: string | null;
    retired_id_no: string | null;
    posting: string | null;
    previous_office: string | null;
    company_name: string | null;
    company_address: string | null;
    company_phone: string | null;
    blood_group?: LookupRef | null;
    religion?: LookupRef | null;
    region?: LookupRef | null;
    sub_region?: LookupRef | null;
    department?: LookupRef | null;
    unit?: LookupRef | null;
    rank?: LookupRef | null;
    designation?: LookupRef | null;
    branch?: LookupRef | null;
    trade?: LookupRef | null;
    appointment?: LookupRef | null;
    status: CardholderStatus;
    custom_fields: Record<string, string | number | boolean>;
    photo?: FileSummary | null;
    signature?: FileSummary | null;
    /** Own active card (null: none); only with cards.view. Legacy list "Status": PENDING / PRINTED. */
    current_card?: { id: string; number: string; print_count: number; last_printed_at: string | null } | null;
    /** Cards were issued to the person or their family: the record cannot be deleted (mark it inactive instead). */
    has_cards?: boolean;
    created_by?: { id: string; name: string } | null;
    created_at: string;
    updated_at: string;
}

export interface CardholderFile {
    id: string;
    collection: string;
    title: string | null;
    original_name: string;
    mime_type: string;
    size: number;
    width: number | null;
    height: number | null;
    is_current: boolean;
    url: string;
    uploaded_by?: { id: string; name: string } | null;
    replaced_at: string | null;
    created_at: string;
}

export type FamilyRelation = 'father' | 'mother' | 'father_in_law' | 'mother_in_law' | 'spouse' | 'son' | 'daughter' | 'sibling' | 'other';

export interface FamilyMember {
    id: string;
    relation: FamilyRelation;
    name: string;
    name_local: string | null;
    gender: 'male' | 'female' | 'other' | null;
    date_of_birth: string | null;
    phone: string | null;
    blood_group?: { id: string; name: string } | null;
    national_id: string | null;
    passport_no: string | null;
    identification_mark: string | null;
    card_no: string | null;
    sort_order: number;
    /** The member's own photo and signature, printed on family cards. */
    photo?: FileSummary | null;
    signature?: FileSummary | null;
    updated_at: string;
}

export type QualificationKind = 'education' | 'course';

export interface Qualification {
    id: string;
    kind: QualificationKind;
    title: string;
    institution: string | null;
    year: number | null;
    subject: string | null;
    degree: string | null;
    result: string | null;
    certificate_no: string | null;
    sort_order: number;
    updated_at: string;
}

export type CustomFieldType = 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox';

export interface CustomField {
    id: string;
    entity: string;
    key: string;
    label: string;
    label_local: string | null;
    group: string | null;
    type: CustomFieldType;
    options: string[] | null;
    is_required: boolean;
    is_active: boolean;
    sort_order: number;
}

export type ImportStatus = 'analyzed' | 'queued' | 'processing' | 'completed' | 'failed';

export interface ImportCardCounts {
    imported: number;
    skipped: number;
}

export interface CardholderImport {
    id: string;
    original_name: string;
    status: ImportStatus;
    options: { create_missing_lookups: boolean; update_existing: boolean; import_cards?: boolean; card_template?: string | null };
    summary: {
        /** Field: a cardholder field, "custom:<key>" or "card:<field>". */
        columns: { header: string; field: string }[];
        ignored: string[];
        preview: { row: number; action: 'created' | 'updated'; card?: 'imported' | 'skipped' | null; values: Record<string, string> }[];
        lookups_to_create: string[];
        /** Headers of card columns found in the file (used only when cards are imported). */
        card_columns?: string[];
        /** Cards to import / skipped because the person already has them (analysis). */
        cards?: ImportCardCounts;
        card_template?: { id: string; name: string; code: string } | null;
        /** Cards actually imported / skipped (after the run). */
        cards_result?: ImportCardCounts;
    } | null;
    total_rows: number;
    valid_rows: number;
    created_count: number;
    updated_count: number;
    failed_count: number;
    errors: { row: number; messages: string[] }[];
    user?: { id: string; name: string } | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
}

/** An image in the organization library (logos, backgrounds), linked from card templates. */
export interface LibraryImage {
    id: string;
    name: string;
    /** Pixels. */
    width: number;
    height: number;
    /** Bytes. */
    size: number;
    mime_type: string;
    /** Signed link, stable for a day. */
    url: string;
    /** Templates linking this image (library listing only). */
    templates_count?: number;
    created_at: string;
}

/** A licensed font file uploaded by the organization (one weight and style of a family). */
export interface TenantFont {
    id: string;
    family: string;
    weight: number;
    style: 'normal' | 'italic';
    format: 'truetype' | 'opentype' | 'woff' | 'woff2';
    original_name: string;
    /** Bytes. */
    size: number;
    /** Signed link, stable for a day so the browser caches the file. */
    url: string;
    created_at: string;
}

export interface Signatory {
    id: string;
    name: string;
    name_local: string | null;
    designation: string;
    designation_local: string | null;
    authority_type?: { id: string; name: string } | null;
    signature_url: string | null;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
    updated_at: string;
}

export type CardStatus = 'active' | 'replaced' | 'revoked';

/** An issued card (legacy card_information + print reason). */
export interface IssuedCard {
    id: string;
    number: string;
    rf_card_no: string | null;
    /** Key of config/cards.php → reasons. */
    reason: string;
    notes: string | null;
    status: CardStatus;
    is_expired: boolean;
    issue_date: string;
    expiry_date: string | null;
    status_changed_at: string | null;
    status_note: string | null;
    template_version: number;
    /** Total copies printed; 0 = never printed (legacy "PENDING"). */
    print_count: number;
    last_printed_at: string | null;
    /** Public checks on the verification page (3.7). */
    verify_count: number;
    last_verified_at: string | null;
    /** Public verification link; null while the organization has verification off. */
    verify_url: string | null;
    /** Active and not expired: the server's rule for printing. */
    printable: boolean;
    template?: { id: string; name: string; code: string };
    family_member?: { id: string; name: string; relation: string } | null;
    cardholder?: { id: string; full_name: string; employee_no: string | null; department?: string | null };
    issued_by?: string | null;
    replaced_by?: { id: string; number: string } | null;
    document?: { id: string; name: string; url: string } | null;
    created_at: string;
}

/** One printing of an issued card (card_prints). */
/** One public check of a card: its status then, a short device id (never the address), when. */
export interface CardVerificationLog {
    status: 'valid' | 'expired' | 'revoked' | 'replaced';
    device: string;
    verified_at: string;
}

export interface CardPrintLog {
    id: string;
    card?: { id: string; number: string };
    /** Same id for cards printed together. */
    batch: string | null;
    /** Key of config/cards.php → print.methods. */
    method: string;
    copies: number;
    note: string | null;
    printed_by?: { id: string; name: string } | null;
    printed_at: string;
}

/** The design a card was issued with, in the designer's element shape. */
export interface CardLayout {
    size_mm: { width: number; height: number };
    orientation: string;
    is_double_sided: boolean;
    elements: DesignElement[];
}

/** What a card prints: text by data field, and the photo and signatures. */
export interface CardValues {
    fields: Record<string, string>;
    images: { photo: string | null; cardholder_signature: string | null; signatory_signature: string | null };
}

export interface CardTemplate {
    id: string;
    name: string;
    code: string;
    card_type?: { id: string; name: string } | null;
    audience: 'cardholder' | 'family_member';
    orientation: 'horizontal' | 'vertical';
    width: number;
    height: number;
    size_unit: 'mm' | 'px';
    authority_type?: { id: string; name: string } | null;
    validity_months: number | null;
    is_double_sided: boolean;
    is_active: boolean;
    version: number;
    updated_at: string;
    /** Only with `include=elements`. */
    elements?: DesignElement[];
}

export type CardSide = 'front' | 'back';

export type DesignElementType = 'logo' | 'background' | 'photo' | 'signatory_signature' | 'cardholder_signature' | 'qr_code' | 'field' | 'text' | 'shape' | 'barcode';

export interface DesignElementStyle {
    font_family?: string;
    /** Points. */
    font_size?: number;
    color?: string;
    label_color?: string;
    background?: string;
    bold?: boolean;
    italic?: boolean;
    underline?: boolean;
    align?: 'left' | 'center' | 'right';
    vertical_align?: 'top' | 'middle' | 'bottom';
    line_height?: number;
    /** Points. */
    letter_spacing?: number;
    uppercase?: boolean;
    /** Shrink the font (down to 4 pt) until the text fits its box. */
    auto_fit?: boolean;
    /** Never wrap the text. */
    single_line?: boolean;
    /** Millimetres, like every size below. */
    padding?: number;
    /** Second colour of a gradient fill that starts with `background`. */
    gradient_to?: string;
    gradient_direction?: 'to bottom' | 'to right' | 'to bottom right' | 'to top right';
    border_color?: string;
    border_width?: number;
    border_radius?: number;
    opacity?: number;
    fit?: 'cover' | 'contain';
    shape?: 'rectangle' | 'ellipse';
    border_style?: 'solid' | 'dashed' | 'dotted';
    /** Degrees; the content turns inside the unrotated box. */
    rotation?: 0 | 90 | 180 | 270;
    /** Degrees (−180..180): the whole element tilts around its centre, after `rotation`. */
    angle?: number;
    /** A tilted element may reach past the card edge and is cut off there. */
    bleed?: boolean;
    /** Editing only: cannot be moved or changed. */
    locked?: boolean;
    /** Kept in the design but not printed. */
    hidden?: boolean;
    /** Editing only: elements of a side with the same id are selected and moved together. */
    group?: string;
    /** Shadow, on when a colour is set; offsets and blur in mm. */
    shadow_color?: string;
    shadow_x?: number;
    shadow_y?: number;
    shadow_blur?: number;
    shadow_opacity?: number;
    /** Barcode symbology; Code 128 when not set. */
    barcode_format?: 'code128' | 'code39' | 'ean13' | 'ean8' | 'upc' | 'itf' | 'codabar';
    /** Print the encoded value under the bars. */
    barcode_text?: boolean;
    /** QR error correction level; default H (legacy). */
    qr_error_correction?: 'L' | 'M' | 'Q' | 'H';
}

export interface QrLine {
    field?: string;
    label?: string;
    text?: string;
}

/** One item on a card side; position and size in millimetres from the top-left corner. */
export interface DesignElement {
    id?: string;
    side: CardSide;
    type: DesignElementType;
    field: string | null;
    label: string | null;
    content: string | null;
    qr_lines: QrLine[] | null;
    image: string | null;
    /** Public id of a linked library image (instead of `image`). */
    library_image?: string | null;
    image_url?: string | null;
    x: number;
    y: number;
    width: number;
    height: number;
    style: DesignElementStyle;
}

export interface DesignerCatalog {
    types: Record<DesignElementType, { image?: boolean; single_per_side?: boolean; needs_field?: boolean; needs_content?: boolean }>;
    fields: Record<string, string[]>;
    custom_fields: { key: string; label: string; label_local: string | null }[];
    /** Shipped with the application. */
    fonts: string[];
    /** Render only where installed on the computer. */
    system_fonts: string[];
    barcode_formats: NonNullable<DesignElementStyle['barcode_format']>[];
    qr_error_levels: NonNullable<DesignElementStyle['qr_error_correction']>[];
    /** Licensed fonts uploaded by the organization, one entry per file. */
    tenant_fonts: { family: string; weight: number; style: string; url: string }[];
    size_mm: { width: number; height: number };
}
