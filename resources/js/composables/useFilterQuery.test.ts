import { beforeEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { apiFilterParams, filterQueryKey, useFilterQuery } from './useFilterQuery';
import type { UrlFilters } from './useFilterQuery';
import { resetVenueScopeMeta } from '@/lib/dietCatalog';

/** 這個 composable 依賴 route/router，所以掛在一個最小元件裡測，不直接呼叫。 */
const Harness = defineComponent({
    setup(_, { expose }) {
        const filters = useFilterQuery();
        expose({ filters });

        return () => h('div');
    },
});

async function mountHarness(url: string) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/restaurants', component: Harness }],
    });
    await router.push(url);
    await router.isReady();

    const wrapper = mount(Harness, { global: { plugins: [router] } });

    return {
        router,
        get filters(): Partial<UrlFilters> {
            return (wrapper.vm as unknown as { filters: Partial<UrlFilters> }).filters;
        },
        set filters(value: Partial<UrlFilters>) {
            (wrapper.vm as unknown as { filters: Partial<UrlFilters> }).filters = value;
        },
    };
}

describe('useFilterQuery 讀取網址', () => {
    beforeEach(() => {
        resetVenueScopeMeta();
    });
    it('沒有篩選參數時是空物件', async () => {
        const h = await mountHarness('/restaurants');

        expect(h.filters).toEqual({});
    });

    it('讀得出飲食類型', async () => {
        const h = await mountHarness('/restaurants?diet=vegan');

        expect(h.filters).toEqual({ diet: 'vegan' });
    });

    it('布林篩選只認 1', async () => {
        expect((await mountHarness('/restaurants?parking=1')).filters).toEqual({ parking: true });
        expect((await mountHarness('/restaurants?takeout=1')).filters).toEqual({ takeout: true });

        // 0／true／空字串都不算開啟——網址上「沒有這個參數」才是關閉，不用 0 佔位。
        expect((await mountHarness('/restaurants?parking=0')).filters).toEqual({});
        expect((await mountHarness('/restaurants?parking=true')).filters).toEqual({});
        expect((await mountHarness('/restaurants?parking=')).filters).toEqual({});
    });

    it('多個條件同時存在', async () => {
        const h = await mountHarness('/restaurants?diet=vegan&pet_friendly=1&parking=1');

        expect(h.filters).toEqual({ diet: 'vegan', pet_friendly: true, parking: true });
    });

    it('空的 diet 不算條件', async () => {
        expect((await mountHarness('/restaurants?diet=')).filters).toEqual({});
    });

    it('讀得出 venue_scope', async () => {
        expect((await mountHarness('/restaurants?venue_scope=friendly')).filters).toEqual({
            venue_scope: 'friendly',
        });
    });
});

describe('useFilterQuery 寫回網址', () => {
    beforeEach(() => {
        resetVenueScopeMeta();
    });
    it('設定條件會寫進網址', async () => {
        const h = await mountHarness('/restaurants');

        h.filters = { diet: 'vegan', parking: true };
        await flushPromises();

        expect(h.router.currentRoute.value.query.diet).toBe('vegan');
        expect(h.router.currentRoute.value.query.parking).toBe('1');
        expect(h.router.currentRoute.value.query.pet_friendly).toBeUndefined();
    });

    it('關掉的條件會從網址移除，不是留一個 0', async () => {
        // 留著 0 會讓網址愈用愈長，而且「關閉」跟「沒設定」變成兩種表示法。
        const h = await mountHarness('/restaurants?diet=vegan&parking=1');

        h.filters = { diet: 'vegan' };
        await flushPromises();

        expect(h.router.currentRoute.value.query.parking).toBeUndefined();
        expect(h.router.currentRoute.value.query.diet).toBe('vegan');
    });

    it('清空條件會把篩選參數都拿掉', async () => {
        const h = await mountHarness('/restaurants?diet=vegan&pet_friendly=1&parking=1&takeout=1');

        h.filters = {};
        await flushPromises();

        const query = h.router.currentRoute.value.query;
        expect(query.diet).toBeUndefined();
        expect(query.pet_friendly).toBeUndefined();
        expect(query.parking).toBeUndefined();
        expect(query.takeout).toBeUndefined();
    });

    it('不會動到其他人的參數', async () => {
        // city／keyword 是別的功能在管的，篩選改動不該把它們洗掉。
        const h = await mountHarness('/restaurants?city=taichung&keyword=%E7%B4%A0%E9%A3%9F&diet=vegan');

        h.filters = { parking: true };
        await flushPromises();

        expect(h.router.currentRoute.value.query.city).toBe('taichung');
        expect(h.router.currentRoute.value.query.keyword).toBe('素食');
        expect(h.router.currentRoute.value.query.diet).toBeUndefined();
        expect(h.router.currentRoute.value.query.parking).toBe('1');
    });

    it('預設 venue_scope 不寫進網址，非預設才寫', async () => {
        const h = await mountHarness('/restaurants');

        h.filters = { venue_scope: 'exclusive' };
        await flushPromises();
        expect(h.router.currentRoute.value.query.venue_scope).toBeUndefined();

        h.filters = { venue_scope: 'friendly' };
        await flushPromises();
        expect(h.router.currentRoute.value.query.venue_scope).toBe('friendly');
    });
});

