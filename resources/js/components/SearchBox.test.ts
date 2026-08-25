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
});
