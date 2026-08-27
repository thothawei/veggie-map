<?php

namespace App\Services\External;

/**
 * 一家店現在還在不在。
 *
 * 只有三種：**永久歇業**、還在營業、以及「不知道」。
 * 「不知道」是獨立的一種而不是併進「還在營業」——資料來源查不到、超時、
 * 或回了看不懂的東西時，唯一誠實的答案是不知道，而把不知道當成營業中會讓
 * 自動下架永遠不敢動手；反過來當成歇業則會把還在營業的店從地圖上抹掉。
 * 兩種錯的代價不對等，所以這一層不做猜測，交給呼叫端決定怎麼處置。
 */
enum BusinessStatus: string
{
    case Operational = 'operational';

    case ClosedPermanently = 'closed_permanently';

    case Unknown = 'unknown';

    public function isClosedPermanently(): bool
    {
        return $this === self::ClosedPermanently;
    }
}
