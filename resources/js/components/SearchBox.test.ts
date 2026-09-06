import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SearchBox from './SearchBox.vue';

const get = vi.fn();

vi.mock('@/api/client', () => ({
    default: { get: (...args: unknown[]) => get(...args) },
}));

// 最近搜尋（A7）存在 localStorage，是跨測試共用的真實瀏覽器 API，不清掉的話
// 前一條測試搜過的字會被下一條看到——每條測試都要從空的最近搜尋清單開始。
beforeEach(() => {
    localStorage.clear();
});

describe('SearchBox', () => {
    it('geocode 失敗時顯示錯誤，不是靜默沒反應', async () => {
        get.mockRejectedValueOnce(new Error('network'));

        const wrapper = mount(SearchBox);
        await wrapper.find('input').setValue('台中一中街');
        await wrapper.find('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('搜尋地點失敗');
    });

    it('點搜尋按鈕不會讓輸入框失焦，否則候選清單會在 geocode 回來前被關掉', async () => {
        // 這條測的是 @mousedown.prevent 有沒有掛在按鈕上。jsdom 不會因為點按鈕
        // 就真的觸發 blur，所以上面那些 trigger('click') 的測試在有 bug 的版本
        // 也照樣是綠的——真實瀏覽器才看得到「按鈕按下去毫無反應」。
        // 把 .prevent 拿掉，這條會紅。
        const wrapper = mount(SearchBox);
        const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true });

        wrapper.find('button').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
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

describe('SearchBox 鍵盤操作與 a11y（A6）', () => {
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

    /** 三個候選：搜尋餐廳（永遠第一）／店名／料理種類。 */
    function mountWithSuggestions() {
        get.mockResolvedValue(suggestPayload({
            restaurants: [{ id: 7, name: '十方齋', slug: 'a', address: '公益路 1 號', city: '台中市', district: '西區' }],
            cuisines: [{ code: 'japanese', label: '日式料理' }],
        }));

        return mount(SearchBox);
    }

    async function typeAndSettle(wrapper: ReturnType<typeof mount>, value: string) {
        await wrapper.find('input').setValue(value);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
    }

    it('輸入框有 combobox 語意，清單有 listbox 語意', async () => {
        const wrapper = mountWithSuggestions();
        const input = wrapper.find('input');

        expect(input.attributes('role')).toBe('combobox');
        expect(input.attributes('aria-expanded')).toBe('false');

        await typeAndSettle(wrapper, '十方');

        const list = wrapper.find('[role="listbox"]');
        expect(wrapper.find('input').attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('input').attributes('aria-controls')).toBe(list.attributes('id'));
        expect(wrapper.findAll('[role="option"]').length).toBe(3);
    });

    it('只用鍵盤能走到第三個建議並選取', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const input = wrapper.find('input');
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });

        // 第三項是料理種類「日式料理」。
        expect(wrapper.findAll('[role="option"]')[2].attributes('aria-selected')).toBe('true');

        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('keyword-search')?.[0]).toEqual(['日式料理']);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    /**
     * 反向驗證用的那條：把 aria-activedescendant 的更新拿掉，讀螢幕使用者
     * 完全不知道游標移到哪一項，這條會紅。
     */
    it('aria-activedescendant 指向目前那一項，關掉清單就不再指', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const input = wrapper.find('input');
        expect(input.attributes('aria-activedescendant')).toBeUndefined();

        await input.trigger('keydown', { key: 'ArrowDown' });

        const active = wrapper.findAll('[role="option"]')[0];
        expect(wrapper.find('input').attributes('aria-activedescendant')).toBe(active.attributes('id'));

        await input.trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('input').attributes('aria-activedescendant')).toBeUndefined();
    });

    it('↑ 從最後一項開始，↓ 到底會繞回第一項', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const input = wrapper.find('input');
        await input.trigger('keydown', { key: 'ArrowUp' });
        expect(wrapper.findAll('[role="option"]')[2].attributes('aria-selected')).toBe('true');

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.findAll('[role="option"]')[0].attributes('aria-selected')).toBe('true');
    });

    it('Esc 關掉清單但留著使用者打的字', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        await wrapper.find('input').trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('十方');
    });

    /**
     * 2026-09-06 真瀏覽器實測：Chrome 對 `<input type="search">` 的原生 Esc 行為是
     * **清空輸入框**。jsdom 沒有這個行為，所以上面那條「留著使用者打的字」在沒有
     * preventDefault 的版本照樣是綠的——這條直接測 defaultPrevented 才守得住。
     */
    it('清單開著時 Esc 要擋掉瀏覽器原生的「清空搜尋框」', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        wrapper.find('input').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('清單已經關著時 Esc 不攔截，使用者仍然清得掉輸入框', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');
        await wrapper.find('input').trigger('keydown', { key: 'Escape' });

        const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        wrapper.find('input').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('Tab 關掉清單、不幫使用者選任何一項', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const input = wrapper.find('input');
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Tab' });

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(wrapper.emitted('keyword-search')).toBeFalsy();
        expect(wrapper.emitted('restaurant-selected')).toBeFalsy();
    });

    it('沒有停在任何候選上時，Enter 維持送出地點查詢', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/geocode') return Promise.resolve({ data: { data: [] } });

            return Promise.resolve({ data: { data: { restaurants: [], cuisines: [], districts: [] } } });
        });

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '台中一中街');
        await wrapper.find('input').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/geocode', { params: { q: '台中一中街' } });
    });

    it('候選內容換了就把游標收回去，Enter 不會選到使用者沒看過的那一項', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');
        await wrapper.find('input').trigger('keydown', { key: 'ArrowDown' });
        await wrapper.find('input').trigger('keydown', { key: 'ArrowDown' });

        get.mockResolvedValue(suggestPayload({
            restaurants: [{ id: 99, name: '十方齋二店', slug: 'b', address: null, city: null, district: null }],
        }));
        await typeAndSettle(wrapper, '十方齋');

        expect(wrapper.find('input').attributes('aria-activedescendant')).toBeUndefined();
        expect(wrapper.findAll('[aria-selected="true"]')).toHaveLength(0);
    });

    it('滑鼠點候選仍然是 mousedown.prevent，不然 blur 會先把清單關掉', async () => {
        const wrapper = mountWithSuggestions();
        await typeAndSettle(wrapper, '十方');

        const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        wrapper.findAll('[role="option"]')[1].element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});

