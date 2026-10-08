<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Laravel\EYazismaManager;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Xml\Okuyucu;
use PHPUnit\Framework\TestCase;

class ManagerTest extends TestCase
{
    public function test_new_package_is_prefilled_from_the_configuration(): void
    {
        $yonetici = new EYazismaManager([
            'olusturan' => [
                'tur' => 'tuzel',
                'kimlik' => '0123456789012345',
                'kimlik_semasi' => 'MERSIS',
                'ad' => 'Örnek Derneği',
                'iletisim' => ['e_posta' => 'bilgi@ornek.org.tr', 'il' => 'Ankara', 'telefon' => null],
            ],
            'dogrulama_adresi' => 'https://ornek.org.tr/dogrula',
            'dil' => 'eng',
            'surum' => '2.1',
            'ozet_algoritmalari' => ['sha384', 'sha512'],
        ]);

        $paket = $yonetici->yeni()
            ->konu('Konu')
            ->dagitim(new KurumKurulus('24301050'))
            ->ustYazi(Dosya::icerikten('%PDF-1.4', 'yazi.pdf'))
            ->olustur();

        $this->assertEquals(
            TuzelSahis::mersis('0123456789012345', 'Örnek Derneği', new IletisimBilgisi(ePosta: 'bilgi@ornek.org.tr', il: 'Ankara')),
            $paket->ustveri()->olusturan
        );
        $this->assertSame('https://ornek.org.tr/dogrula', $paket->ustveri()->dogrulamaAdresi);
        $this->assertSame('eng', $paket->ustveri()->dil);
        $this->assertSame('2.1', $paket->ozellikler()->surum);
        $this->assertSame(
            [OzetAlgoritmasi::Sha384->value, OzetAlgoritmasi::Sha512->value],
            array_map(fn ($kalem) => $kalem->algoritma, Okuyucu::ozet($paket->paketOzeti(), 'PaketOzeti')['referanslar'][0]->ozetler)
        );
    }

    public function test_public_institution_can_be_the_creator(): void
    {
        $yonetici = new EYazismaManager(['olusturan' => ['tur' => 'kurum', 'kimlik' => '24301050', 'ad' => 'Adalet Bakanlığı']]);

        $this->assertEquals(new KurumKurulus('24301050', 'Adalet Bakanlığı'), $yonetici->olusturan());
    }

    public function test_creator_is_left_empty_without_an_identifier(): void
    {
        $this->assertNull((new EYazismaManager)->olusturan());

        $this->expectException(EYazismaException::class);
        $this->expectExceptionMessage('oluşturan');

        (new EYazismaManager)->yeni()->konu('Konu')->olustur();
    }
}
