import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { setViewportMatches } from '@/test/setup';

const cities = [
    { slug: 'taipei', label: '台北', country: '台灣', center: [25.033, 121.5654], zoom: 13, bbox: '24.9613,121.4570,25.2130,121.6663' },
    { slug: 'taichung', label: '台中', country: '台灣', center: [24.1477, 120.6736], zoom: 13, bbox: '23.9500,120.4300,24.4500,121.4700' },
    { slug: 'tokyo', label: '東京', country: '日本', center: [35.6762, 139.6503], zoom: 12, bbox: '35.5300,139.5600,35.8200,139.9200' },
];

function fakeRestaurant(id: number) {
    return { id, name: `餐廳 ${id}`, address: `地址 ${id}`, latitude: 25.03, longitude: 121.56, rating: 4.2, rating_count: 10 };
}

let listPayload: { data: unknown[]; meta?: Record<string, unknown> } = { data: [] };
const restaurantCalls: Record<string, unknown>[] = [];

const get = vi.fn((url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/cities') return Promise.resolve({ data: { data: cities } });
    if (url === '/restaurants') {
        restaurantCalls.push(config?.params ?? {});

        return Promise.resolve({ data: listPayload });
    }

    // FilterDrawer 要有這兩份清單才畫得出晶片，少了它篩選相關的測試會測到空畫面。
    if (url === '/diets') {
        return Promise.resolve({
            data: { data: [{ code: 'vegan', label: '全素（Vegan）' }, { code: 'lacto', label: '奶素（Lacto）' }] },
        });
    }

    if (url === '/features') {
        return Promise.resolve({
            data: {
                data: [
                    { code: 'pet_friendly', label: '寵物友善' },
                    { code: 'parking', label: '停車' },
                    { code: 'takeout', label: '外帶' },
                ],
            },
        });
    }

    return Promise.resolve({ data: { data: [] } });
});

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...(args as [string, { params?: Record<string, unknown> }])) },
}));

const RestaurantListView = (await import('./RestaurantListView.vue')).default;

function makeRouter(): Router {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/restaurants', name: 'restaurants', component: RestaurantListView },
            { path: '/restaurants/:id', name: 'restaurant-detail', component: { template: '<div />' } },
        ],
    });
}

async function mountList(url = '/restaurants') {
    const router = makeRouter();
    await router.push(url);
    await router.isReady();

    const wrapper = mount(RestaurantListView, { global: { plugins: [router] } });
    await flushPromises();
    await flushPromises();

    return { wrapper, router };
}

function lastRestaurantCall(): Record<string, unknown> {
    return restaurantCalls[restaurantCalls.length - 1];
}

describe('RestaurantListView 城市切換', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        listPayload = { data: [fakeRestaurant(1)], meta: { next_cursor: null } };
    });

    it('預設列出全部城市，不帶 bbox——維持這一頁原本的行為', async () => {
        const { wrapper } = await mountList('/restaurants');

        expect(lastRestaurantCall().bbox).toBeUndefined();
        expect(wrapper.find('.scope').text()).toContain('全部城市');
    });

    it('網址指定城市時用該城市的 bbox 收窄', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung');

        expect(lastRestaurantCall().bbox).toBe('23.9500,120.4300,24.4500,121.4700');
        expect(wrapper.find('.scope').text()).toContain('台中');
    });

    it('用 bbox 而不是 city 欄位——匯入資料有 59% 的 city 是空的', async () => {
        await mountList('/restaurants?city=tokyo');

        expect(lastRestaurantCall().bbox).toBe('35.5300,139.5600,35.8200,139.9200');
        expect(lastRestaurantCall().city).toBeUndefined();
    });

    it('切換城市會改網址並重新查詢', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taipei');
        const before = restaurantCalls.length;

        const tokyo = wrapper.findAll('.city').find((b) => b.text() === '東京')!;
        await tokyo.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.city).toBe('tokyo');
        expect(restaurantCalls.length).toBeGreaterThan(before);
        expect(lastRestaurantCall().bbox).toBe('35.5300,139.5600,35.8200,139.9200');
    });

    it('切回「全部」會拿掉 bbox', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung');
        expect(lastRestaurantCall().bbox).toBeDefined();

        const all = wrapper.findAll('.city').find((b) => b.text() === '全部')!;
        await all.trigger('click');
        await flushPromises();

        expect(lastRestaurantCall().bbox).toBeUndefined();
    });

    it('網址帶不認識的城市時退回全部，而不是查一個不存在的範圍', async () => {
        const { wrapper } = await mountList('/restaurants?city=atlantis');

        expect(lastRestaurantCall().bbox).toBeUndefined();
        expect(wrapper.find('.scope').text()).toContain('全部城市');
    });

    it('搜尋關鍵字時保留目前城市範圍', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung');

        await wrapper.find('input[type="search"]').setValue('素食');
        await wrapper.find('.toolbar button').trigger('click');
        await flushPromises();

        expect(lastRestaurantCall().keyword).toBe('素食');
        expect(lastRestaurantCall().bbox).toBe('23.9500,120.4300,24.4500,121.4700');
    });

    it('查無結果時的說明會指出目前限定在哪個城市', async () => {
        listPayload = { data: [], meta: { next_cursor: null } };

        const { wrapper } = await mountList('/restaurants?city=tokyo');

        expect(wrapper.find('.notice').text()).toContain('東京沒有符合條件的餐廳');
        expect(wrapper.find('.notice').text()).toContain('切換到其他城市');
    });
});

