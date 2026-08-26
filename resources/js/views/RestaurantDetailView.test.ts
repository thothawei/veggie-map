import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { resetVenueScopeMeta } from '@/lib/dietCatalog';
import { useAuthStore } from '@/stores/auth';

const post = vi.fn();

/** 附近搜尋（`GET /restaurants`）的回應，各測試可以覆蓋。 */
let nearbyPayload: { data: unknown[] } = { data: [] };
let nearbyFails = false;

/** 蓋在 `/restaurants/1` 詳情回應上的欄位，讓各測試只寫自己在乎的那幾個。 */
let detailOverrides: Record<string, unknown> = {};

const get = vi.fn((url: string) => {
    if (url === '/restaurants') {
        return nearbyFails
            ? Promise.reject(new Error('network'))
            : Promise.resolve({ data: nearbyPayload });
    }
    if (url === '/me/favorites') {
        return Promise.resolve({ data: { data: [] } });
    }
    if (url === '/diets') {
        return Promise.resolve({
            data: {
                data: [{ code: 'vegan', label: '全素（Vegan）' }],
                meta: {
                    menu_item_diets: [
                        { code: 'vegan', label: '植物性' },
                        { code: 'vegetarian', label: '素食' },
                        { code: 'non_vegetarian', label: '含肉' },
                        { code: 'unknown', label: '未標示' },
                    ],
                },
            },
        });
    }
    if (url === '/admin/verification-types') {
        return Promise.resolve({
            data: { data: [{ code: 'admin_verified', label: 'Admin 已查證', score: 30 }] },
        });
    }
    if (url === '/features') {
        return Promise.resolve({ data: { data: [{ code: 'takeout', label: '外帶' }] } });
    }
    if (url === '/restaurants/1') {
        return Promise.resolve({
            data: {
                data: {
                    id: 1,
                    name: '第一家',
                    address: '忠孝路',
                    city: '台北市',
                    district: '大安區',
                    cuisines: [{ code: 'chinese', label: '中式料理' }, { code: 'stir_fry', label: '中式快炒' }],
                    rating: 4,
                    rating_count: 1,
                    diet_types: ['vegan'],
                    venue_kind: 'exclusive',
                    venue_badge: '素食餐廳',
                    venue_summary: '整間店都是素食',
                    features: ['takeout'],
                    menu_items: [
                        { id: 11, name: '白飯', diet_type: 'vegan', diet_label: '全素', price: 30, is_available: true },
                        { id: 12, name: '牛肉麵', diet_type: 'non_vegetarian', diet_label: '葷食', price: 150, is_available: true },
                    ],
                    website: 'javascript:alert(1)',
                    ...detailOverrides,
                },
            },
        });
    }
    if (url === '/restaurants/2') {
        return Promise.resolve({
            data: {
                data: {
                    id: 2,
                    name: '第二家',
                    address: '中山路',
                    rating: 5,
                    rating_count: 2,
                    diet_types: [],
                    features: [],
                    menu_items: [],
                },
            },
        });
    }
    if (url === '/restaurants/3') {
        return Promise.resolve({
            data: {
                data: {
                    id: 3,
                    name: '友善火鍋',
                    address: '',
                    rating: 0,
                    rating_count: 0,
                    diet_types: ['vegetarian_friendly'],
                    venue_kind: 'friendly',
                    venue_badge: '素食友善',
                    features: [],
                    menu_items: [],
                    menu_empty_message: 'OSM 標示此店有素食選項，菜單尚未建檔。',
                },
            },
        });
    }

    // 舊 slug（restaurant_slug_aliases）：後端仍然回這家店，但 payload 裡的 slug
    // 是現行值。
    if (url === '/restaurants/osm-node-9') {
        return Promise.resolve({
            data: {
                data: {
                    id: 9,
                    name: '清心蔬食',
                    slug: 'qing-xin-shu-shi',
                    address: '',
                    rating: 0,
                    rating_count: 0,
                    diet_types: [],
                    features: [],
                    menu_items: [],
                },
            },
        });
    }

    return Promise.reject(new Error('network'));
});

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...(args as [string])), post: (...args: unknown[]) => post(...args) },
}));

const RestaurantDetailView = (await import('./RestaurantDetailView.vue')).default;

