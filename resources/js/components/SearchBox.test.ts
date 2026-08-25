import { describe, expect, it, vi } from 'vitest';
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
