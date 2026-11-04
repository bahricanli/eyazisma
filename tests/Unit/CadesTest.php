<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Cades\Cades;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Tests\OpenSslImzalayici;
use PHPUnit\Framework\TestCase;

class CadesTest extends TestCase
{
    private const ICERIK = '<PaketOzeti Id="1">özet</PaketOzeti>';

    public function test_signer_certificate_is_read_from_a_valid_signature(): void
    {
        $imza = (new OpenSslImzalayici(['commonName' => 'AYŞE YILMAZ', 'serialNumber' => '12345678950']))->imzala(self::ICERIK);

        $sertifikalar = Cades::imzacilar($imza, self::ICERIK);

        $this->assertCount(1, $sertifikalar);
        $this->assertSame('AYŞE YILMAZ', $sertifikalar[0]->adSoyad);
        $this->assertSame('12345678950', $sertifikalar[0]->tckn());
    }

    public function test_signature_over_other_content_is_refused(): void
    {
        $imza = (new OpenSslImzalayici)->imzala('başka içerik');

        $this->expectException(EYazismaException::class);
        Cades::imzacilar($imza, self::ICERIK);
    }

    public function test_tampered_signature_is_refused(): void
    {
        $imza = (new OpenSslImzalayici)->imzala(self::ICERIK);
        $bozuk = substr($imza, 0, -10).str_repeat("\x01", 10);

        $this->expectException(EYazismaException::class);
        Cades::imzacilar($bozuk, self::ICERIK);
    }

    public function test_signatures_over_the_same_content_merge_into_one_block(): void
    {
        $birinci = (new OpenSslImzalayici(['commonName' => 'AYŞE YILMAZ', 'serialNumber' => '12345678950']))->imzala(self::ICERIK);
        $ikinci = (new OpenSslImzalayici(['commonName' => 'MEHMET KAYA', 'serialNumber' => '10000000146']))->imzala(self::ICERIK);

        $birlesik = Cades::birlestir($birinci, $ikinci);
        $sertifikalar = Cades::imzacilar($birlesik, self::ICERIK);

        $this->assertEqualsCanonicalizing(
            ['12345678950', '10000000146'],
            array_map(fn ($sertifika) => $sertifika->tckn(), $sertifikalar),
        );
        $this->assertSame($birlesik, Cades::birlestir($ikinci, $birinci), 'Sıra sonucu değiştirmemeli.');
        $this->assertSame($birinci, Cades::birlestir($birinci));
    }

    public function test_signatures_over_different_content_do_not_merge(): void
    {
        $birinci = (new OpenSslImzalayici)->imzala(self::ICERIK);
        $ikinci = (new OpenSslImzalayici)->imzala('başka içerik');

        $this->expectException(EYazismaException::class);
        Cades::birlestir($birinci, $ikinci);
    }

    public function test_non_cms_input_is_refused(): void
    {
        $this->expectException(EYazismaException::class);
        Cades::birlestir('imza değil', 'imza değil');
    }
}
