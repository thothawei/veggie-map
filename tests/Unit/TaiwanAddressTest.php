<?php

namespace Tests\Unit;

use App\Support\TaiwanAddress;
use PHPUnit\Framework\TestCase;

/**
 * 測資全部取自真實節點（2026-08-31 查 overpass-api.de，台中／高雄／台南素食餐廳，
 * 223 個不重複的 addr:full）。自己編的地址測不出「大里路」這種陷阱。
 */
class TaiwanAddressTest extends TestCase
{
    public function test_leading_postcode_is_dropped(): void
    {
        // 223 筆裡有 129 筆以郵遞區號開頭，3 碼與 5 碼都有。
        $this->assertSame('台中市西區精誠路137號', TaiwanAddress::tidy('403臺中市西區精誠路137號'));
        $this->assertSame('台中市北區五常街34號', TaiwanAddress::tidy('40458臺中市北區五常街34號'));
        $this->assertSame('台中市西區五權西三街105號', TaiwanAddress::tidy('403025臺中市西區五權西三街105號'));
    }

    public function test_number_that_is_not_a_postcode_survives(): void
    {
        // 郵遞區號後面一定接中文字。日本地址的街区符号（「2-3」）不能被當成郵遞區號切掉。
        $this->assertSame('2-3', TaiwanAddress::tidy('2-3'));
    }

    public function test_village_and_neighbourhood_are_dropped(): void
    {
        // 郵局的標準地址不寫里鄰，而且它卡在行政區與路名中間，
        // 「北屯區大連路」這種搜法會因此對不上。
        $this->assertSame(
            '台中市北屯區大連路三段14號',
            TaiwanAddress::tidy('臺中市北屯區平順里8鄰大連路三段14號'),
        );
        $this->assertSame(
            '彰化縣鹿港鎮介壽路三段66號',
            TaiwanAddress::tidy('彰化縣鹿港鎮順興里11鄰介壽路三段66號'),
        );
        // 只有里、沒有鄰的也要拿掉。
        $this->assertSame('台中市東區精武東路110號', TaiwanAddress::tidy('40147臺中市東區東英里精武東路110號'));
    }

    public function test_place_names_that_contain_the_village_character_are_kept(): void
    {
        /*
         * 這三個是回頭拿真實資料驗證才發現的坑：
         * 「大里區」「埔里鎮」的行政區名本身帶「里」，而台中大里區真的有一條「大里路」。
         */
        $this->assertSame('台中市大里區大里路272號', TaiwanAddress::tidy('412台中市大里區大里路272號'));
        $this->assertSame('台中市大里區塗城路1043號', TaiwanAddress::tidy('412台中市大里區塗城路1043號'));
        $this->assertSame('南投縣埔里鎮南安路788號', TaiwanAddress::tidy('545南投縣埔里鎮南安路788號'));
    }

    public function test_administrative_unit_is_not_swallowed_with_the_village(): void
    {
        // 里名本身不含行政區單位字：少了這道但書會從「市」一路吃到「區興邦里」，
        // 變成「高雄市前鎮時代大道33號」。
        $this->assertSame(
            '高雄市前鎮區時代大道33號',
            TaiwanAddress::tidy('806高雄市前鎮區興邦里時代大道33號'),
        );
    }

    public function test_two_addresses_joined_by_a_semicolon_keep_the_first(): void
    {
        $this->assertSame(
            '台中市太平區中山路三段67巷29號',
            TaiwanAddress::tidy('41169臺中市太平區宜昌里中山路三段67巷29號;41158臺中市太平區宜昌里富宜路262號'),
        );
    }

    public function test_city_names_are_normalised_to_the_common_form(): void
    {
        // 台中 bbox 實測：臺中市 88 家、台中市 29 家——同一個城市在篩選清單裡出現兩次。
        $this->assertSame('台中市', TaiwanAddress::normalizeName('臺中市'));
        $this->assertSame('台南市', TaiwanAddress::normalizeName('臺南市'));
        $this->assertSame('西屯區', TaiwanAddress::normalizeName('西屯區'));
        $this->assertNull(TaiwanAddress::normalizeName(''));
        $this->assertNull(TaiwanAddress::normalizeName(null));
    }

    public function test_only_city_names_lose_the_formal_character(): void
    {
        // 路名確實有「臺灣大道」這種寫法，全域置換會動到不該動的地方。
        $this->assertSame('台中市西屯區臺灣大道三段99號', TaiwanAddress::tidy('臺中市西屯區臺灣大道三段99號'));
    }

    public function test_japanese_addresses_pass_through_untouched(): void
    {
        // 實測 DB 裡 42 筆日本地址走過這個函式，0 筆變動。
        $this->assertSame('東京都千代田区日比谷公園1-2', TaiwanAddress::tidy('東京都千代田区日比谷公園1-2'));
        $this->assertSame('東京都港区麻布十番4丁目3-1', TaiwanAddress::tidy('東京都港区麻布十番4丁目3-1'));
    }

    public function test_empty_input_is_null_not_an_empty_string(): void
    {
        // 空字串是一個值，等於宣稱「這家店的地址是空的」。
        $this->assertNull(TaiwanAddress::tidy(''));
        $this->assertNull(TaiwanAddress::tidy('   '));
        $this->assertNull(TaiwanAddress::tidy(null));
    }
}
