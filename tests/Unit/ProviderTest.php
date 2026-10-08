<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Laravel\EYazismaManager;
use BahriCanli\EYazisma\Laravel\EYazismaServiceProvider;
use BahriCanli\EYazisma\Laravel\Facades\EYazisma;
use BahriCanli\EYazisma\Model\KurumKurulus;
use Orchestra\Testbench\TestCase;

class ProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EYazismaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('eyazisma.olusturan.kimlik', '0123456789012345');
        $app['config']->set('eyazisma.olusturan.ad', 'Örnek Derneği');
        $app['config']->set('eyazisma.dogrulama_adresi', 'https://ornek.org.tr/dogrula');
    }

    public function test_facade_builds_a_prefilled_package(): void
    {
        $this->assertSame('tur', config('eyazisma.dil'));
        $this->assertSame(app(EYazismaManager::class), app('eyazisma'));

        $paket = EYazisma::yeni()->konu('Konu')->dagitim(new KurumKurulus('24301050'))->ustYazi(Dosya::icerikten('%PDF-1.4', 'yazi.pdf'))->olustur();

        $this->assertSame('Örnek Derneği', $paket->ustveri()->olusturan->gorunenAd());
        $this->assertSame('Türkiye', $paket->ustveri()->olusturan->iletisimBilgisi->ulke);
        $this->assertSame($paket->belgeId(), EYazisma::icerikten($paket->icerik())->belgeId());
    }
}
