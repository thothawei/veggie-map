import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn((url: string) => {
    if (url === '/diets') {
        return Promise.resolve({ data: { data: [{ code: 'vegan', label: '全素（Vegan）' }] } });
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
                    features: ['takeout'],
                    menu_items: [],
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

    return Promise.reject({ response: { status: 404 } });
});

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...(args as [string])), post: vi.fn() },
}));

const RestaurantDetailView = (await import('./RestaurantDetailView.vue')).default;

async function mountDetail(id: string) {
    setActivePinia(createPinia());
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
    });

    it('飲食與特色顯示中文標籤，不是 raw code', async () => {
        const { wrapper } = await mountDetail('1');

        expect(wrapper.text()).toContain('全素（Vegan）');
        expect(wrapper.text()).toContain('外帶');
        expect(wrapper.text()).not.toContain('vegan');
        expect(wrapper.text()).not.toContain('takeout');
        expect(wrapper.find('a[href^="javascript"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('官方網站');
    });

    it('id 變更會重新載入，不是留著上一間的資料', async () => {
        const { wrapper } = await mountDetail('1');
        expect(wrapper.find('h1').text()).toBe('第一家');

        await wrapper.setProps({ id: '2' });
        await flushPromises();

        expect(wrapper.find('h1').text()).toBe('第二家');
        expect(get).toHaveBeenCalledWith('/restaurants/2');
    });
});
