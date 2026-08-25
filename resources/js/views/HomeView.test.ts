import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { setViewportMatches } from '@/test/setup';

const cities = [
    { slug: 'taipei', label: '台北', country: '台灣', center: [25.033, 121.5654], zoom: 13, bbox: '24.9613,121.4570,25.2130,121.6663' },
    { slug: 'taichung', label: '台中', country: '台灣', center: [24.1477, 120.6736], zoom: 13, bbox: '23.9500,120.4300,24.4500,121.4700' },
    { slug: 'tokyo', label: '東京', country: '日本', center: [35.6762, 139.6503], zoom: 12, bbox: '35.5300,139.5600,35.8200,139.9200' },
];

/** 貼近 API 真實形狀——少了 rating 之類的欄位，測試會炸在 renderMarkers 而不是測到重點。 */
function fakeRestaurant(id: number) {
    return {
        id,
        name: `餐廳 ${id}`,
        address: `地址 ${id}`,
        latitude: 25.03 + id / 1000,
        longitude: 121.56 + id / 1000,
        rating: 4.2,
        rating_count: 10,
    };
}

let restaurantsPayload: { data: unknown[]; meta?: Record<string, unknown> } = { data: [] };
const restaurantCalls: Record<string, unknown>[] = [];
const recommendedCalls: Record<string, unknown>[] = [];

const get = vi.fn((url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/cities') return Promise.resolve({ data: { data: cities } });
    if (url === '/restaurants') {
        restaurantCalls.push(config?.params ?? {});
        return Promise.resolve({ data: restaurantsPayload });
    }
    if (url === '/restaurants/recommended') {
        recommendedCalls.push(config?.params ?? {});
        return Promise.resolve({ data: { data: [] } });
    }
    if (url === '/diets') return Promise.resolve({ data: { data: [] } });

    return Promise.resolve({ data: { data: [] } });
});

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...(args as [string, { params?: Record<string, unknown> }])) },
}));

const mapStub = {
    setView: vi.fn().mockReturnThis(),
    flyTo: vi.fn().mockReturnThis(),
    addLayer: vi.fn(),
    on: vi.fn(),
    remove: vi.fn(),
    getCenter: vi.fn(() => ({ lat: 25.033, lng: 121.5654 })),
    getBounds: vi.fn(() => ({ getSouth: () => 24.9, getWest: () => 121.4, getNorth: () => 25.1, getEast: () => 121.7 })),
};

vi.mock('leaflet', () => ({
    default: {
        map: vi.fn(() => mapStub),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        markerClusterGroup: vi.fn(() => ({ clearLayers: vi.fn(), addLayer: vi.fn() })),
        marker: vi.fn(() => ({ bindPopup: vi.fn().mockReturnThis(), on: vi.fn() })),
    },
}));
vi.mock('leaflet.markercluster', () => ({}));

const HomeView = (await import('./HomeView.vue')).default;

function makeRouter(): Router {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: HomeView },
            { path: '/restaurants/:id', name: 'restaurant-detail', component: { template: '<div />' } },
        ],
    });
}

async function mountHome(initialUrl = '/') {
    const router = makeRouter();
    await router.push(initialUrl);
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [router] } });
    await flushPromises();
    await flushPromises();

    return { wrapper, router };
}

function activeCityLabel(wrapper: { findAll: (s: string) => { text: () => string }[] }): string | undefined {
    return wrapper.findAll('.city.active')[0]?.text();
}

