import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { useSearchScope } from './useSearchScope';

function makeHarness(defaultScope: 'map' | 'city' | 'all') {
    return defineComponent({
        setup() {
            const scope = useSearchScope(defaultScope);

            return { scope };
        },
        render() {
            return h('div', this.scope);
        },
    });
}

async function mountWithRoute(defaultScope: 'map' | 'city' | 'all', initialUrl = '/') {
    const Harness = makeHarness(defaultScope);
    const router: Router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/', name: 'home', component: Harness }],
    });

    await router.push(initialUrl);
    await router.isReady();

    const wrapper = mount(Harness, { global: { plugins: [router] } });

    return { wrapper, router };
}

describe('useSearchScope', () => {
    it('網址沒有 scope 時回傳呼叫端指定的預設值', async () => {
        const { wrapper } = await mountWithRoute('city');

        expect(wrapper.text()).toBe('city');
    });

    it('網址帶合法的 scope 就用那個值，不管預設是什麼', async () => {
        const { wrapper } = await mountWithRoute('city', '/?scope=all');

        expect(wrapper.text()).toBe('all');
    });

    /** 網址是使用者可以隨手改的，帶垃圾值進來要退回預設，不是照單全收送去後端。 */
    it('網址帶不合法的值時退回預設，不是原樣送出去', async () => {
        const { wrapper } = await mountWithRoute('map', '/?scope=nearby');

        expect(wrapper.text()).toBe('map');
    });

    it('設成跟預設一樣的值時，網址上不留 scope 參數——跟 useCities/useFilterQuery 同一套約定', async () => {
        const Harness = defineComponent({
            setup() {
                const scope = useSearchScope('city');

                return { scope };
            },
            render() {
                return h('button', { onClick: () => { this.scope = 'city'; } }, this.scope);
            },
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'home', component: Harness }],
        });
        await router.push('/?scope=all');
        await router.isReady();

        const wrapper = mount(Harness, { global: { plugins: [router] } });
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.scope).toBeUndefined();
    });

    it('設成跟預設不同的值時，網址上會出現 scope 參數', async () => {
        const Harness = defineComponent({
            setup() {
                const scope = useSearchScope('city');

                return { scope };
            },
            render() {
                return h('button', { onClick: () => { this.scope = 'all'; } }, this.scope);
            },
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'home', component: Harness }],
        });
        await router.push('/');
        await router.isReady();

        const wrapper = mount(Harness, { global: { plugins: [router] } });
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.scope).toBe('all');
    });

    /**
     * 反向驗證：scope 要跟 city／keyword／filters 一樣是網址狀態，「上一頁」
     * 才回得到前一次的值。拿掉 setter 裡的 router.push（例如改成只更新一個
     * 內部 ref）這條會紅——因為瀏覽器歷史紀錄裡根本不會多一筆。
     */
    it('改變 scope 會推一筆新的歷史紀錄，上一頁回得去', async () => {
        const Harness = defineComponent({
            setup() {
                const scope = useSearchScope('city');

                return { scope };
            },
            render() {
                return h('button', { onClick: () => { this.scope = 'all'; } }, this.scope);
            },
        });

        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/', name: 'home', component: Harness }],
        });
        await router.push('/');
        await router.isReady();

        const wrapper = mount(Harness, { global: { plugins: [router] } });
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.query.scope).toBe('all');

        await router.back();
        await flushPromises();

        expect(router.currentRoute.value.query.scope).toBeUndefined();
    });
});
