import { computed, type WritableComputedRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * 搜尋範圍（A5）。兩頁共用同一組值，語意各自對應：
 * - `map`：目前地圖視角（首頁預設；列表頁沒有地圖，不會出現這個選項，
 *   但網址上帶著它時列表頁會退回 `city` 處理——見 RestaurantListView 的註解）
 * - `city`：目前選的城市（列表頁預設）
 * - `all`：不限城市
 */
export const SEARCH_SCOPES = ['map', 'city', 'all'] as const;
export type SearchScope = (typeof SEARCH_SCOPES)[number];

function isSearchScope(value: unknown): value is SearchScope {
    return typeof value === 'string' && (SEARCH_SCOPES as readonly string[]).includes(value);
}

/**
 * 搜尋範圍是網址上的單一真相來源，跟 city／keyword／filters 同一套約定
 * （見 useCities、useFilterQuery）：重新整理、分享連結、上一頁因此都對。
 *
 * 是預設值時網址上不寫 `scope`（跟 useCities 的 fallback 同一個理由）：
 * 「沒有明講」與「明講了但剛好等於預設」在網址上長得一樣，才不會每個
 * 頁面一打開就多一個看起來像使用者選過的參數。
 *
 * **這一版沒有「打了關鍵字就自動變成 all」的特例**——那正是這一批要拿掉的東西
 * （見 plan-2026-09-search-ux.md A5）：範圍原本兩頁不一樣、使用者改不了，
 * 現在兩頁共用同一顆看得到、按得到的控制項，行為由使用者決定，不是程式
 * 幫他決定。首頁預設 `map`、列表頁預設 `city`，跟兩頁原本「非關鍵字」時
 * 的行為完全一致——真正改變的只有「打了關鍵字」那個分支不再被強制忽略範圍。
 */
export function useSearchScope(defaultScope: SearchScope): WritableComputedRef<SearchScope> {
    const route = useRoute();
    const router = useRouter();

    return computed<SearchScope>({
        get: () => {
            const raw = route.query.scope;

            return isSearchScope(raw) ? raw : defaultScope;
        },
        set: (value) => {
            const query = { ...route.query };

            if (value === defaultScope) {
                delete query.scope;
            } else {
                query.scope = value;
            }

            router.push({ query });
        },
    });
}