describe('HomeView 多城市切換', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        recommendedCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        restaurantsPayload = { data: [] };
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('網址指定哪個城市就顯示哪個', async () => {
        const { wrapper } = await mountHome('/?city=tokyo');

        expect(activeCityLabel(wrapper)).toBe('東京');
    });

    it('網址沒指定時沿用上次看的城市', async () => {
        localStorage.setItem('veggiemap:last-city', 'taichung');

        const { router } = await mountHome('/');

        expect(router.currentRoute.value.query.city).toBe('taichung');
    });

    it('網址與 localStorage 都沒有時用清單第一個', async () => {
        const { router } = await mountHome('/');

        expect(router.currentRoute.value.query.city).toBe('taipei');
    });

    it('網址帶不認識的城市時退回第一個，不是留白', async () => {
        // 使用者改網址、或我們日後移除某個城市時，畫面不能因此變成沒有地圖的空殼。
        const { wrapper } = await mountHome('/?city=atlantis');

        expect(activeCityLabel(wrapper)).toBe('台北');
    });

    it('選城市只改網址——網址是唯一真相來源，上一頁才會正確', async () => {
        const { wrapper, router } = await mountHome('/?city=taipei');

        await wrapper.findAll('.city')[2].trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.city).toBe('tokyo');
    });

    it('切換城市時用該城市自己的中心點與 zoom 直接跳，不做長距離飛行動畫', async () => {
        const { wrapper } = await mountHome('/?city=taipei');
        mapStub.setView.mockClear();
        mapStub.flyTo.mockClear();

        await wrapper.findAll('.city')[2].trigger('click');
        await flushPromises();

        expect(mapStub.setView).toHaveBeenCalledWith([35.6762, 139.6503], 12);
        expect(mapStub.flyTo).not.toHaveBeenCalled();
    });

    it('記住最後選的城市', async () => {
        const { wrapper } = await mountHome('/?city=taipei');

        await wrapper.findAll('.city')[1].trigger('click');
        await flushPromises();

        expect(localStorage.getItem('veggiemap:last-city')).toBe('taichung');
    });

    it('第一次載入不會多飛一次——地圖已經開在正確位置', async () => {
        const { wrapper } = await mountHome('/?city=tokyo');

        expect(activeCityLabel(wrapper)).toBe('東京');
        expect(mapStub.flyTo).not.toHaveBeenCalled();
    });
});

describe('HomeView 結果計數', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        recommendedCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('還有下一頁時顯示 100+，不把上限當成總數', async () => {
        // 後端是 cursor 分頁、不回總數，per_page=100 是上限。說「有 100 家」會是謊話。
        restaurantsPayload = { data: Array.from({ length: 100 }, (_, i) => fakeRestaurant(i)), meta: { next_cursor: 'abc' } };

        const { wrapper } = await mountHome('/?city=taipei');

        expect(wrapper.find('.map-badge').text()).toContain('100+');
    });

    it('沒有下一頁時顯示實際筆數', async () => {
        restaurantsPayload = { data: Array.from({ length: 7 }, (_, i) => fakeRestaurant(i)), meta: { next_cursor: null } };

        const { wrapper } = await mountHome('/?city=taipei');

        expect(wrapper.find('.map-badge').text()).toContain('7 家');
        expect(wrapper.find('.map-badge').text()).not.toContain('+');
    });

    it('查無結果時給出可行動的說明，不是一片空白地圖', async () => {
        restaurantsPayload = { data: [], meta: { next_cursor: null } };

        const { wrapper } = await mountHome('/?city=taipei');

        expect(wrapper.find('.empty-state').exists()).toBe(true);
        expect(wrapper.find('.empty-state').text()).toContain('切換到其他城市');
    });
});

describe('HomeView 篩選也套到推薦', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        recommendedCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        restaurantsPayload = { data: [] };
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('推薦 API 帶上目前的特色篩選，不是另外撈一組沒篩過的', async () => {
        await mountHome('/?city=taichung&takeout=1');

        expect(recommendedCalls[recommendedCalls.length - 1]?.takeout).toBe(1);
        expect(recommendedCalls[recommendedCalls.length - 1]?.venue_scope).toBe('exclusive');
    });
});

describe('HomeView 地圖範圍', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        restaurantCalls.length = 0;
        recommendedCalls.length = 0;
        localStorage.clear();
        setViewportMatches(true);
        restaurantsPayload = { data: [] };
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('列表與推薦送 bbox，不是會超過 50km 上限的 radius', async () => {
        await mountHome('/?city=taichung');

        const restaurantsParams = restaurantCalls[restaurantCalls.length - 1];
        const recommendedParams = recommendedCalls[recommendedCalls.length - 1];

        expect(restaurantsParams?.bbox).toBe('24.9,121.4,25.1,121.7');
        expect(restaurantsParams).not.toHaveProperty('radius');
        expect(recommendedParams?.bbox).toBe('24.9,121.4,25.1,121.7');
        expect(recommendedParams).not.toHaveProperty('radius');
    });
});
