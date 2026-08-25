import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { apiFilterParams, filterQueryKey, useFilterQuery } from './useFilterQuery';
import type { UrlFilters } from './useFilterQuery';

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
});

describe('useFilterQuery 寫回網址', () => {
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

    it('清空條件會把三個參數都拿掉', async () => {
        const h = await mountHarness('/restaurants?diet=vegan&pet_friendly=1&parking=1');

        h.filters = {};
        await flushPromises();

        const query = h.router.currentRoute.value.query;
        expect(query.diet).toBeUndefined();
        expect(query.pet_friendly).toBeUndefined();
        expect(query.parking).toBeUndefined();
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
        expect(apiFilterParams({ parking: true, pet_friendly: true })).toEqual({
            parking: 1,
            pet_friendly: 1,
        });
    });

    it('沒開的條件完全不出現在參數裡', () => {
        expect(apiFilterParams({})).toEqual({});
        expect(apiFilterParams({ diet: 'vegan' })).toEqual({ diet: 'vegan' });
    });
});
