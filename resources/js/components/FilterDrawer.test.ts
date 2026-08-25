import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { setViewportMatches } from '@/test/setup';
import { resetVenueScopeMeta } from '@/lib/dietCatalog';
import type { RestaurantSearchParams } from '@/types';

vi.mock('@/api/client', () => ({
    default: {
        get: vi.fn((url: string) => {
            if (url === '/diets') {
                return Promise.resolve({
                    data: {
                        data: [
                            { code: 'vegan', label: '全素（Vegan）' },
                            { code: 'vegetarian', label: '素食（Vegetarian）' },
                        ],
                    },
                });
            }

            return Promise.resolve({
                data: {
                    data: [
                        { code: 'pet_friendly', label: '寵物友善' },
                        { code: 'parking', label: '停車' },
                        { code: 'takeout', label: '外帶' },
                    ],
                },
            });
        }),
    },
}));

import client from '@/api/client';

const FilterDrawer = (await import('./FilterDrawer.vue')).default;

/**
 * defineModel 的更新是靠 emit 回傳給父層，所以測試必須真的接上 v-model——否則
 * 「整組換掉」（clearAll）的路徑會看起來沒生效，而「就地改欄位」的路徑因為改到同一個
 * 物件反而看得到，兩條路徑行為不一致純粹是測試沒接線造成的假象。
 */
async function mountDrawer(filters: Partial<RestaurantSearchParams> = {}) {
    const wrapper = mount(FilterDrawer, {
        props: {
            filters,
            'onUpdate:filters': (value: Partial<RestaurantSearchParams>) => wrapper.setProps({ filters: value }),
        },
    });
    await flushPromises();

    return wrapper;
}

function panelVisible(wrapper: Awaited<ReturnType<typeof mountDrawer>>): boolean {
    return wrapper.find('#filter-panel').isVisible();
}

describe('FilterDrawer', () => {
    beforeEach(() => {
        setViewportMatches(false);
        resetVenueScopeMeta();
    });

    it('窄螢幕預設收合，避免晶片把地圖擠到摺線以下', async () => {
        expect(panelVisible(await mountDrawer())).toBe(false);
    });

    it('寬螢幕預設展開', async () => {
        setViewportMatches(true);

        expect(panelVisible(await mountDrawer())).toBe(true);
    });

    it('使用者手動展開後，選擇蓋過螢幕寬度的預設', async () => {
        const wrapper = await mountDrawer();

        await wrapper.find('.toggle').trigger('click');

        expect(panelVisible(wrapper)).toBe(true);
        expect(wrapper.find('.toggle').attributes('aria-expanded')).toBe('true');
    });

    it('取消飲食篩選會把 key 刪掉，不是留一個 undefined', async () => {
        // 這是真的踩過的 bug：留著 undefined 的 key 會讓「還有幾個篩選條件」多算一個，
        // 徽章和空狀態提示都會跟著說謊。
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        const veganChip = wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!;

        await veganChip.trigger('click');
        expect(wrapper.props('filters')).toEqual({ diet: 'vegan' });

        await veganChip.trigger('click');
        expect(Object.keys(wrapper.props('filters'))).toEqual([]);
    });

    it('徽章數字等於實際生效的條件數', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        expect(wrapper.find('.count').exists()).toBe(false);

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');
        expect(wrapper.find('.count').text()).toBe('1');

        await wrapper.findAll('.chip').find((c) => c.text() === '停車')!.trigger('click');
        expect(wrapper.find('.count').text()).toBe('2');
    });

    it('沒有生效條件時不顯示清除鈕', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        expect(wrapper.find('.clear').exists()).toBe(false);

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');
        expect(wrapper.find('.clear').exists()).toBe(true);
    });

    it('清除會一次移除所有條件', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');
        await wrapper.findAll('.chip').find((c) => c.text() === '寵物友善')!.trigger('click');
        expect(wrapper.find('.count').text()).toBe('2');

        await wrapper.find('.clear').trigger('click');

        expect(wrapper.props('filters')).toEqual({});
        expect(wrapper.find('.count').exists()).toBe(false);
        expect(wrapper.findAll('.chip.active')).toHaveLength(0);
    });

    it('飲食類型是單選，換一個會取代而不是累加', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');
        await wrapper.findAll('.chip').find((c) => c.text() === '素食（Vegetarian）')!.trigger('click');

        expect(wrapper.props('filters')).toEqual({ diet: 'vegetarian' });
        expect(wrapper.findAll('.chip.active')).toHaveLength(1);
    });

    it('特色晶片依 /features 動態渲染，不是寫死寵物友善與停車', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        expect(wrapper.findAll('.chip').map((c) => c.text())).toContain('外帶');

        await wrapper.findAll('.chip').find((c) => c.text() === '外帶')!.trigger('click');

        expect(wrapper.props('filters')).toEqual({ takeout: true });
    });
});

describe('FilterDrawer venue_scope 依 /diets meta 渲染', () => {
    beforeEach(() => {
        setViewportMatches(true);
        resetVenueScopeMeta();
        vi.mocked(client.get).mockImplementation((url: string) => {
            if (url === '/diets') {
                return Promise.resolve({
                    data: {
                        data: [
                            { code: 'vegan', label: '全素（Vegan）', kind: 'exclusive', group_label: '純素食店' },
                            { code: 'vegetarian_friendly', label: '全素友善以外', kind: 'friendly', group_label: '素食友善' },
                        ],
                        meta: {
                            venue_scope: {
                                param: 'venue_scope',
                                default: 'exclusive',
                                group_label: '店家類型',
                                values: [
                                    { value: 'exclusive', label: '純素食店' },
                                    { value: 'friendly', label: '素食友善' },
                                    { value: 'all', label: '全部' },
                                ],
                            },
                        },
                    },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('範圍晶片來自 API，預設選 exclusive 但不算進徽章', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.text()).toContain('店家類型');
        expect(wrapper.findAll('.chip').map((c) => c.text())).toEqual(
            expect.arrayContaining(['純素食店', '素食友善', '全部', '全素（Vegan）', '全素友善以外']),
        );
        expect(wrapper.findAll('.chip').find((c) => c.text() === '純素食店')!.classes()).toContain('active');
        expect(wrapper.find('.count').exists()).toBe(false);
    });

    it('飲食晶片依 group_label 分組，不是寫死兩組名稱', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.findAll('.label').map((n) => n.text())).toEqual(
            expect.arrayContaining(['店家類型', '純素食店', '素食友善']),
        );
    });

    it('選友善範圍會寫進 filters，選回預設會把 key 刪掉', async () => {
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '素食友善')!.trigger('click');
        expect(wrapper.props('filters')).toEqual({ venue_scope: 'friendly' });
        expect(wrapper.find('.count').text()).toBe('1');

        await wrapper.findAll('.chip').find((c) => c.text() === '純素食店')!.trigger('click');
        expect(Object.keys(wrapper.props('filters'))).toEqual([]);
        expect(wrapper.find('.count').exists()).toBe(false);
    });
});
