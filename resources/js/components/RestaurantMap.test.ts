import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { triggerResize } from '@/test/setup';
import type { Restaurant } from '@/types';

const mapStub = {
    setView: vi.fn().mockReturnThis(),
    flyTo: vi.fn().mockReturnThis(),
    addLayer: vi.fn(),
    on: vi.fn(),
    remove: vi.fn(),
    getCenter: vi.fn(() => ({ lat: 25.033, lng: 121.5654 })),
    getBounds: vi.fn((): { getSouth: () => number; getWest: () => number; getNorth: () => number; getEast: () => number } => ({
        getSouth: () => 24.9,
        getWest: () => 121.4,
        getNorth: () => 25.1,
        getEast: () => 121.7,
    })),
    invalidateSize: vi.fn(),
};

const clusterStub = { clearLayers: vi.fn(), addLayer: vi.fn() };
const { bindPopup } = vi.hoisted(() => ({ bindPopup: vi.fn().mockReturnThis() }));

vi.mock('leaflet', () => ({
    default: {
        map: vi.fn(() => mapStub),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        markerClusterGroup: vi.fn(() => clusterStub),
        marker: vi.fn(() => ({ bindPopup, on: vi.fn() })),
    },
}));
vi.mock('leaflet.markercluster', () => ({}));

const RestaurantMap = (await import('./RestaurantMap.vue')).default;

/**
 * 掛載時 `L.map(...).setView(center, zoom)` 本來就會呼叫一次 setView，那是地圖初始化
 * 不是視角切換。清掉初始化的呼叫紀錄，後面斷言的才是切換行為本身。
 */
function mountMap() {
    const wrapper = mount(RestaurantMap, {
        props: { restaurants: [] as Restaurant[], center: [25.033, 121.5654] as [number, number], zoom: 13 },
    });

    mapStub.setView.mockClear();
    mapStub.flyTo.mockClear();

    return wrapper;
}

describe('RestaurantMap 視角切換', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('短距離用 flyTo 做動畫', () => {
        // 台北市中心 → 台北車站附近，約 2km。
        const wrapper = mountMap();

        (wrapper.vm as unknown as { flyTo: (a: number, b: number) => void }).flyTo(25.0478, 121.517);

        expect(mapStub.flyTo).toHaveBeenCalledTimes(1);
        expect(mapStub.setView).not.toHaveBeenCalled();
    });

    it('長距離改用 setView，不做飛行動畫', () => {
        // Leaflet 的 flyTo 跨長距離會把 tile layer 的 transform 留在壞掉的狀態：
        // 磁磚被排到容器外好幾千 px、畫面一片空白，而 marker 走另一條 pane 路徑所以
        // 位置照樣正確。實測台北→台南（約 300km）會重現。
        const wrapper = mountMap();

        (wrapper.vm as unknown as { flyTo: (a: number, b: number) => void }).flyTo(22.9997, 120.227);

        expect(mapStub.setView).toHaveBeenCalledTimes(1);
        expect(mapStub.flyTo).not.toHaveBeenCalled();
    });

    it('跨國距離同樣不做動畫', () => {
        const wrapper = mountMap();

        (wrapper.vm as unknown as { flyTo: (a: number, b: number) => void }).flyTo(35.6762, 139.6503);

        expect(mapStub.setView).toHaveBeenCalledTimes(1);
        expect(mapStub.flyTo).not.toHaveBeenCalled();
    });

    it('jumpTo 一律直接跳，即使距離很近', () => {
        // 城市切換走這條。近距離也不該animate——切換城市是換場景，不是平移。
        const wrapper = mountMap();

        (wrapper.vm as unknown as { jumpTo: (a: number, b: number, c: number) => void }).jumpTo(25.0478, 121.517, 13);

        expect(mapStub.setView).toHaveBeenCalledWith([25.0478, 121.517], 13);
        expect(mapStub.flyTo).not.toHaveBeenCalled();
    });

    it('jumpTo 會帶上該城市自己的 zoom，不是寫死的值', () => {
        // 東京 23 区範圍大，用 12；台灣各市用 13。寫死會讓其中一邊一開場就看錯範圍。
        const wrapper = mountMap();

        (wrapper.vm as unknown as { jumpTo: (a: number, b: number, c: number) => void }).jumpTo(35.6762, 139.6503, 12);

        expect(mapStub.setView).toHaveBeenCalledWith([35.6762, 139.6503], 12);
    });
});

