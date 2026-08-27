import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const get = vi.fn();
const post = vi.fn();

vi.mock('@/api/client', () => ({
    default: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
    },
}));

const AdminView = (await import('./AdminView.vue')).default;

function duplicateGroup(overrides: Record<string, unknown> = {}) {
    return {
        name: '十方齋',
        stale: false,
        restaurants: [
            {
                id: 1,
                name: '十方齋',
                address: '台中市西區公益路 1 號',
                city: '台中市',
                district: '西區',
                source: 'osm',
                source_id: 'node-1',
                status: 'active',
            },
            {
                id: 2,
                name: '十方齋',
                address: '台中市西區公益路 1-1 號',
                city: '台中市',
                district: '西區',
                source: 'osm',
                source_id: 'node-2',
                status: 'active',
            },
        ],
        ...overrides,
    };
}

describe('AdminView 重複審核', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockResolvedValue({ data: { data: [] } });
        post.mockResolvedValue({ data: { success: true } });
    });

    async function openDuplicatesTab(groups: unknown[]) {
        get.mockImplementation((url: string) => {
            if (url === '/admin/duplicates') return Promise.resolve({ data: { data: groups } });

            return Promise.resolve({ data: { data: [] } });
        });

        const wrapper = mount(AdminView);
        await flushPromises();

        await wrapper.findAll('.tabs button').find((b) => b.text() === '重複審核')!.trigger('click');
        await flushPromises();

        return wrapper;
    }

    it('列出每一組候選與它們的來源，讓人判斷是不是同一家', async () => {
        const wrapper = await openDuplicatesTab([duplicateGroup()]);

        expect(wrapper.text()).toContain('十方齋');
        expect(wrapper.text()).toContain('node-1');
        expect(wrapper.text()).toContain('node-2');
    });

    it('過期的標記會標示出來，不會看起來像還有一筆重複', async () => {
        const wrapper = await openDuplicatesTab([
            duplicateGroup({ stale: true, restaurants: [duplicateGroup().restaurants[0]] }),
        ]);

        expect(wrapper.find('.stale').exists()).toBe(true);
    });

    /**
     * 只有保留／下架兩個動作。合併會把一家真實存在的店抹掉而且不可逆，
     * 所以介面上根本不提供那個按鈕。
     */
    it('只提供保留與下架，沒有合併按鈕', async () => {
        const wrapper = await openDuplicatesTab([duplicateGroup()]);

        const labels = wrapper.findAll('.duplicates button').map((b) => b.text());
        expect(labels).toEqual(['保留', '下架', '保留', '下架']);
        expect(labels).not.toContain('合併');
    });

    it('按下架會送 deactivate 並重新載入清單', async () => {
        const wrapper = await openDuplicatesTab([duplicateGroup()]);

        await wrapper.findAll('.duplicates button').find((b) => b.text() === '下架')!.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin/restaurants/1/duplicate', { action: 'deactivate' });
        expect(get).toHaveBeenLastCalledWith('/admin/duplicates');
    });

    it('沒有標記時說清楚，不是留一片空白', async () => {
        const wrapper = await openDuplicatesTab([]);

        expect(wrapper.text()).toContain('目前沒有被標記為可能重複的餐廳');
    });
});

describe('AdminView 疑似歇業', () => {
    function closureSignal(overrides: Record<string, unknown> = {}) {
        return {
            id: 7,
            signal: 'osm_node_missing',
            metadata: { source_id: '123' },
            detected_at: '2026-08-27T03:00:00+00:00',
            restaurant: {
                id: 42,
                name: '綠光食堂',
                slug: 'lu-guang-shi-tang',
                address: '台北市大安區',
                city: '台北市',
                status: 'active',
                website: null,
                google_maps_url: 'https://www.google.com/maps/search/?api=1&query=25.03,121.56',
            },
            ...overrides,
        };
    }

    beforeEach(() => {
        vi.clearAllMocks();
        get.mockResolvedValue({ data: { data: [] } });
    });

    async function openClosuresTab() {
        const wrapper = mount(AdminView);
        await flushPromises();

        get.mockResolvedValueOnce({ data: { data: [closureSignal()] } });
        const tab = wrapper.findAll('button').find((b) => b.text() === '疑似歇業');
        await tab!.trigger('click');
        await flushPromises();

        return wrapper;
    }

    it('列出訊號時說明「憑什麼說它歇業了」，不是丟一個代碼', async () => {
        const wrapper = await openClosuresTab();

        expect(get).toHaveBeenCalledWith('/admin/closures');
        expect(wrapper.text()).toContain('綠光食堂');
        // osm_node_missing 對人沒有意義。
        expect(wrapper.text()).toContain('OpenStreetMap 上查不到這個點位了');
    });

    it('直接給 Google 地圖連結，Admin 不必自己複製店名去搜', async () => {
        const wrapper = await openClosuresTab();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Google 地圖'));
        expect(link?.attributes('href')).toContain('google.com/maps');
        expect(link?.attributes('rel')).toContain('noopener');
    });

    it('確認歇業會送 confirmed，並重新載入清單', async () => {
        const wrapper = await openClosuresTab();
        post.mockResolvedValueOnce({ data: { data: {} } });
        get.mockResolvedValueOnce({ data: { data: [] } });

        const confirm = wrapper.findAll('button').find((b) => b.text() === '確認歇業並下架');
        await confirm!.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin/closures/7', { resolution: 'confirmed' });
    });

    it('誤報送 dismissed——不下架', async () => {
        const wrapper = await openClosuresTab();
        post.mockResolvedValueOnce({ data: { data: {} } });
        get.mockResolvedValueOnce({ data: { data: [] } });

        const dismiss = wrapper.findAll('button').find((b) => b.text() === '誤報，店還在');
        await dismiss!.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/admin/closures/7', { resolution: 'dismissed' });
    });
});