async function mountDetail(id: string, role: 'user' | 'admin' | null = null) {
    setActivePinia(createPinia());
    if (role) {
        const auth = useAuthStore();
        auth.token = 't';
        auth.user = { id: 1, name: '測', email: 'a@b.c', role, created_at: '2026-01-01' };
    }

    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/restaurants/:id', name: 'restaurant-detail', component: RestaurantDetailView, props: true },
            { path: '/login', name: 'login', component: { template: '<div />' } },
        ],
    });
    await router.push(`/restaurants/${id}`);
    await router.isReady();

    const wrapper = mount(RestaurantDetailView, {
        props: { id },
        global: { plugins: [router] },
    });
    await flushPromises();

    return { wrapper, router };
}

describe('RestaurantDetailView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        resetVenueScopeMeta();
        nearbyPayload = { data: [] };
        detailOverrides = {};
    });

    it('飲食與特色顯示中文標籤，不是 raw code', async () => {
        const { wrapper } = await mountDetail('1');

        expect(wrapper.text()).toContain('全素（Vegan）');
        expect(wrapper.text()).toContain('外帶');
        expect(wrapper.text()).toContain('素食餐廳');
        expect(wrapper.text()).toContain('整間店都是素食');
        expect(wrapper.text()).toContain('地址');
        expect(wrapper.text()).toContain('台北市大安區忠孝路');
        expect(wrapper.text()).toContain('中式料理、中式快炒');
        expect(wrapper.text()).not.toContain('takeout');
        expect(wrapper.text()).not.toContain('尚無評分');
        expect(wrapper.text()).not.toContain('寫評論');
        expect(wrapper.text()).not.toContain('加入收藏');
        expect(wrapper.find('a[href^="javascript"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('官方網站');
    });

    it('有菜單時依 /diets meta 分組，不是寫死全素／葷食', async () => {
        const { wrapper } = await mountDetail('1');
        const headings = wrapper.findAll('.menu-group h3').map((node) => node.text());

        expect(headings).toEqual(['植物性', '含肉']);
        expect(wrapper.text()).toContain('白飯');
        expect(wrapper.text()).toContain('牛肉麵');
        expect(wrapper.find('.menu-empty').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('vegan');
        expect(wrapper.text()).not.toContain('non_vegetarian');
    });

    it('無菜單的友善店顯示尚未建檔，不渲染假菜色', async () => {
        const { wrapper } = await mountDetail('3');

        expect(wrapper.text()).toContain('OSM 標示此店有素食選項，菜單尚未建檔。');
        expect(wrapper.findAll('.menu li')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('白飯');
        expect(wrapper.text()).not.toContain('牛肉麵');
    });

    it('admin 新增菜單會打寫入 API', async () => {
        post.mockResolvedValue({ data: { data: { id: 99 } } });
        const { wrapper } = await mountDetail('3', 'admin');

        expect(wrapper.text()).toContain('新增菜色');
        await wrapper.find('.menu-form input[type="text"]').setValue('高麗菜');
        await wrapper.find('.menu-form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin/restaurants/3/menu-items', {
            name: '高麗菜',
            diet_type: 'vegan',
            price: undefined,
        });
    });

    it('admin 可以標記驗證，送出後重新載入可信度', async () => {
        post.mockResolvedValue({ data: { data: { id: 5, confidence_score: 30 } } });
        const { wrapper } = await mountDetail('3', 'admin');

        expect(wrapper.find('.verify-form').exists()).toBe(true);
        expect(wrapper.text()).toContain('Admin 已查證（+30）');

        await wrapper.find('.verify-form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin/restaurants/3/verifications', {
            verification_type: 'admin_verified',
        });
        expect(get).toHaveBeenCalledWith('/restaurants/3');
        expect(wrapper.find('.notice').exists()).toBe(true);
    });

    it('標記驗證失敗會把錯誤顯示出來', async () => {
        post.mockRejectedValue(new Error('boom'));
        const { wrapper } = await mountDetail('3', 'admin');

        await wrapper.find('.verify-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('.verify-form .error').text()).toBe('記錄驗證失敗');
        expect(wrapper.find('.notice').exists()).toBe(false);
    });

    it('一般使用者看不到標記驗證表單，也不會打 admin lookup', async () => {
        const { wrapper } = await mountDetail('3', 'user');

        expect(wrapper.find('.verify-form').exists()).toBe(false);
        expect(get).not.toHaveBeenCalledWith('/admin/verification-types');
    });

    it('一般使用者看不到新增菜單表單', async () => {
        const { wrapper } = await mountDetail('3', 'user');

        expect(wrapper.find('.menu-form').exists()).toBe(false);
    });

    it('id 變更會重新載入，不是留著上一間的資料', async () => {
        const { wrapper } = await mountDetail('1');
        expect(wrapper.find('h1').text()).toBe('第一家');

        await wrapper.setProps({ id: '2' });
        await flushPromises();

        expect(wrapper.find('h1').text()).toBe('第二家');
        expect(get).toHaveBeenCalledWith('/restaurants/2');
    });

    it('未登入時不再出現登入引導——消費者端不需要帳號', async () => {
        // 2026-08-25 決定移除消費者端登入入口：瀏覽、搜尋、看詳細資料全部免帳號。
        // 原本這裡有「登入後可以收藏餐廳或寫評論」，等於把匿名使用者推去註冊。
        const { wrapper } = await mountDetail('1');

        expect(wrapper.findAll('a').some((anchor) => anchor.text() === '登入')).toBe(false);
        expect(wrapper.text()).not.toContain('後可以收藏餐廳或寫評論');
        expect(wrapper.text()).not.toContain('寫評論');
        expect(wrapper.text()).not.toContain('加入收藏');
    });

    it('非 404 的載入失敗顯示錯誤，不是空白頁', async () => {
        const { wrapper } = await mountDetail('9');

        expect(wrapper.text()).toContain('載入餐廳失敗');
        expect(wrapper.find('h1').exists()).toBe(false);
    });
});

describe('RestaurantDetailView 附近的素食餐廳', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        resetVenueScopeMeta();
        nearbyPayload = { data: [] };
        nearbyFails = false;
        detailOverrides = {};
    });

    it('列出附近的店，並把自己濾掉', async () => {
        nearbyPayload = {
            data: [
                { id: 1, name: '第一家', slug: 'first', distance_meters: 0, diet_types: [], features: [] },
                { id: 9, name: '隔壁素食', slug: 'next-door', distance_meters: 320, diet_types: [], features: [] },
            ],
        };

        const { wrapper } = await mountDetail('1');

        const text = wrapper.find('.nearby').text();
        expect(text).toContain('隔壁素食');
        // 半徑搜尋一定會撈到自己（距離 0），列出來只會讓人困惑。
        expect(text).not.toContain('第一家');
    });

    it('附近沒有別家時整段不顯示，不留一個空標題', async () => {
        nearbyPayload = {
            data: [{ id: 1, name: '第一家', slug: 'first', distance_meters: 0, diet_types: [], features: [] }],
        };

        const { wrapper } = await mountDetail('1');

        expect(wrapper.find('.nearby').exists()).toBe(false);
    });

    it('附近搜尋失敗時安靜略過，不讓人以為主要內容也壞了', async () => {
        nearbyFails = true;

        const { wrapper } = await mountDetail('1');

        expect(wrapper.find('.nearby').exists()).toBe(false);
        expect(wrapper.text()).toContain('第一家');
        expect(wrapper.find('.error').exists()).toBe(false);
    });
});

