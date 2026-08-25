import { computed, type WritableComputedRef } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { FEATURE_CODES, type FeatureCode } from '@/lib/features';
import { venueScopeDefault, venueScopeParam } from '@/lib/dietCatalog';
import type { RestaurantSearchParams } from '@/types';

/**
 * 篩選條件在網址上用的參數名。逐個參數而不是壓成一個字串，理由是網址本身要看得懂：
 * `?diet=vegan&takeout=1` 一眼就知道在篩什麼，`?f=dGVzdA` 只有程式看得懂，
 * 使用者回報問題時也貼不出有意義的資訊。
 */
function filterKeys(): string[] {
    return ['diet', venueScopeParam(), 'price_level', 'open_now', ...FEATURE_CODES];
}

/** 後端 SearchRestaurantRequest 驗證 price_level 是 1–4 的整數。 */
export const PRICE_LEVELS = [1, 2, 3, 4] as const;

export type UrlFilters = Pick<RestaurantSearchParams, 'diet' | 'venue_scope' | 'price_level' | 'open_now'> &
    Partial<Record<FeatureCode, boolean>>;

/** 布林篩選在網址上一律寫成 1；沒有這個參數就代表沒開，不用 0 佔位。 */
const ON = '1';

function isFeatureCode(key: string): key is FeatureCode {
    return (FEATURE_CODES as readonly string[]).includes(key);
}

function parse(query: Record<string, unknown>): Partial<UrlFilters> {
    const filters: Partial<UrlFilters> = {};
    const scopeParam = venueScopeParam();

    if (typeof query.diet === 'string' && query.diet !== '') {
        filters.diet = query.diet;
    }

    const scope = query[scopeParam];
    if (typeof scope === 'string' && scope !== '') {
        filters.venue_scope = scope;
    }

    // 數值篩選要驗證範圍再採用：網址是使用者可以隨手改的，帶 price_level=99 過來
    // 後端會回 422，整個列表變成「載入失敗」，而不是忽略一個無效條件。
    const price = Number(query.price_level);
    if ((PRICE_LEVELS as readonly number[]).includes(price)) {
        filters.price_level = price;
    }

    // open_now 不是 features.code（它不是店家屬性，是「此刻」的狀態），
    // 但走同一套 `=1` 的網址約定。
    if (query.open_now === ON) {
        filters.open_now = true;
    }

    for (const code of FEATURE_CODES) {
        if (query[code] === ON) {
            filters[code] = true;
        }
    }

    return filters;
}

/**
 * 篩選條件的真相來源是網址，跟 city／keyword 一致：重新整理、分享連結、上一頁三件事
 * 因此自動都對。回傳可寫的 computed，直接接 `v-model:filters` 即可。
 */
export function useFilterQuery(): WritableComputedRef<Partial<UrlFilters>> {
    const route = useRoute();
    const router = useRouter();

    return computed({
        get: () => parse(route.query as Record<string, unknown>),
        set: (next) => {
            const query: LocationQueryRaw = { ...route.query };
            const scopeParam = venueScopeParam();
            const scopeDefault = venueScopeDefault();

            // 先把這幾個參數清掉再重寫，否則被關掉的條件會留在網址上。
            for (const key of filterKeys()) {
                delete query[key];
            }

            if (next.diet) query.diet = next.diet;

            if (next.venue_scope && next.venue_scope !== scopeDefault) {
                query[scopeParam] = next.venue_scope;
            }

            if (next.price_level) query.price_level = String(next.price_level);

            if (next.open_now) query.open_now = ON;

            for (const code of FEATURE_CODES) {
                if (next[code]) query[code] = ON;
            }

            router.push({ query });
        },
    });
}

/** 給查詢觸發用的穩定 key——物件每次 get 都是新的，不能直接拿來比較。 */
export function filterQueryKey(filters: Partial<UrlFilters>): string {
    return filterKeys()
        .map((key) => `${key}=${filters[key as keyof UrlFilters] ?? ''}`)
        .join('&');
}

/**
 * 送給 API 的參數。布林要轉成 1——axios 會把 `true` 序列化成字串 `"true"`，而 Laravel 的
 * `boolean` 規則不吃 `"true"`（只吃 1/0/"1"/"0" 與真布林），會直接回 422。
 *
 * 這是 2026-08-25 才發現的既有 bug：「寵物友善」「停車」兩個篩選從一開始就送 `parking=true`，
 * 也就是從來沒有真正運作過；舊版前端沒有錯誤處理，422 被靜默吞掉、畫面只是沒更新，
 * 所以一直沒人發現。
 *
 * `venue_scope` 省略時後端不過濾（全部）。前端預設送 catalog 的 default（目前 exclusive），
 * 避免地圖一進來把友善店跟純素食店混在一起。
 */
export function apiFilterParams(filters: Partial<UrlFilters>): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    const scopeParam = venueScopeParam();

    if (filters.diet) params.diet = filters.diet;
    if (filters.price_level) params.price_level = filters.price_level;
    if (filters.open_now) params.open_now = 1;

    params[scopeParam] = filters.venue_scope ?? venueScopeDefault();

    for (const key of Object.keys(filters)) {
        if (isFeatureCode(key) && filters[key]) {
            params[key] = 1;
        }
    }

    return params;
}
