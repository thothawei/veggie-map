<?php

namespace App\Support;

/**
 * 台灣地址的正規化。跟 OSM 的日本地址組裝（見 OsmRestaurantProvider::buildAddress）
 * 是同一件事的另一半：把來源寫法各異的字串收斂成使用者會唸、也會拿去搜尋的那一種。
 *
 * 每一條規則都對應 2026-08-31 從 Overpass 取回的台中／高雄／台南素食節點實測
 * （223 個不重複的 addr:full）：
 *
 * - 129 筆以郵遞區號開頭（「403臺中市西區精誠路137號」「40147臺中市東區…」）
 * - 109 筆寫「臺」而不是「台」，同一個城市因此在篩選清單裡出現兩次
 *   （台中 bbox：臺中市 88／台中市 29）
 * - 43 筆夾著里鄰（「臺中市北屯區平順里8鄰大連路三段14號」），郵局的標準地址不寫它，
 *   而且它會卡在行政區與路名中間，讓「北屯區大連路」這種搜法對不上
 * - 1 筆用分號串了兩個地址
 *
 * 「臺→台」刻意只換城市名（臺北／臺中／臺南／臺東 ＋ 市／縣）而不是全字串置換：
 * 實測 223 筆裡的「臺」全部都是城市名，但路名確實有「臺灣大道」這種寫法，
 * 全域置換會動到不該動的地方，而收斂城市名的目的一個字都沒多做到。
 */
final class TaiwanAddress
{
    /**
     * city／district 欄位用。這兩個欄位是篩選清單的來源，寫法不統一會長出重複選項。
     */
    public static function normalizeName(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return self::normalizeTai($value);
    }

    /**
     * addr:full／組裝出來的地址用。
     */
    public static function tidy(?string $address): ?string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return null;
        }

        // 分號串起來的兩個地址不是地址，取第一個。
        $address = trim(explode(';', $address)[0]);

        // 開頭的 3～6 碼郵遞區號。要求後面接的是中文字，才不會把日本地址的
        // 街区符号（「2-3」）或門牌數字當成郵遞區號切掉。
        $address = preg_replace('/^\d{3,6}(?=\p{Han})/u', '', $address) ?? $address;

        /*
         * 里鄰。只在行政區單位（市／區／鎮／鄉／縣）後面拿掉，而且有兩道但書——
         * 兩道都是拿真實資料回頭檢查才發現的：
         *
         * 1. 里名後面不能接區／鎮／鄉／路／街／巷／弄／道／段：「大里區」「埔里鎮」
         *    本身帶「里」字，而台中大里區真的有一條「大里路」——少了這一條，
         *    「台中市大里區大里路272號」會被切成「台中市大里區路272號」。
         * 2. 里名本身不能含行政區單位字：少了這一條，「高雄市前鎮區興邦里時代大道」
         *    會從「市」後面一路吃掉「區興邦里」，變成「高雄市前鎮時代大道」。
         */
        $address = preg_replace('/(?<=[市區鎮鄉縣])(?:(?![區鎮鄉市縣])\p{Han}){1,3}里(?![區鎮鄉路街巷弄道段])(\d+鄰)?/u', '', $address) ?? $address;
        $address = preg_replace('/(?<=[市區鎮鄉縣])\d+鄰/u', '', $address) ?? $address;

        $address = self::normalizeTai($address);

        $address = trim($address);

        return $address === '' ? null : $address;
    }

    private static function normalizeTai(string $value): string
    {
        return preg_replace('/臺(?=[北中南東][市縣])/u', '台', $value) ?? $value;
    }
}
