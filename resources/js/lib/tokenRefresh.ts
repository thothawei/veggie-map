/**
 * token 過期前該不該換一張新的（`config('sanctum.expiration')`，2026-09-06 加上）。
 * 抽成純函式方便測試——不用真的等分鐘數過去，也不用 mock 計時器。
 */
export function shouldRefreshToken(
    expiresAt: string | null,
    now: Date = new Date(),
    bufferMinutes = 60,
): boolean {
    if (!expiresAt) {
        // 沒有過期時間就是永不過期（`config('sanctum.expiration')` 沒設），
        // 不用排程 refresh。
        return false;
    }

    const expiresAtMs = new Date(expiresAt).getTime();

    if (Number.isNaN(expiresAtMs)) {
        return false;
    }

    return expiresAtMs - now.getTime() <= bufferMinutes * 60_000;
}