describe('RestaurantListView 分頁與計數', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
    });

    it('還有下一頁時計數顯示 +，不把已載入筆數當成總數', async () => {
        listPayload = { data: Array.from({ length: 20 }, (_, i) => fakeRestaurant(i)), meta: { next_cursor: 'abc' } };

        const { wrapper } = await mountList('/restaurants?city=taipei');

        expect(wrapper.find('.scope').text()).toContain('20+');
    });

    it('載入更多會帶上 cursor 並接在現有結果後面', async () => {
        listPayload = { data: [fakeRestaurant(1)], meta: { next_cursor: 'cur-1' } };
        const { wrapper } = await mountList('/restaurants?city=taipei');

        listPayload = { data: [fakeRestaurant(2)], meta: { next_cursor: null } };
        await wrapper.find('.more').trigger('click');
        await flushPromises();

        expect(lastRestaurantCall().cursor).toBe('cur-1');
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('查詢失敗時顯示錯誤，不是靜默留著舊資料', async () => {
        listPayload = { data: [fakeRestaurant(1)], meta: { next_cursor: null } };
        const { wrapper } = await mountList('/restaurants?city=taipei');

        get.mockImplementationOnce(() => Promise.reject(new Error('boom')));
        await wrapper.find('.toolbar button').trigger('click');
        await flushPromises();

        expect(wrapper.find('.notice.error').exists()).toBe(true);
        expect(wrapper.findAll('li')).toHaveLength(0);
    });
});

describe('RestaurantListView 關鍵字進網址', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        listPayload = { data: [fakeRestaurant(1)], meta: { next_cursor: null } };
    });

    it('網址帶關鍵字時，開頁就套用並回填輸入框', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung&keyword=素食');

        expect(lastRestaurantCall().keyword).toBe('素食');
        expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('素食');
    });

    it('按下搜尋才寫進網址——打字不該每個鍵都推一筆歷史紀錄', async () => {
        const { wrapper, router } = await mountList('/restaurants');

        await wrapper.find('input[type="search"]').setValue('蔬食');
        expect(router.currentRoute.value.query.keyword).toBeUndefined();

        await wrapper.find('.toolbar button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBe('蔬食');
    });

    it('Enter 與按鈕行為一致', async () => {
        const { wrapper, router } = await mountList('/restaurants');

        await wrapper.find('input[type="search"]').setValue('拉麵');
        await wrapper.find('input[type="search"]').trigger('keyup.enter');
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBe('拉麵');
    });

    it('關鍵字與城市同時存在於網址，兩者都套用', async () => {
        await mountList('/restaurants?city=tokyo&keyword=ramen');

        expect(lastRestaurantCall().keyword).toBe('ramen');
        expect(lastRestaurantCall().bbox).toBe('35.5300,139.5600,35.8200,139.9200');
    });

    it('換城市時保留關鍵字', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taipei&keyword=素食');

        const tokyo = wrapper.findAll('.city').find((b) => b.text() === '東京')!;
        await tokyo.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBe('素食');
        expect(lastRestaurantCall().keyword).toBe('素食');
        expect(lastRestaurantCall().bbox).toBe('35.5300,139.5600,35.8200,139.9200');
    });

    it('清除關鍵字會把它從網址移除，城市留著', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taichung&keyword=素食');

        await wrapper.find('.clear-keyword').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBeUndefined();
        expect(router.currentRoute.value.query.city).toBe('taichung');
        expect(lastRestaurantCall().keyword).toBeUndefined();
        expect(lastRestaurantCall().bbox).toBe('23.9500,120.4300,24.4500,121.4700');
    });

    it('沒有關鍵字時不顯示清除鈕', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung');

        expect(wrapper.find('.clear-keyword').exists()).toBe(false);
    });

    it('空白關鍵字不會進網址', async () => {
        const { wrapper, router } = await mountList('/restaurants');

        await wrapper.find('input[type="search"]').setValue('   ');
        await wrapper.find('.toolbar button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBeUndefined();
    });

    it('上一頁會回到前一次的搜尋，輸入框跟著回填', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taipei');

        await wrapper.find('input[type="search"]').setValue('素食');
        await wrapper.find('.toolbar button').trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.query.keyword).toBe('素食');

        await router.back();
        await flushPromises();

        expect(router.currentRoute.value.query.keyword).toBeUndefined();
        expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('');
    });

    it('查無結果時的建議會提到換關鍵字', async () => {
        listPayload = { data: [], meta: { next_cursor: null } };

        const { wrapper } = await mountList('/restaurants?city=tokyo&keyword=不存在的店');

        const text = wrapper.find('.notice').text();
        expect(text).toContain('不存在的店');
        expect(text).toContain('換個關鍵字');
        expect(text).toContain('切換到其他城市');
    });

    it('沒下關鍵字時不會建議「換個關鍵字」', async () => {
        listPayload = { data: [], meta: { next_cursor: null } };

        const { wrapper } = await mountList('/restaurants?city=tokyo');

        expect(wrapper.find('.notice').text()).not.toContain('換個關鍵字');
    });
});