describe('RestaurantMap popup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    it('店名與地址裡的 HTML 會被跳脫', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: 'Cafe <img src=x onerror=alert(1)>',
                    address: '路 <script>',
                    latitude: 25.03,
                    longitude: 121.56,
                    rating: 4,
                    rating_count: 1,
                } as Restaurant],
                center: [25.033, 121.5654],
                zoom: 13,
            },
        });

        expect(bindPopup).toHaveBeenCalled();
        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).not.toContain('<img');
        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;img');
        expect(html).toContain('&lt;script&gt;');
    });


    it('popup 顯示距離——後端每次距離查詢都回 distance_meters，先前完全沒用到', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: '尚蔬苑',
                    address: '信義路',
                    latitude: 25.03,
                    longitude: 121.56,
                    rating: 0,
                    rating_count: 0,
                    distance_meters: 538.7,
                } as Restaurant],
                center: [25.033, 121.5654],
                zoom: 13,
            },
        });

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).toContain('540 公尺');
    });

    it('沒有距離時不顯示距離那一行，而不是印出 null', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: '沒有距離',
                    address: '某處',
                    latitude: 25.03,
                    longitude: 121.56,
                    rating: 0,
                    rating_count: 0,
                } as Restaurant],
                center: [25.033, 121.5654],
                zoom: 13,
            },
        });

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).not.toContain('公尺');
        expect(html).not.toContain('null');
    });

    it('沒有地址時 popup 寫「地址未提供」，不是省略整行', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: '新匯入的店',
                    address: '',
                    city: '',
                    district: '',
                    latitude: 25.03,
                    longitude: 121.56,
                    rating: 0,
                    rating_count: 0,
                } as Restaurant],
                center: [25.033, 121.5654],
                zoom: 13,
            },
        });

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).toContain('地址未提供');
        expect(html).not.toContain('尚無評分');
        expect(html).not.toContain('0.0');
    });

    it('popup 顯示料理種類', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: '日泰蔬食',
                    address: '公益路',
                    city: '台中市',
                    latitude: 24.14,
                    longitude: 120.67,
                    rating: 0,
                    rating_count: 0,
                    cuisines: [{ code: 'japanese', label: '日式料理' }],
                } as Restaurant],
                center: [24.1477, 120.6736],
                zoom: 13,
            },
        });

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).toContain('日式料理');
        expect(html).toContain('台中市公益路');
    });

    it('popup 顯示 API 帶回的 venue_badge，文案不是寫死在元件裡', () => {
        mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1,
                    name: 'AFURI',
                    address: '渋谷',
                    latitude: 35.66,
                    longitude: 139.70,
                    rating: 4,
                    rating_count: 1,
                    venue_badge: '素食友善',
                    venue_summary: '菜單有素食（無肉）選項',
                } as Restaurant],
                center: [35.6762, 139.6503],
                zoom: 12,
            },
        });

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).toContain('素食友善');
        expect(html).toContain('菜單有素食（無肉）選項');
    });
});

describe('RestaurantMap bounds 事件', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mapStub.getBounds.mockReturnValue({
            getSouth: () => 24.9,
            getWest: () => 121.4,
            getNorth: () => 25.1,
            getEast: () => 121.7,
        });
    });

    it('掛載後送出目前的 bbox', () => {
        const wrapper = mountMap();

        expect(wrapper.emitted('bounds-changed')).toHaveLength(1);
    });

    /**
     * 2026-08-26 瀏覽器實測：首次渲染時容器還沒量到寬度，east === west，
     * 算出來的 bbox 是一條線，後端回 422，畫面閃一下「載入失敗」。
     */
    /**
     * 2026-08-26 實測到的回歸：只是「不送」會讓那次載入永遠補不回來——`moveend`
     * 不會因為容器變大而觸發，畫面停在空地圖，一筆 /restaurants 請求都不會發。
     */
    it('尺寸確定後會補送一次 bbox', () => {
        mapStub.getBounds.mockReturnValue({
            getSouth: () => 25.0,
            getWest: () => 121.56,
            getNorth: () => 25.06,
            getEast: () => 121.56,
        });

        const wrapper = mountMap();
        expect(wrapper.emitted('bounds-changed')).toBeUndefined();

        // 容器量到尺寸了。
        mapStub.getBounds.mockReturnValue({
            getSouth: () => 24.9,
            getWest: () => 121.4,
            getNorth: () => 25.1,
            getEast: () => 121.7,
        });
        triggerResize();

        expect(wrapper.emitted('bounds-changed')).toHaveLength(1);
        expect(mapStub.invalidateSize).toHaveBeenCalled();
    });

    it('容器還沒有寬度時不送退化的 bbox', () => {
        mapStub.getBounds.mockReturnValue({
            getSouth: () => 25.0,
            getWest: () => 121.56,
            getNorth: () => 25.06,
            getEast: () => 121.56,
        });

        const wrapper = mountMap();

        expect(wrapper.emitted('bounds-changed')).toBeUndefined();
    });
});
