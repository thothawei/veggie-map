import { vi } from 'vitest';

/**
 * jsdom 沒有實作 matchMedia。FilterDrawer 靠它決定窄螢幕要不要收合，沒有這個 stub
 * 元件一掛載就會炸。預設回 false（窄螢幕），需要桌機行為的測試自己用
 * `setViewportMatches(true)` 覆蓋。
 */
let matches = false;

export function setViewportMatches(value: boolean): void {
    matches = value;
}

vi.stubGlobal(
    'matchMedia',
    (query: string): MediaQueryList =>
        ({
            matches,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }) as unknown as MediaQueryList,
);
