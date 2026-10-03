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
