<?php

namespace Tests\Unit;

use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Support\IndianCurrency;
use PHPUnit\Framework\TestCase;

class CustomerChannelHelpersTest extends TestCase
{
    public function test_amounts_in_words_use_indian_grouping(): void
    {
        $this->assertSame('Rupees Twelve Thousand Two Hundred Seventy Only', IndianCurrency::inWords(12270));
        $this->assertSame('Rupees One Lakh Five Thousand Only', IndianCurrency::inWords(105000));
        $this->assertSame('Rupees Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Only', IndianCurrency::inWords(2345678));
        $this->assertSame('Rupees One Crore Only', IndianCurrency::inWords(10000000));
        $this->assertSame('Rupees Ninety Nine Only', IndianCurrency::inWords(99));
        $this->assertSame('Rupees Zero Only', IndianCurrency::inWords(0));
        // Paise are rounded to the rupee, as on the grand total
        $this->assertSame('Rupees One Hundred One Only', IndianCurrency::inWords(100.5));
    }

    public function test_whatsapp_numbers_are_normalised_to_india(): void
    {
        $this->assertSame('919876543210', ShareLinkChannel::normalizePhone('9876543210'));
        $this->assertSame('919876543210', ShareLinkChannel::normalizePhone('+91 98765 43210'));
        $this->assertSame('919876543210', ShareLinkChannel::normalizePhone('09876543210'));
        $this->assertSame('919876543210', ShareLinkChannel::normalizePhone('91-9876-543210'));
        $this->assertNull(ShareLinkChannel::normalizePhone('022 2345678'));
        $this->assertNull(ShareLinkChannel::normalizePhone(null));
    }

    public function test_share_link_encodes_the_message(): void
    {
        $url = (new ShareLinkChannel())->shareUrl('919876543210', "Hello & welcome\nLine 2");

        $this->assertSame('https://wa.me/919876543210?text=Hello%20%26%20welcome%0ALine%202', $url);
    }
}