describe('RestaurantDetailView 可信度依據', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        resetVenueScopeMeta();
        nearbyPayload = { data: [] };
        nearbyFails = false;
        detailOverrides = {};
    });

    it('列出每一種驗證與它貢獻的分數', async () => {
        detailOverrides = {
            confidence_score: 40,
            confidence_breakdown: [
                { code: 'admin_verified', label: '管理員已查證', score: 30 },
                { code: 'external_source', label: '外部資料來源（OpenStreetMap）標示', score: 10 },
            ],
        };

        const { wrapper } = await mountDetail('1');

        const text = wrapper.find('.confidence').text();
        expect(text).toContain('素食可信度 40／100');
        expect(text).toContain('管理員已查證');
        expect(text).toContain('+30');
    });

    it('有分數但沒有有效查證時照實說，不留空白', async () => {
        detailOverrides = { confidence_score: 10, confidence_breakdown: [] };

        const { wrapper } = await mountDetail('1');

        expect(wrapper.find('.confidence .unknown').text()).toBe('目前沒有有效的查證紀錄。');
    });

    it('沒有分數時整段不顯示', async () => {
        detailOverrides = { confidence_score: null };

        const { wrapper } = await mountDetail('1');

        expect(wrapper.find('.confidence').exists()).toBe(false);
    });

    it('用舊 slug 進來時，網址換成現行 slug', async () => {
        const { router } = await mountDetail('osm-node-9');
        await flushPromises();

        expect(router.currentRoute.value.params.id).toBe('qing-xin-shu-shi');
    });

    it('數字 id 的網址刻意不換成 slug（舊的數字連結仍然有效）', async () => {
        detailOverrides = { slug: 'di-yi-jia' };
        const { router } = await mountDetail('1');
        await flushPromises();

        expect(router.currentRoute.value.params.id).toBe('1');
    });
});
