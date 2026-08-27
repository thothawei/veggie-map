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

    /**
     * 來源查得動，但這個點位已經不在了（OSM 節點被刪掉）。
     *
     * 跟 Unknown 分開是必要的：Unknown 是「我沒查成功」（超時、HTTP 500、
     * 來源沒收錄），Missing 是「我查成功了，它不在」。混在一起的話，Overpass
     * 掛掉一次就會替所有店產生一批假的「疑似歇業」訊號洗版 Admin 的待審清單。
     *
     * 不直接當成歇業：節點會因為被合併進 way／building、改成別的 element、
     * 或單純被誤刪而消失。它是線索，交給人判斷。
     */
    case Missing = 'missing';

    case Unknown = 'unknown';

    public function isClosedPermanently(): bool
    {
        return $this === self::ClosedPermanently;
    }
}
