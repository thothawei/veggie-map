import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { resetVenueScopeMeta } from '@/lib/dietCatalog';
import { useAuthStore } from '@/stores/auth';

const post = vi.fn();
const get = vi.fn((url: string) => {
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
    });

    it('飲食與特色顯示中文標籤，不是 raw code', async () => {
        const { wrapper } = await mountDetail('1');

        expect(wrapper.text()).toContain('全素（Vegan）');
        expect(wrapper.text()).toContain('外帶');
        expect(wrapper.text()).toContain('素食餐廳');
        expect(wrapper.text()).toContain('整間店都是素食');
        expect(wrapper.text()).not.toContain('takeout');
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
    });

    it('非 404 的載入失敗顯示錯誤，不是空白頁', async () => {
        const { wrapper } = await mountDetail('9');

        expect(wrapper.text()).toContain('載入餐廳失敗');
        expect(wrapper.find('h1').exists()).toBe(false);
    });
});
