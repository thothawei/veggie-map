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
                        meta: {
                            confidence_filters: [
                                { value: 30, label: '有查證' },
                                { value: 60, label: '高度可信' },
                            ],
                        },
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


    it('取消其中一個只拿掉那一個，不是整組清空', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer({ diet: 'vegan,vegetarian' });

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');

        expect(wrapper.props('filters')).toEqual({ diet: 'vegetarian' });
    });

    it('全部取消後 diet 這個 key 會消失，不留空字串', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer({ diet: 'vegan' });

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');

        expect('diet' in wrapper.props('filters')).toBe(false);
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

    /**
     * 2026-08-26 起飲食類型改成**多選**（原本是單選）：素食者常常「全素或蛋奶素
     * 都可以」，逼他們一次只能挑一個就得分兩次搜尋再自己合併。後端多個 code
     * 之間是 OR。
     */
    it('飲食類型可以複選，網址上用逗號串起來', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '全素（Vegan）')!.trigger('click');
        await wrapper.findAll('.chip').find((c) => c.text() === '素食（Vegetarian）')!.trigger('click');

        expect(wrapper.props('filters')).toEqual({ diet: 'vegan,vegetarian' });
        // 兩顆都要看起來是選取狀態——只亮一顆的話使用者會以為另一個沒生效。
        expect(wrapper.findAll('.chip.active')).toHaveLength(2);
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

describe('FilterDrawer 價位與評分', () => {
    beforeEach(() => {
        setViewportMatches(true);
    });

    it('價位晶片用 $ 數量表示級距', async () => {
        const wrapper = await mountDrawer();
        const labels = wrapper.findAll('.chip').map((c) => c.text());

        expect(labels).toContain('$');
        expect(labels).toContain('$$$$');
    });

    it('點價位會寫進 filters，再點一次取消', async () => {
        const wrapper = await mountDrawer();
        const chip = wrapper.findAll('.chip').find((c) => c.text() === '$$')!;

        await chip.trigger('click');
        expect(wrapper.props('filters').price_level).toBe(2);

        await chip.trigger('click');
        expect(wrapper.props('filters').price_level).toBeUndefined();
    });

    it('價位是單選——換一個會取代而不是累加', async () => {
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '$$')!.trigger('click');
        await wrapper.findAll('.chip').find((c) => c.text() === '$$$')!.trigger('click');

        expect(wrapper.props('filters').price_level).toBe(3);
    });

    it('沒有評分篩選——消費者端地圖不走會員評分', async () => {
        const wrapper = await mountDrawer();
        const labels = wrapper.findAll('.chip').map((c) => c.text());

        expect(labels.some((label) => label.includes('★'))).toBe(false);
        expect(wrapper.text()).not.toContain('評分');
    });

    it('價位算進徽章數字', async () => {
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '$$')!.trigger('click');

        expect(wrapper.find('.count').text()).toBe('1');
    });

    it('營業中晶片切開切關，網址參數 open_now 跟著進出', async () => {
        setViewportMatches(true);
        const wrapper = await mountDrawer();

        const chip = wrapper.findAll('.chip').find((c) => c.text() === '營業中')!;

        await chip.trigger('click');
        expect(wrapper.props('filters').open_now).toBe(true);

        await wrapper.findAll('.chip').find((c) => c.text() === '營業中')!.trigger('click');
        expect('open_now' in wrapper.props('filters')).toBe(false);
    });

});

