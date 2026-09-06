import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import L from 'leaflet';
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

/**
 * 預設回傳傳進來的那個 marker 本身——模擬「沒被收進 cluster，marker 自己
 * 就看得到」。B2 的高亮測試要模擬「被收進 cluster」時，改成
 * `mockReturnValueOnce(clusterIconStub)` 蓋一次即可。
 */
const clusterStub = { clearLayers: vi.fn(), addLayer: vi.fn(), getVisibleParent: vi.fn((layer: unknown) => layer) };
const { bindPopup, bindTooltip, markerOn } = vi.hoisted(() => ({
    bindPopup: vi.fn().mockReturnThis(),
    bindTooltip: vi.fn().mockReturnThis(),
    markerOn: vi.fn(),
}));

vi.mock('leaflet', () => ({
    default: {
        map: vi.fn(() => mapStub),
        tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
        markerClusterGroup: vi.fn(() => clusterStub),
        // 用真的 DOM 元素而不是手刻 classList/style 的假物件——B2 的高亮測試
        // 斷言的是真的 class 與 style，用 jsdom 的真元素比維護一份假 API 可靠。
        marker: vi.fn(() => {
            const element = document.createElement('div');

            return { bindPopup, bindTooltip, on: markerOn, getElement: () => element };
        }),
        divIcon: vi.fn((options: unknown) => options),
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

describe('RestaurantMap marker 樣式', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    /**
     * 純素食店與素食友善是這張地圖上最重要的區別：前者整間都能吃，後者是葷素都有。
     * 全部長一樣的話，使用者得逐個點開才知道——而那正是他要用地圖的原因。
     */
    it('依 venue_kind 給不同的 marker 樣式', async () => {
        const L = (await import('leaflet')).default;

        mount(RestaurantMap, {
            props: {
                restaurants: [
                    { id: 1, name: '純素店', latitude: 25, longitude: 121, venue_kind: 'exclusive' },
                    { id: 2, name: '友善店', latitude: 25.1, longitude: 121.1, venue_kind: 'friendly' },
                ] as unknown as Restaurant[],
                center: [25.033, 121.5654] as [number, number],
                zoom: 13,
            },
        });

        const icons = vi.mocked(L.divIcon).mock.calls.map(([options]) => (options as { html: string }).html);

        expect(icons[0]).toContain('data-kind="exclusive"');
        expect(icons[1]).toContain('data-kind="friendly"');
    });

    it('沒有 venue_kind 時退回中性樣式，不猜成其中一種', async () => {
        const L = (await import('leaflet')).default;

        mount(RestaurantMap, {
            props: {
                restaurants: [{ id: 3, name: '未知', latitude: 25, longitude: 121 }] as unknown as Restaurant[],
                center: [25.033, 121.5654] as [number, number],
                zoom: 13,
            },
        });

        const [options] = vi.mocked(L.divIcon).mock.calls[0];
        expect((options as { html: string }).html).toContain('data-kind="unknown"');
    });
});

/**
 * popup 的兩個出口。
 *
 * 在這之前 marker 的 click 直接導航到詳情頁，popup 綁了卻永遠沒機會顯示——
 * 整段 popup 內容連同它的測試，都在維護一個使用者看不到的 UI（2026-08-27 實測）。
 */
describe('RestaurantMap popup 的出口', () => {
    const restaurant = {
        id: 42,
        name: '綠光食堂',
        address: '台北市大安區',
        latitude: 25.03,
        longitude: 121.56,
        rating: 4,
        rating_count: 1,
    } as Restaurant;

    beforeEach(() => {
        vi.clearAllMocks();
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    function mountWithRestaurant() {
        return mount(RestaurantMap, {
            props: { restaurants: [restaurant], center: [25.033, 121.5654] as [number, number], zoom: 13 },
        });
    }

    it('popup 同時給「看詳情」與 Google 地圖，後者擋掉 opener 劫持', () => {
        mountWithRestaurant();

        const html = String(bindPopup.mock.calls[0][0]);
        expect(html).toContain('看詳情');
        expect(html).toContain('google.com/maps');
        expect(html).toContain('rel="noopener noreferrer"');
        // 座標不是店名——同名的店會定位到別家。
        expect(html).toContain('query=25.03,121.56');
    });

    /**
     * B2 之後 marker 確實掛了 `click` 監聽（讓清單捲過去、見下面
     * marker-focused 那組測試），但它只發 `marker-focused`，**不會**發
     * `select`（那是導航去詳情頁的事件）——popup 照樣由 Leaflet 自己的
     * click 行為開啟，兩者互不干擾。
     */
    it('點 marker 不再直接導航，而是開 popup', () => {
        const wrapper = mountWithRestaurant();

        const events = markerOn.mock.calls.map((call) => call[0]);
        expect(events).toContain('popupopen');

        const clickHandler = markerOn.mock.calls.find((call) => call[0] === 'click')?.[1];
        clickHandler?.();

        expect(wrapper.emitted('select')).toBeFalsy();
    });

    it('popup 反覆開關後，看詳情只會發一次 select', async () => {
        const wrapper = mountWithRestaurant();

        /*
         * Leaflet 關閉再開啟同一個 marker 的 popup 時重用同一份 DOM
         * （2026-08-27 在瀏覽器實測：popup element 與 button element 都是同一個物件）。
         * 用 addEventListener 的話開關兩次就綁了兩個，點一下發兩次 select。
         * 把 onclick 賦值改回 addEventListener，這條會紅。
         */
        const element = document.createElement('div');
        element.innerHTML = '<button data-detail="42">看詳情</button>';
        const handler = markerOn.mock.calls.find((call) => call[0] === 'popupopen')?.[1];

        handler({ popup: { getElement: () => element } });
        handler({ popup: { getElement: () => element } });

        element.querySelector('button')!.click();

        expect(wrapper.emitted('select')?.length).toBe(1);
    });

    it('popup 裡的「看詳情」走 Vue 導航，不是整頁重載', async () => {
        const wrapper = mountWithRestaurant();

        // 模擬 Leaflet 開啟 popup：把 popup 的 DOM 交給元件去接事件。
        const element = document.createElement('div');
        element.innerHTML = '<button data-detail="42">看詳情</button>';
        const handler = markerOn.mock.calls.find((call) => call[0] === 'popupopen')?.[1];

        handler({ popup: { getElement: () => element } });
        element.querySelector('button')!.click();

        expect(wrapper.emitted('select')?.[0]).toEqual([restaurant]);
    });
});

/**
 * 滑鼠移到 marker 上的提示。
 *
 * 它是**附加**在 popup 之上的一層，不是取代：手機沒有 hover，改成 hover-only
 * 會讓手機使用者完全看不到這些資訊。
 */
describe('RestaurantMap marker tooltip', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    function mountWith(restaurant: Partial<Restaurant>) {
        return mount(RestaurantMap, {
            props: {
                restaurants: [{
                    id: 1, name: '綠光食堂', latitude: 25.03, longitude: 121.56,
                    rating: 4, rating_count: 1, ...restaurant,
                } as Restaurant],
                center: [25.033, 121.5654] as [number, number],
                zoom: 13,
            },
        });
    }

    it('顯示店名與料理種類', () => {
        // cuisines 是 { code, label } 物件（API 實際回傳的形狀），不是字串陣列。
        mountWith({ cuisines: [{ code: 'ramen', label: '拉麵' }, { code: 'japanese', label: '日式料理' }] });

        const html = String(bindTooltip.mock.calls[0][0]);
        expect(html).toContain('綠光食堂');
        expect(html).toContain('拉麵');
    });

    it('沒有料理種類時只有店名，不留一個空欄位', () => {
        // OSM 很多店沒有 cuisine 標籤。硬留一個空的 span 會在 tooltip 底下
        // 多出一條沒有內容的間距。
        mountWith({ cuisines: [] });

        const html = String(bindTooltip.mock.calls[0][0]);
        expect(html).toContain('綠光食堂');
        expect(html).not.toContain('tooltip-cuisines');
    });

    it('店名裡的 HTML 會被跳脫', () => {
        // tooltip 跟 popup 一樣吃 HTML 字串，跳脫規則要比照。
        mountWith({ name: '<img src=x onerror=alert(1)>' });

        const html = String(bindTooltip.mock.calls[0][0]);
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
    });

    it('tooltip 不取代 popup——兩個都要在', () => {
        mountWith({ cuisines: [{ code: 'ramen', label: '拉麵' }] });

        expect(bindTooltip).toHaveBeenCalled();
        expect(bindPopup).toHaveBeenCalled();
    });
});

/**
 * 地圖↔清單雙向連動（B2）。這裡只驗證 RestaurantMap 這一半：`marker-focused`
 * 事件與 `highlightRestaurant()`。HomeView 那一半（sheet 展開、捲動、
 * 卡片 hover）在 HomeView.test.ts。
 */
describe('RestaurantMap 地圖↔清單連動（B2）', () => {
    const restaurant = {
        id: 7,
        name: '綠光食堂',
        address: '台北市大安區',
        latitude: 25.03,
        longitude: 121.56,
        rating: 4,
        rating_count: 1,
    } as Restaurant;

    beforeEach(() => {
        vi.clearAllMocks();
        clusterStub.getVisibleParent.mockImplementation((layer: unknown) => layer);
        mapStub.getCenter.mockReturnValue({ lat: 25.033, lng: 121.5654 });
    });

    function mountWithRestaurant() {
        return mount(RestaurantMap, {
            props: { restaurants: [restaurant], center: [25.033, 121.5654] as [number, number], zoom: 13 },
        });
    }

    /**
     * `L.marker()` 回傳的是一個從沒被插進 `document` 的孤兒 div（我們的 mock
     * 就是這樣做的，跟真的 Leaflet 一樣不會被 mount() 掛進 vue-test-utils 的
     * DOM 樹）——查 `document.querySelectorAll` 永遠找不到它。要驗證的是
     * 「哪個 marker 的 element 被打了 highlight」，直接從 `L.marker` 的
     * mock 呼叫紀錄拿回傳值最準，不用假裝它在真實 DOM 裡。
     */
    function markerElements(): HTMLElement[] {
        return (L.marker as ReturnType<typeof vi.fn>).mock.results.map(
            (result) => (result.value as { getElement: () => HTMLElement }).getElement(),
        );
    }

    it('點 marker 會發 marker-focused，帶上那家餐廳', () => {
        const wrapper = mountWithRestaurant();

        const clickHandler = markerOn.mock.calls.find((call) => call[0] === 'click')?.[1];
        clickHandler?.();

        expect(wrapper.emitted('marker-focused')?.[0]?.[0]).toMatchObject({ id: 7 });
    });

    it('highlightRestaurant 放大對應的 marker，設 zIndex 讓它蓋過其他點', () => {
        const wrapper = mountWithRestaurant();
        const vm = wrapper.vm as unknown as { highlightRestaurant: (id: number | null) => void };

        vm.highlightRestaurant(7);

        const [el] = markerElements();

        expect(el.classList.contains('marker-highlighted')).toBe(true);
        expect(el.style.zIndex).toBe('10000');
    });

    it('highlightRestaurant(null) 復原上一個被放大的 marker', () => {
        const wrapper = mountWithRestaurant();
        const vm = wrapper.vm as unknown as { highlightRestaurant: (id: number | null) => void };

        vm.highlightRestaurant(7);
        vm.highlightRestaurant(null);

        const [el] = markerElements();

        expect(el.classList.contains('marker-highlighted')).toBe(false);
        expect(el.style.zIndex).toBe('');
    });

    it('換一個 id 高亮時，前一個會先復原——不是兩個同時放大', () => {
        const other = { ...restaurant, id: 8, latitude: 25.04, longitude: 121.57 } as Restaurant;
        const wrapper = mount(RestaurantMap, {
            props: { restaurants: [restaurant, other], center: [25.033, 121.5654] as [number, number], zoom: 13 },
        });
        const vm = wrapper.vm as unknown as { highlightRestaurant: (id: number | null) => void };

        vm.highlightRestaurant(7);
        vm.highlightRestaurant(8);

        const [first, second] = markerElements();

        expect(first.classList.contains('marker-highlighted')).toBe(false);
        expect(second.classList.contains('marker-highlighted')).toBe(true);
    });

    /**
     * cluster 收起來時個別 marker 不存在——`getVisibleParent()` 這時候回的是
     * 代表整群的 cluster icon，不是 marker 自己。高亮要打在那個 icon 上，
     * 不能對著一個沒有 DOM 節點的 marker 呼叫 `getElement()`（拿到 undefined，
     * 什麼事都不會發生，看起來像「hover 沒反應」）。
     */
    it('marker 被收進 cluster 時，高亮打在 cluster icon 上，不是消失的 marker', () => {
        const clusterIconElement = document.createElement('div');
        const clusterIcon = { getElement: () => clusterIconElement };
        clusterStub.getVisibleParent.mockReturnValueOnce(clusterIcon);

        const wrapper = mountWithRestaurant();
        const vm = wrapper.vm as unknown as { highlightRestaurant: (id: number | null) => void };

        vm.highlightRestaurant(7);

        expect(clusterIconElement.classList.contains('marker-highlighted')).toBe(true);
    });

    it('不存在的 id 不會爆炸，也不會留下高亮', () => {
        const wrapper = mountWithRestaurant();
        const vm = wrapper.vm as unknown as { highlightRestaurant: (id: number | null) => void };

        expect(() => vm.highlightRestaurant(9999)).not.toThrow();

        const [el] = markerElements();
        expect(el.classList.contains('marker-highlighted')).toBe(false);
    });
});
