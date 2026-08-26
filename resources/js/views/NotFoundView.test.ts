import { describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import NotFoundView from './NotFoundView.vue';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: { template: '<div>map</div>' } },
            { path: '/restaurants', name: 'restaurants', component: { template: '<div>list</div>' } },
            { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView },
        ],
    });
}

describe('NotFoundView', () => {
    /**
     * 沒有 catch-all 的話，打錯的網址會渲染出一片空白——只有導覽列，中間什麼都
     * 沒有，使用者看不出是打錯了還是網站壞了（2026-08-26 瀏覽器實測）。
     */
    it('打錯的網址會落到這個頁面，而不是一片空白', async () => {
        const router = makeRouter();
        await router.push('/this-page-does-not-exist');
        await router.isReady();

        const wrapper = mount(NotFoundView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('not-found');
        expect(wrapper.text()).toContain('找不到這個頁面');
    });

    it('給得出兩條回去的路，不是死路', async () => {
        const router = makeRouter();
        await router.push('/nope');
        await router.isReady();

        const wrapper = mount(NotFoundView, { global: { plugins: [router] } });
        await flushPromises();

        const hrefs = wrapper.findAll('.links a').map((a) => a.attributes('href'));
        expect(hrefs).toEqual(['/', '/restaurants']);
    });
});
