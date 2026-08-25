/**
 * 登入成功後的 redirect 只接受站內路徑。`//evil.com` 或 `https://...`
 * 若原封不動丟進 router.push，有機會被當成外部導向。
 */
export function safeInternalPath(value: unknown, fallback = '/'): string {
    if (typeof value !== 'string' || value === '') {
        return fallback;
    }

    if (!value.startsWith('/') || value.startsWith('//') || value.includes('\\')) {
        return fallback;
    }

    return value;
}

/**
 * OSM 的 website 會原樣塞進 `<a href>`。只放行 http/https，避免 `javascript:`。
 */
export function safeHttpUrl(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    try {
        const url = new URL(trimmed);

        if (url.protocol === 'http:' || url.protocol === 'https:') {
            return url.href;
        }
    } catch {
        return null;
    }

    return null;
}