describe('filterQueryKey', () => {
    it('相同條件產生相同 key，不同條件產生不同 key', async () => {
        // 每次讀 filters 都是新物件，不能直接比較物件本身當查詢觸發條件。
        expect(filterQueryKey({ diet: 'vegan' })).toBe(filterQueryKey({ diet: 'vegan' }));
        expect(filterQueryKey({ diet: 'vegan' })).not.toBe(filterQueryKey({ diet: 'lacto' }));
        expect(filterQueryKey({})).not.toBe(filterQueryKey({ parking: true }));
    });

    it('key 不受屬性順序影響', () => {
        expect(filterQueryKey({ diet: 'vegan', parking: true })).toBe(
            filterQueryKey({ parking: true, diet: 'vegan' }),
        );
    });
});

describe('apiFilterParams', () => {
    it('布林送 1 而不是 true——送 true 後端會回 422', () => {
        // axios 會把 true 序列化成字串 "true"，Laravel 的 boolean 規則不吃這個值。
        // 2026-08-25 實測：`parking=true` 回「The parking field must be true or false.」。
        expect(apiFilterParams({ parking: true, pet_friendly: true, takeout: true })).toEqual({
            venue_scope: 'exclusive',
            parking: 1,
            pet_friendly: 1,
            takeout: 1,
        });
    });

    it('沒開的條件完全不出現在參數裡，但 venue_scope 預設會送 exclusive', () => {
        expect(apiFilterParams({})).toEqual({ venue_scope: 'exclusive' });
        expect(apiFilterParams({ diet: 'vegan' })).toEqual({ diet: 'vegan', venue_scope: 'exclusive' });
        expect(apiFilterParams({ venue_scope: 'all' })).toEqual({ venue_scope: 'all' });
    });
});

describe('useFilterQuery 價位與評分', () => {
    it('讀得出網址上的價位與最低評分', async () => {
        const h = await mountHarness('/restaurants?price_level=2&rating_min=4');

        expect(h.filters.price_level).toBe(2);
        expect(h.filters.rating_min).toBe(4);
    });

    it('超出後端允許範圍的值直接忽略，不是原封不動送出去', async () => {
        // 網址是使用者隨手可改的。price_level=99 送到後端會回 422，整個列表變成
        // 「載入失敗」——那是把一個無效參數變成整頁壞掉，忽略它才對。
        expect((await mountHarness('/restaurants?price_level=99')).filters.price_level).toBeUndefined();
        expect((await mountHarness('/restaurants?price_level=0')).filters.price_level).toBeUndefined();
        expect((await mountHarness('/restaurants?price_level=abc')).filters.price_level).toBeUndefined();
        expect((await mountHarness('/restaurants?rating_min=9')).filters.rating_min).toBeUndefined();
        expect((await mountHarness('/restaurants?rating_min=-1')).filters.rating_min).toBeUndefined();
    });

    it('寫回網址時是數字字串，取消後參數消失', async () => {
        const h = await mountHarness('/restaurants');

        h.filters = { price_level: 3, rating_min: 4.5 };
        await flushPromises();
        expect(h.router.currentRoute.value.query.price_level).toBe('3');
        expect(h.router.currentRoute.value.query.rating_min).toBe('4.5');

        h.filters = {};
        await flushPromises();
        expect(h.router.currentRoute.value.query.price_level).toBeUndefined();
        expect(h.router.currentRoute.value.query.rating_min).toBeUndefined();
    });

    it('送給 API 的是數字，不是字串', async () => {
        // 後端驗證 price_level 是 integer、rating_min 是 numeric。
        expect(apiFilterParams({ price_level: 2, rating_min: 4 })).toMatchObject({
            price_level: 2,
            rating_min: 4,
        });
    });

    it('價位與評分會進查詢 key，改了要能觸發重查', () => {
        expect(filterQueryKey({ price_level: 2 })).not.toBe(filterQueryKey({ price_level: 3 }));
        expect(filterQueryKey({ rating_min: 4 })).not.toBe(filterQueryKey({}));
    });
});
