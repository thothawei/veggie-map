import { computed, type WritableComputedRef } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import type { RestaurantSearchParams } from '@/types';

/**
 * 篩選條件在網址上用的參數名。逐個參數而不是壓成一個字串，理由是網址本身要看得懂：
 * `?diet=vegan&parking=1` 一眼就知道在篩什麼，`?f=dGVzdA` 只有程式看得懂，
 * 使用者回報問題時也貼不出有意義的資訊。
 */
const FILTER_KEYS = ['diet', 'pet_friendly', 'parking'] as const;

/** 布林篩選在網址上一律寫成 1；沒有這個參數就代表沒開，不用 0 佔位。 */
const ON = '1';

export type UrlFilters = Pick<RestaurantSearchParams, 'diet' | 'pet_friendly' | 'parking'>;

function parse(query: Record<string, unknown>): Partial<UrlFilters> {
    const filters: Partial<UrlFilters> = {};

    if (typeof query.diet === 'string' && query.diet !== '') {
        filters.diet = query.diet;
    }

    if (query.pet_friendly === ON) {
        filters.pet_friendly = true;
    }

    if (query.parking === ON) {
        filters.parking = true;
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

            // 先把這幾個參數清掉再重寫，否則被關掉的條件會留在網址上。
            for (const key of FILTER_KEYS) {
                delete query[key];
            }

            if (next.diet) query.diet = next.diet;
            if (next.pet_friendly) query.pet_friendly = ON;
            if (next.parking) query.parking = ON;

            router.push({ query });
        },
    });
}

/** 給查詢觸發用的穩定 key——物件每次 get 都是新的，不能直接拿來比較。 */
export function filterQueryKey(filters: Partial<UrlFilters>): string {
    return FILTER_KEYS.map((key) => `${key}=${filters[key] ?? ''}`).join('&');
}

/**
 * 送給 API 的參數。布林要轉成 1——axios 會把 `true` 序列化成字串 `"true"`，而 Laravel 的
 * `boolean` 規則不吃 `"true"`（只吃 1/0/"1"/"0" 與真布林），會直接回 422。
 *
 * 這是 2026-08-25 才發現的既有 bug：「寵物友善」「停車」兩個篩選從一開始就送 `parking=true`，
 * 也就是從來沒有真正運作過；舊版前端沒有錯誤處理，422 被靜默吞掉、畫面只是沒更新，
 * 所以一直沒人發現。
 */
export function apiFilterParams(filters: Partial<UrlFilters>): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    if (filters.diet) params.diet = filters.diet;
    if (filters.pet_friendly) params.pet_friendly = 1;
    if (filters.parking) params.parking = 1;

    return params;
}
