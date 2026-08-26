import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SearchBox from './SearchBox.vue';

const get = vi.fn();

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...args) },
}));

describe('SearchBox', () => {
    it('geocode 失敗時顯示錯誤，不是靜默沒反應', async () => {
        get.mockRejectedValueOnce(new Error('network'));

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('台中一中街');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('搜尋地點失敗');
    });

    it('候選清單永遠有「搜尋餐廳」，即使 geocode 一個地點都沒找到', async () => {
        // 「拉麵」在 Nominatim 查不到地點，但後端搜尋得到菜色。舊版這裡是死路。
        get.mockResolvedValueOnce({ data: { data: [] } });

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('拉麵');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        const keywordOption = wrapper.find('.keyword-option');
        expect(keywordOption.exists()).toBe(true);
        expect(keywordOption.text()).toContain('拉麵');

        await keywordOption.trigger('mousedown');
        expect(wrapper.emitted('keyword-search')?.[0]).toEqual(['拉麵']);
    });

    it('選地點時發 place-selected，不是 keyword-search', async () => {
        get.mockResolvedValueOnce({
            data: { data: [{ display_name: '台中一中街', latitude: 24.15, longitude: 120.68 }] },
        });

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('台中一中街');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        const places = wrapper.findAll('.results li').filter((li) => !li.classes('keyword-option'));
        await places[0].trigger('mousedown');

        expect(wrapper.emitted('place-selected')).toBeTruthy();
        expect(wrapper.emitted('keyword-search')).toBeFalsy();
    });

    it('關鍵字短於 geocode 門檻時仍然打得開候選清單', async () => {
        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('麵');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        // 舊版在這裡直接 return，按 Enter 完全沒反應。
        expect(wrapper.find('.keyword-option').exists()).toBe(true);
        expect(get).not.toHaveBeenCalledWith('/geocode', { params: { q: '麵' } });
    });
});

describe('SearchBox 自動完成', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function suggestPayload(data: Partial<{
        restaurants: unknown[];
        cuisines: unknown[];
        districts: unknown[];
    }>) {
        return {
            data: {
                data: { restaurants: [], cuisines: [], districts: [], ...data },
            },
        };
    }

    async function typeAndSettle(wrapper: ReturnType<typeof mount>, value: string) {
        await wrapper.find('input').setValue(value);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
    }

    it('打字後（節流過去）才查建議，不是每個字元一次請求', async () => {
        get.mockResolvedValue(suggestPayload({}));

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('十');
        await wrapper.find('input').setValue('十方');
        await wrapper.find('input').setValue('十方齋');

        expect(get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/restaurants/suggest', { params: { q: '十方齋' } });
    });

    it('選店名建議會發 restaurant-selected，不是關鍵字搜尋', async () => {
        get.mockResolvedValue(suggestPayload({
            restaurants: [{ id: 7, name: '十方齋', slug: 'shi-fang-zhai', address: '公益路 1 號', city: '台中市', district: '西區' }],
        }));

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '十方齋');

        const item = wrapper.findAll('.suggestion').find((li) => li.text().includes('十方齋'))!;
        await item.trigger('mousedown');

        expect(wrapper.emitted('restaurant-selected')?.[0]?.[0]).toMatchObject({ id: 7 });
        expect(wrapper.emitted('keyword-search')).toBeFalsy();
    });

    it('選料理種類＝用那個標籤做關鍵字搜尋', async () => {
        get.mockResolvedValue(suggestPayload({ cuisines: [{ code: 'japanese', label: '日式料理' }] }));

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '日式');

        const item = wrapper.findAll('.suggestion').find((li) => li.text().includes('日式料理'))!;
        await item.trigger('mousedown');

        expect(wrapper.emitted('keyword-search')?.[0]).toEqual(['日式料理']);
    });

    it('建議 API 失敗時安靜地不給建議，不跳錯誤紅字干擾打字', async () => {
        get.mockRejectedValue(new Error('network'));

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '十方齋');

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.findAll('.suggestion')).toHaveLength(0);
    });

    it('有建議時不顯示「找不到符合的地點」', async () => {
        get.mockResolvedValue(suggestPayload({ cuisines: [{ code: 'japanese', label: '日式料理' }] }));

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '日式');

        expect(wrapper.text()).not.toContain('找不到符合的地點');
    });

    it('沒有城市／行政區時退回地址，五筆同名的店才分得出來', async () => {
        get.mockResolvedValue(suggestPayload({
            restaurants: [
                { id: 1, name: '素食', slug: 'a', address: '台北市中正區羅斯福路 1 號', city: null, district: null },
                { id: 2, name: '素食', slug: 'b', address: null, city: null, district: null },
            ],
        }));

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '素食');

        const hints = wrapper.findAll('.suggestion .hint').map((h) => h.text());
        expect(hints[0]).toBe('台北市中正區羅斯福路 1 號');
        expect(hints[1]).toBe('地址未提供');
    });
});

describe('SearchBox 「找不到地點」的時機', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * 2026-08-26 瀏覽器實測抓到：打完字還沒按搜尋時，下拉就顯示「找不到符合的地點」
     * ——但地點查詢只在按下搜尋／Enter 時才發生，**那時候根本還沒查過**。
     */
    it('還沒按搜尋時不說「找不到符合的地點」', async () => {
        get.mockResolvedValue({ data: { data: { restaurants: [], cuisines: [], districts: [] } } });

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('台中一中街');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        // 下拉是開的（有「搜尋餐廳」那一項），但不能宣稱地點找不到。
        expect(wrapper.find('.keyword-option').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('找不到符合的地點');
    });

    it('真的查過而且沒有結果時才說找不到', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/geocode') return Promise.resolve({ data: { data: [] } });

            return Promise.resolve({ data: { data: { restaurants: [], cuisines: [], districts: [] } } });
        });

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('不存在的地名');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('找不到符合的地點');
    });

    it('查過之後又改字，就不再沿用上一次的結論', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/geocode') return Promise.resolve({ data: { data: [] } });

            return Promise.resolve({ data: { data: { restaurants: [], cuisines: [], districts: [] } } });
        });

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('不存在的地名');
        await wrapper.find('button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('找不到符合的地點');

        await wrapper.find('input').setValue('台中一中街');
        await flushPromises();

        expect(wrapper.text()).not.toContain('找不到符合的地點');
    });
});
