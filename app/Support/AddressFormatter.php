<?php

namespace App\Support;

/**
 * 把 city／district／address 拼成人類看得懂的一行，重複的部分不加第二次。
 * OSM 匯入時常只有路名、city 欄位另外有值（或反過來），前端／API 都走這裡。
 */
class AddressFormatter
{
    public static function compose(?string $address, ?string $city = null, ?string $district = null): ?string
    {
        $address = trim((string) $address);
        $city = trim((string) $city);
        $district = trim((string) $district);

        $parts = [];

        if ($city !== '' && ! str_contains($address, $city)) {
            $parts[] = $city;
        }

        if ($district !== '' && ! str_contains($address, $district) && ! str_contains($city, $district)) {
            $parts[] = $district;
        }

        if ($address !== '') {
            $parts[] = $address;
        }

        $result = implode('', $parts);

        return $result === '' ? null : $result;
    }
}
