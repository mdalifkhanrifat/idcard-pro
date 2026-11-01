import { http } from '@/lib/http';

/** An organization's account for another system (6.1). */
export interface Integration {
    id: string;
    name: string;
    description: string | null;
    role: string | null;
    status: 'active' | 'inactive';
    allowed_ips: string[];
    tokens_count: number;
    last_used_at: string | null;
    created_at: string;
}

export interface ApiToken {
    id: number;
    name: string;
    expires_at: string | null;
    expired: boolean;
    last_used_at: string | null;
    created_at: string;
}

/** The token itself comes with its making only and cannot be asked for again. */
export interface NewApiToken extends ApiToken {
    plain_text_token: string;
}

/** What the list's answer says for this person. */
export interface IntegrationOptions {
    assignable_roles: string[];
    token_days: number;
    max_token_days: number;
    requests_per_minute: number;
}

export async function fetchTokens(id: string): Promise<ApiToken[]> {
    const { data } = await http.get<{ data: ApiToken[] }>(`/integrations/${id}/tokens`);

    return data.data;
}

export async function createToken(id: string, name: string, days: number | string): Promise<NewApiToken> {
    const { data } = await http.post<{ data: NewApiToken }>(`/integrations/${id}/tokens`, { name, expires_in_days: Number(days) });

    return data.data;
}

export async function revokeToken(id: string, tokenId: number): Promise<void> {
    await http.delete(`/integrations/${id}/tokens/${tokenId}`);
}
