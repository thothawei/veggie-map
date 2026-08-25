<?php

namespace Tests\Unit;

use App\Support\AddressFormatter;
use Tests\TestCase;

class AddressFormatterTest extends TestCase
{
    public function test_joins_city_district_and_street(): void
    {
        $this->assertSame(
            '台中市西區公益路 100 號',
            AddressFormatter::compose('公益路 100 號', '台中市', '西區'),
        );
    }

    public function test_does_not_repeat_city_already_in_the_address(): void
    {
        $this->assertSame(
            '台中市西區公益路 100 號',
            AddressFormatter::compose('台中市西區公益路 100 號', '台中市', '西區'),
        );
    }

    public function test_empty_parts_become_null_instead_of_blank_spaces(): void
    {
        $this->assertNull(AddressFormatter::compose('', '', ''));
        $this->assertNull(AddressFormatter::compose(null, null, null));
        $this->assertSame('信義路 7', AddressFormatter::compose('信義路 7', '', ''));
        $this->assertSame('台中市', AddressFormatter::compose('', '台中市', null));
    }
}