describe('FilterDrawer 可信度篩選', () => {
    beforeEach(() => {
        setViewportMatches(true);
        resetVenueScopeMeta();
        vi.mocked(client.get).mockImplementation((url: string) => {
            if (url === '/diets') {
                return Promise.resolve({
                    data: {
                        data: [],
                        meta: {
                            confidence_filters: [
                                { value: 30, label: '有查證' },
                                { value: 60, label: '高度可信' },
                            ],
                        },
                    },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('可信度門檻與標籤來自 API，不是元件寫死的數字', async () => {
        const wrapper = await mountDrawer();

        await wrapper.findAll('.chip').find((c) => c.text() === '高度可信')!.trigger('click');

        expect(wrapper.props('filters').confidence_min).toBe(60);
    });

    it('再點一次同一個門檻＝取消，不留 undefined 的 key', async () => {
        const wrapper = await mountDrawer({ confidence_min: 30 });

        await wrapper.findAll('.chip').find((c) => c.text() === '有查證')!.trigger('click');

        expect('confidence_min' in wrapper.props('filters')).toBe(false);
    });

    it('沒有回 confidence_filters 時不渲染那一組，不用預設值硬撐', async () => {
        vi.mocked(client.get).mockImplementation(() => Promise.resolve({ data: { data: [], meta: {} } }));

        const wrapper = await mountDrawer();

        expect(wrapper.text()).not.toContain('素食可信度');
    });

    /** 只挑最高的一級當 quick chip，較低的門檻留在「更多篩選」面板裡。 */
    it('只有最高門檻進 quick chip，較低的留在更多篩選面板', async () => {
        setViewportMatches(false);
        const wrapper = await mountDrawer();

        const quick = wrapper.find('.quick-chips');
        expect(quick.text()).toContain('高度可信');
        expect(quick.text()).not.toContain('有查證');

        const panel = wrapper.find('#filter-panel');
        expect(panel.text()).toContain('有查證');
        expect(panel.text()).not.toContain('高度可信');
    });
});

/**
 * 常駐 quick filter（B3）：營業中／純素食店↔含友善店／高可信度不用打開
 * 「更多篩選」就按得到。哪三個是暫定的，等 A8 的資料夠了再回頭調整。
 */
describe('FilterDrawer 常駐 quick filter（B3）', () => {
    beforeEach(() => {
        resetVenueScopeMeta();
    });

    it('窄螢幕上「更多篩選」收合，quick chip 仍然看得到、按得到', async () => {
        setViewportMatches(false);
        const wrapper = await mountDrawer();

        expect(panelVisible(wrapper)).toBe(false);
        expect(wrapper.find('.quick-chips').isVisible()).toBe(true);

        const openNowChip = wrapper.findAll('.quick-chips .chip').find((c) => c.text() === '營業中')!;
        await openNowChip.trigger('click');

        expect(wrapper.props('filters').open_now).toBe(true);
    });

    it('toggle 按鈕文字是「更多篩選」，不是「篩選」', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.find('.toggle').text()).toContain('更多篩選');
    });

    it('quick chip 裡的可信度按鈕也能切換，行為跟面板裡的一致', async () => {
        setViewportMatches(false);
        const wrapper = await mountDrawer();

        const chip = wrapper.findAll('.quick-chips .chip').find((c) => c.text() === '高度可信')!;
        await chip.trigger('click');
        expect(wrapper.props('filters').confidence_min).toBe(60);

        await chip.trigger('click');
        expect('confidence_min' in wrapper.props('filters')).toBe(false);
    });
});

describe('FilterDrawer 底部「顯示 N 家結果」（B3）', () => {
    beforeEach(() => {
        setViewportMatches(true);
        resetVenueScopeMeta();
    });

    it('沒有帶 resultCount 時不顯示這顆按鈕', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.find('.show-results').exists()).toBe(false);
    });

    it('顯示目前已經查到的筆數，還有下一頁時加上 +', async () => {
        const wrapper = mount((await import('./FilterDrawer.vue')).default, {
            props: {
                filters: {},
                resultCount: 42,
                hasMoreResults: true,
                'onUpdate:filters': () => {},
            },
        });
        await flushPromises();

        expect(wrapper.find('.show-results').text()).toBe('顯示 42+ 家結果');
    });

    /**
     * `isVisible()` 讀 `getComputedStyle()`——沒有 `attachTo` 掛進真的
     * `document` 的元件，jsdom 對「同一個節點查兩次 computed style、中間
     * DOM 變了」這種情況不會重算，第二次查到的還是第一次查詢當下的舊值
     * （2026-09-06 實測：`display` 明明已經變成 `none`，`getComputedStyle`
     * 還是回 `block`）。這條要驗證的正是「查一次→變化→再查一次」，所以
     * 掛進 `document.body`，測完手動 unmount 清掉，不留給下一條測試。
     */
    it('按下「顯示 N 家結果」會收合面板，不需要使用者自己再點一次「更多篩選」', async () => {
        const wrapper = mount((await import('./FilterDrawer.vue')).default, {
            attachTo: document.body,
            props: {
                filters: {},
                resultCount: 10,
                'onUpdate:filters': () => {},
            },
        });
        await flushPromises();

        try {
            expect(panelVisible(wrapper)).toBe(true);
            await wrapper.find('.show-results').trigger('click');

            expect(panelVisible(wrapper)).toBe(false);
        } finally {
            wrapper.unmount();
        }
    });

    it('「清除全部」跟「顯示 N 家結果」在同一個底部區塊', async () => {
        const wrapper = mount((await import('./FilterDrawer.vue')).default, {
            props: {
                filters: { open_now: true },
                resultCount: 5,
                'onUpdate:filters': () => {},
            },
        });
        await flushPromises();

        const footer = wrapper.find('.panel-footer');
        expect(footer.find('.clear').exists()).toBe(true);
        expect(footer.find('.show-results').exists()).toBe(true);
    });
});

describe('FilterDrawer quick chip 帶「會剩幾家」（B4）', () => {
    beforeEach(() => {
        setViewportMatches(true);
        resetVenueScopeMeta();
        // 前面幾個 describe 區塊各自覆寫過 client.get 的 mock 實作，
        // 而且從沒重設回模組頂層的預設值——這裡要自己指定一份完整的
        // venue_scope meta，不能依賴「跑到這裡時剛好是哪個區塊留下的值」。
        vi.mocked(client.get).mockImplementation((url: string) => {
            if (url === '/diets') {
                return Promise.resolve({
                    data: {
                        data: [],
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
                            confidence_filters: [
                                { value: 30, label: '有查證' },
                                { value: 60, label: '高度可信' },
                            ],
                        },
                    },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('沒有 facets prop 時 quick chip 不顯示數字', async () => {
        const wrapper = await mountDrawer();

        const venueChip = wrapper.findAll('.quick-chips .chip').find((c) => c.text().includes('純素食店'))!;
        expect(venueChip.find('.facet-count').exists()).toBe(false);
    });

    it('有 facets 時 quick chip 顯示對應的候選值數字', async () => {
        const FilterDrawer = (await import('./FilterDrawer.vue')).default;
        const wrapper = mount(FilterDrawer, {
            props: {
                filters: {},
                facets: {
                    venue_scope: [
                        { value: 'exclusive', label: '純素食店', count: 12 },
                        { value: 'friendly', label: '素食友善', count: 0 },
                        { value: 'all', label: '全部', count: 12 },
                    ],
                    open_now: { value: true, count: 3 },
                    confidence_min: { value: 60, label: '高度可信', count: 5 },
                },
                'onUpdate:filters': () => {},
            },
        });
        await flushPromises();

        const chips = wrapper.findAll('.quick-chips .chip');
        const exclusiveChip = chips.find((c) => c.text().includes('純素食店'))!;
        const friendlyChip = chips.find((c) => c.text().includes('素食友善'))!;
        const openNowChip = chips.find((c) => c.text().includes('營業中'))!;
        const confidenceChip = chips.find((c) => c.text().includes('高度可信'))!;

        expect(exclusiveChip.text()).toContain('12');
        expect(openNowChip.text()).toContain('3');
        expect(confidenceChip.text()).toContain('5');

        // 0 家的 chip 變灰但不隱藏——隱藏會讓使用者以為選項消失了。
        expect(friendlyChip.exists()).toBe(true);
        expect(friendlyChip.classes()).toContain('zero-count');
    });

    /**
     * 防呆：`facets` 缺欄位（例如某個維度算失敗、後端調整過回應形狀）不能讓
     * 整個 FilterDrawer 崩潰——2026-09-06 實測踩過，舊測試的 catch-all mock
     * 回一個陣列蓋掉 facets，`.open_now.count` 直接炸掉整個元件。
     */
    it('facets 形狀不完整時不會讓元件崩潰，只是不顯示那個數字', async () => {
        const FilterDrawer = (await import('./FilterDrawer.vue')).default;

        expect(() => mount(FilterDrawer, {
            props: {
                filters: {},
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                facets: [] as any,
                'onUpdate:filters': () => {},
            },
        })).not.toThrow();
    });
});
