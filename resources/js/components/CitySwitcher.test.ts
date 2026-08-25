import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CitySwitcher from './CitySwitcher.vue';
import type { City } from '@/types';

const cities: City[] = [
    { slug: 'taipei', label: '台北', country: '台灣', center: [25.033, 121.5654], zoom: 13, bbox: '24.9613,121.4570,25.2130,121.6663' },
    { slug: 'taichung', label: '台中', country: '台灣', center: [24.1477, 120.6736], zoom: 13, bbox: '23.9500,120.4300,24.4500,121.4700' },
    { slug: 'tokyo', label: '東京', country: '日本', center: [35.6762, 139.6503], zoom: 12, bbox: '35.5300,139.5600,35.8200,139.9200' },
];

function mountSwitcher(modelValue: string | null = 'taipei') {
    return mount(CitySwitcher, { props: { cities, modelValue } });
}

describe('CitySwitcher', () => {
    it('依國家分組，同一國的城市排在同一組', () => {
        const groups = mountSwitcher().findAll('.city-group');

        expect(groups).toHaveLength(2);
        expect(groups[0].find('.country').text()).toBe('台灣');
        expect(groups[0].findAll('.city').map((b) => b.text())).toEqual(['台北', '台中']);
        expect(groups[1].find('.country').text()).toBe('日本');
        expect(groups[1].findAll('.city').map((b) => b.text())).toEqual(['東京']);
    });

    it('只有 modelValue 對應的城市是 active', () => {
        const buttons = mountSwitcher('taichung').findAll('.city');
        const active = buttons.filter((b) => b.classes('active'));

        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('台中');
    });

    it('點擊送出該城市的 slug 而不是顯示名稱', async () => {
        // 送出 label 的話網址會變成 ?city=東京，重新整理就找不到對應城市。
        const wrapper = mountSwitcher('taipei');

        await wrapper.findAll('.city')[2].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['tokyo']]);
    });

    it('modelValue 是不認識的值時不會有任何 active，也不會炸', () => {
        const buttons = mountSwitcher('atlantis').findAll('.city');

        expect(buttons.filter((b) => b.classes('active'))).toHaveLength(0);
    });

    it('用 aria-pressed 表達選取狀態，不是只有顏色', () => {
        const buttons = mountSwitcher('taipei').findAll('.city');

        expect(buttons.map((b) => b.attributes('aria-pressed'))).toEqual(['true', 'false', 'false']);
    });
});
