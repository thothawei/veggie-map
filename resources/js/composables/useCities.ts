import { computed, onMounted, ref, type ComputedRef, type Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import client from '@/api/client';
import type { ApiSuccess, City } from '@/types';

const LAST_CITY_KEY = 'veggiemap:last-city';

/** 「全部城市」在網址上的值。列表頁用得到，地圖頁沒有這個概念（地圖一定看著某個地方）。 */
export const ALL_CITIES = 'all';

interface Options {
    /**
     * 網址沒指定、或指定了不認識的 slug 時要退到哪裡：
     * - 'first'：清單第一個城市（地圖頁——沒有城市就沒有地圖中心點可用）
     * - 'all'：不限城市（列表頁——維持它原本「列出全部」的行為）
     */
    fallback: 'first' | 'all';
    /** 記住／沿用上次選的城市。列表頁預設看全部，不套用。 */
    remember?: boolean;
}

export interface UseCities {
    cities: Ref<City[]>;
    loading: Ref<boolean>;
    /** null 代表「全部城市」或尚未載入；用 loading 區分這兩種。 */
    activeCity: ComputedRef<City | null>;
    activeSlug: ComputedRef<string | null>;
    selectCity: (slug: string) => void;
}

/**
 * 網址是「目前在哪個城市」的單一真相來源：選擇器只負責改網址，要做什麼由呼叫端 watch
 * activeCity 決定。這樣上一頁／重新整理／把連結貼給別人三件事自動都對，不用另外維護
 * 一份會跟網址對不起來的內部狀態。
 */
export function useCities(options: Options): UseCities {
    const route = useRoute();
    const router = useRouter();

    const cities = ref<City[]>([]);
    const loading = ref(true);

    const activeCity = computed<City | null>(() => {
        if (!cities.value.length) return null;

        const slug = route.query.city;

        if (options.fallback === 'all' && (slug === ALL_CITIES || slug === undefined)) {
            return null;
        }

        const found = cities.value.find((city) => city.slug === slug);

        if (found) return found;

        return options.fallback === 'first' ? cities.value[0] : null;
    });

    const activeSlug = computed(() => activeCity.value?.slug ?? (options.fallback === 'all' ? ALL_CITIES : null));

    function selectCity(slug: string) {
        router.push({ query: { ...route.query, city: slug } });
    }

    onMounted(async () => {
        try {
            const { data } = await client.get<ApiSuccess<City[]>>('/cities');
            cities.value = data.data;
        } finally {
            loading.value = false;
        }

        if (!cities.value.length || !options.remember || route.query.city) return;

        // 用 replace 而不是 push，免得使用者一進站就在歷史紀錄裡多一筆、按上一頁還回不去。
        const remembered = localStorage.getItem(LAST_CITY_KEY);
        const fallback = cities.value.find((city) => city.slug === remembered) ?? cities.value[0];

        router.replace({ query: { ...route.query, city: fallback.slug } });
    });

    return { cities, loading, activeCity, activeSlug, selectCity };
}

export function rememberCity(slug: string): void {
    localStorage.setItem(LAST_CITY_KEY, slug);
}