describe('SearchBox 最近搜尋（A7）', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
        get.mockResolvedValue({ data: { data: { restaurants: [], cuisines: [], districts: [] } } });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    async function typeAndSettle(wrapper: ReturnType<typeof mount>, value: string) {
        await wrapper.find('input').setValue(value);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
    }

    it('第一次使用、還沒有最近搜尋時，focus 空白輸入框不會打開清單', async () => {
        const wrapper = mount(SearchBox);
        await wrapper.find('input').trigger('focus');

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('搜過的關鍵字會存起來，下次 focus 空輸入框就看得到', async () => {
        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');

        expect(wrapper.emitted('keyword-search')?.[0]).toEqual(['拉麵']);

        // 換一顆全新的 SearchBox（模擬下次打開頁面）：localStorage 是真的存進去了，
        // 不是只活在同一個元件實例的記憶體裡。
        const fresh = mount(SearchBox);
        await fresh.find('input').trigger('focus');

        const options = fresh.findAll('[role="option"]');
        expect(options).toHaveLength(1);
        expect(options[0].text()).toBe('拉麵');
        expect(fresh.find('.recent-header').text()).toContain('最近搜尋');
    });

    it('選料理種類／行政區、選地點也算一次搜尋，會被記住', async () => {
        get.mockResolvedValue({
            data: { data: { restaurants: [], cuisines: [{ code: 'japanese', label: '日式料理' }], districts: [] } },
        });

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '日式');
        await wrapper.find('.suggestion').trigger('mousedown');

        const fresh = mount(SearchBox);
        await fresh.find('input').trigger('focus');

        expect(fresh.findAll('[role="option"]').map((o) => o.text())).toContain('日式料理');
    });

    it('選詳情頁的店名建議不算搜尋——那是選中一家已知的店，不是打了什麼詞', async () => {
        get.mockResolvedValue({
            data: {
                data: {
                    restaurants: [{ id: 1, name: '十方齋', slug: 'a', address: null, city: null, district: null }],
                    cuisines: [],
                    districts: [],
                },
            },
        });

        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '十方齋');
        await wrapper.find('.suggestion').trigger('mousedown');

        const fresh = mount(SearchBox);
        await fresh.find('input').trigger('focus');

        expect(fresh.find('[role="listbox"]').exists()).toBe(false);
    });

    it('同一個詞再搜一次，清單裡只有一筆，而且排到最前面', async () => {
        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');

        await typeAndSettle(wrapper, '滷味');
        await wrapper.find('.keyword-option').trigger('mousedown');

        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');

        await wrapper.find('input').setValue('');
        await wrapper.find('input').trigger('focus');

        const texts = wrapper.findAll('[role="option"]').map((o) => o.text());
        expect(texts).toEqual(['拉麵', '滷味']);
    });

    it('最多存 5 筆，最舊的被擠掉', async () => {
        const wrapper = mount(SearchBox);

        for (const term of ['一', '二', '三', '四', '五', '六']) {
            await typeAndSettle(wrapper, term);
            await wrapper.find('.keyword-option').trigger('mousedown');
        }

        await wrapper.find('input').setValue('');
        await wrapper.find('input').trigger('focus');

        const texts = wrapper.findAll('[role="option"]').map((o) => o.text());
        expect(texts).toEqual(['六', '五', '四', '三', '二']);
        expect(texts).not.toContain('一');
    });

    it('按「清除」會清空清單，而且真的從 localStorage 移除，不是只清畫面', async () => {
        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');
        await wrapper.find('input').setValue('');
        await wrapper.find('input').trigger('focus');

        await wrapper.find('.clear-recent').trigger('mousedown');

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(localStorage.getItem('veggiemap:recent-searches')).toBeNull();

        const fresh = mount(SearchBox);
        await fresh.find('input').trigger('focus');
        expect(fresh.find('[role="listbox"]').exists()).toBe(false);
    });

    it('「清除」按鈕是 mousedown.prevent，不然點下去會先讓輸入框失焦、清單被關掉', async () => {
        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');
        await wrapper.find('input').setValue('');
        await wrapper.find('input').trigger('focus');

        const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        wrapper.find('.clear-recent').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('只用鍵盤也能選到最近搜尋的項目', async () => {
        const wrapper = mount(SearchBox);
        await typeAndSettle(wrapper, '拉麵');
        await wrapper.find('.keyword-option').trigger('mousedown');
        await wrapper.find('input').setValue('');

        const fresh = mount(SearchBox);
        const input = fresh.find('input');
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Enter' });

        expect(fresh.emitted('keyword-search')?.[0]).toEqual(['拉麵']);
    });

    it('存進 localStorage 的格式壞掉時安靜地當作沒有最近搜尋，不會讓元件整個炸掉', async () => {
        localStorage.setItem('veggiemap:recent-searches', '{not json');

        const wrapper = mount(SearchBox);
        await wrapper.find('input').trigger('focus');

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});
