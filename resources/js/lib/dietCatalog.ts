import type { MenuItemDiet, VenueScopeMeta } from '@/types';

const FALLBACK: VenueScopeMeta = {
    param: 'venue_scope',
    default: 'exclusive',
    group_label: '',
    values: [],
};

let cached: VenueScopeMeta = { ...FALLBACK, values: [] };
let cachedMenuItemDiets: MenuItemDiet[] = [];

export function applyVenueScopeMeta(meta: VenueScopeMeta | undefined | null): void {
    if (!meta?.param) {
        return;
    }

    cached = {
        param: meta.param,
        default: meta.default || FALLBACK.default,
        group_label: meta.group_label ?? '',
        values: Array.isArray(meta.values) ? meta.values : [],
    };
}

export function applyMenuItemDiets(items: MenuItemDiet[] | undefined | null): void {
    cachedMenuItemDiets = Array.isArray(items) ? items : [];
}

export function venueScopeMeta(): VenueScopeMeta {
    return cached;
}

export function venueScopeParam(): string {
    return cached.param;
}

export function venueScopeDefault(): string {
    return cached.default;
}

export function menuItemDiets(): MenuItemDiet[] {
    return cachedMenuItemDiets;
}

/** 測試用：把模組狀態還原成尚未打過 /diets 的 fallback。 */
export function resetVenueScopeMeta(): void {
    cached = { ...FALLBACK, values: [] };
    cachedMenuItemDiets = [];
}
