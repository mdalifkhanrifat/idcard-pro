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
    status: 'active' | 'suspended';
    users_count?: number;
    created_at: string;
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

export interface CardholderImport {
    id: string;
    original_name: string;
    status: ImportStatus;
    options: { create_missing_lookups: boolean; update_existing: boolean };
    summary: {
        columns: { header: string; field: string }[];
        ignored: string[];
        preview: { row: number; action: 'created' | 'updated'; values: Record<string, string> }[];
        lookups_to_create: string[];
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

export type DesignElementType = 'logo' | 'background' | 'photo' | 'signatory_signature' | 'cardholder_signature' | 'qr_code' | 'field' | 'text' | 'shape';

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
    size_mm: { width: number; height: number };
}
