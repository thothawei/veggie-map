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

/**
 * jsdom 也沒有 ResizeObserver。RestaurantMap 用它補送掛載當下容器還沒量到尺寸
 * 的那次 bbox（見該元件註解），沒有 stub 的話元件一掛載就炸。
 *
 * 這個 stub 會把 callback 存起來，`triggerResize()` 讓測試可以主動觸發一次，
 * 驗證「尺寸確定後真的有補送」——只是讓它不炸的話，那條行為就沒有人守著。
 */
const resizeCallbacks: ResizeObserverCallback[] = [];

export function triggerResize(): void {
    for (const callback of resizeCallbacks) {
        callback([], {} as ResizeObserver);
    }
}

vi.stubGlobal(
    'ResizeObserver',
    class {
        constructor(callback: ResizeObserverCallback) {
            resizeCallbacks.push(callback);
        }

        observe(): void {}

        unobserve(): void {}

        disconnect(): void {}
    },
);