describe('RestaurantListView 篩選進網址', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        listPayload = { data: [fakeRestaurant(1)], meta: { next_cursor: null } };
    });

    it('網址帶篩選時開頁就套用，晶片也是選取狀態', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung&diet=vegan&parking=1');

        expect(lastRestaurantCall().diet).toBe('vegan');
        expect(lastRestaurantCall().parking).toBe(1);
        expect(wrapper.findAll('.chip.active').map((c) => c.text())).toContain('停車');
    });

    it('點晶片會寫進網址並重查', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taichung');
        const before = restaurantCalls.length;

        await wrapper.findAll('.chip').find((c) => c.text() === '停車')!.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.parking).toBe('1');
        expect(restaurantCalls.length).toBeGreaterThan(before);
        expect(lastRestaurantCall().parking).toBe(1);
        expect(lastRestaurantCall().bbox).toBe('23.9500,120.4300,24.4500,121.4700');
    });

    it('取消晶片會把參數從網址移除', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taichung&parking=1');

        await wrapper.findAll('.chip').find((c) => c.text() === '停車')!.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.parking).toBeUndefined();
        expect(lastRestaurantCall().parking).toBeUndefined();
    });

    it('清除篩選只清篩選，city 與 keyword 留著', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taichung&keyword=素食&diet=vegan&parking=1');

        await wrapper.find('.clear').trigger('click');
        await flushPromises();

        const query = router.currentRoute.value.query;
        expect(query.diet).toBeUndefined();
        expect(query.parking).toBeUndefined();
        expect(query.city).toBe('taichung');
        expect(query.keyword).toBe('素食');
    });

    it('城市／關鍵字／篩選三者可共存於網址', async () => {
        await mountList('/restaurants?city=tokyo&keyword=ramen&diet=vegan');

        const call = lastRestaurantCall();
        expect(call.bbox).toBe('35.5300,139.5600,35.8200,139.9200');
        expect(call.keyword).toBe('ramen');
        expect(call.diet).toBe('vegan');
    });

    it('改篩選只送一發請求，不是先舊後新兩發', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung');
        const before = restaurantCalls.length;

        await wrapper.findAll('.chip').find((c) => c.text() === '停車')!.trigger('click');
        await flushPromises();

        expect(restaurantCalls.length - before).toBe(1);
    });

    it('上一頁會回到套用篩選之前的狀態', async () => {
        const { wrapper, router } = await mountList('/restaurants?city=taichung');

        await wrapper.findAll('.chip').find((c) => c.text() === '停車')!.trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.query.parking).toBe('1');

        await router.back();
        await flushPromises();

        expect(router.currentRoute.value.query.parking).toBeUndefined();
        expect(lastRestaurantCall().parking).toBeUndefined();
    });

    it('外帶篩選跟停車一樣走獨立 query 參數', async () => {
        const { wrapper } = await mountList('/restaurants?city=taichung&takeout=1');

        expect(lastRestaurantCall().takeout).toBe(1);
        expect(wrapper.findAll('.chip.active').map((c) => c.text())).toContain('外帶');
    });

    it('空地址不佔一行空白', async () => {
        listPayload = { data: [{ ...fakeRestaurant(1), address: '' }], meta: { next_cursor: null } };

        const { wrapper } = await mountList('/restaurants?city=taichung');

        expect(wrapper.find('.address').exists()).toBe(false);
        expect(wrapper.find('strong').text()).toBe('餐廳 1');
    });
});
