<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Dogrulama\Bulgu;
use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\EkTuru;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Exceptions\DesteklenmeyenPaketException;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use BahriCanli\EYazisma\Guid;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Opc\Package;
use BahriCanli\EYazisma\Paket;
use BahriCanli\EYazisma\PaketOlusturucu;
use BahriCanli\EYazisma\Tests\OpenSslImzalayici;
use BahriCanli\EYazisma\Tests\SahteImzalayici;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Okuyucu;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class PaketTest extends TestCase
{
    private const EK_ID = '48ECB045-06C5-43DA-BACE-50BA73A8D129';

    public function test_new_package_waits_for_a_signature(): void
    {
        $paket = $this->olusturucu()->olustur();

        $this->assertSame(PaketAsamasi::ImzaBekliyor, $paket->asama());
        $this->assertTrue(Guid::gecerliMi($paket->belgeId()));
        $this->assertSame(strtoupper($paket->belgeId()), $paket->belgeId());
        $this->assertSame($paket->belgeId().'.eyp', $paket->dosyaAdi());
        $this->assertNull($paket->imza());
        $this->assertNull($paket->nihaiOzet());
        $this->assertNull($paket->nihaiUstveri());

        $ozet = Okuyucu::ozet($paket->paketOzeti(), 'PaketOzeti');

        $this->assertSame($paket->belgeId(), $ozet['id']);
        $this->assertSame(
            ['/Ustveri/Ustveri.xml', '/UstYazi/Ust_Yazi.pdf', '/Ekler/Calisma_Raporu.pdf'],
            array_map(fn ($referans) => $referans->uri, $ozet['referanslar'])
        );
        $this->assertSame(
            [OzetAlgoritmasi::Sha256->value, OzetAlgoritmasi::Sha512->value],
            array_map(fn ($kalem) => $kalem->algoritma, $ozet['referanslar'][0]->ozetler)
        );
    }

    public function test_package_metadata_and_files_are_read_back(): void
    {
        $paket = Paket::icerikten($this->olusturucu()->olustur()->icerik());
        $ustveri = $paket->ustveri();

        $this->assertSame('Şenlik daveti', $ustveri->konu);
        $this->assertSame('application/pdf', $ustveri->mimeTuru);
        $this->assertSame('Ust_Yazi.pdf', $ustveri->dosyaAdi);
        $this->assertSame('tur', $ustveri->dil);
        $this->assertEquals(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği'), $ustveri->olusturan);
        $this->assertCount(2, $ustveri->dagitimlar);
        $this->assertSame(DagitimTuru::Bilgi, $ustveri->dagitimlar[1]->dagitimTuru);

        $this->assertSame(
            [EkTuru::DahiliElektronikDosya, EkTuru::DahiliElektronikDosya, EkTuru::FizikselNesne, EkTuru::HariciReferans],
            array_map(fn ($ek) => $ek->tur, $ustveri->ekler)
        );
        $this->assertSame([1, 2, 3, 4], array_map(fn ($ek) => $ek->siraNo, $ustveri->ekler));
        $this->assertSame([true, false, false, false], array_map(fn ($ek) => $ek->imzaliMi, $ustveri->ekler));
        $this->assertSame('Calisma_Raporu.pdf', $ustveri->ekler[0]->dosyaAdi);

        $this->assertSame('%PDF-1.4 üst yazı', $paket->ustYazi()->icerik);
        $this->assertSame('application/pdf', $paket->ustYazi()->mimeTuru);
        $this->assertSame('%PDF-1.4 rapor', $paket->ekDosyasi(strtolower(self::EK_ID))->icerik);
        $this->assertSame('makrolu tablo', $paket->ekDosyasi($ustveri->ekler[1]->id)->icerik);
        $this->assertCount(2, $paket->ekDosyalari());
        $this->assertNull($paket->ekDosyasi($ustveri->ekler[2]->id));

        $ozellikler = $paket->ozellikler();

        $this->assertSame($paket->belgeId(), $ozellikler->tanimlayici);
        $this->assertSame('Şenlik daveti', $ozellikler->konu);
        $this->assertSame('Örnek Derneği/0123456789012345', $ozellikler->olusturan);
        $this->assertSame(PaketOzellikleri::KATEGORI_RESMI_YAZISMA, $ozellikler->kategori);
        $this->assertSame(PaketOzellikleri::ICERIK_TURU, $ozellikler->icerikTuru);
        $this->assertSame('2.0', $ozellikler->surum);
    }

    public function test_signed_and_sealed_package_is_valid(): void
    {
        $imzalayici = new SahteImzalayici;
        $paket = $this->olusturucu()->olustur()->imzala($imzalayici, $this->nihaiUstveri());

        $this->assertSame(PaketAsamasi::MuhurBekliyor, $paket->asama());
        $this->assertSame(
            [
                '/Ustveri/Ustveri.xml', '/UstYazi/Ust_Yazi.pdf', '/PaketOzeti/PaketOzeti.xml', '/Imzalar/ImzaCades.imz',
                '/NihaiUstveri/NihaiUstveri.xml', '/Ekler/Calisma_Raporu.pdf', $paket->bilesenAdi(Ad::ILISKI_CORE),
            ],
            array_map(fn ($referans) => $referans->uri, Okuyucu::ozet($paket->nihaiOzet(), 'NihaiOzet')['referanslar'])
        );

        $paket->muhurle($imzalayici);

        $this->assertSame(PaketAsamasi::Tamamlandi, $paket->asama());

        $okunan = Paket::icerikten($paket->icerik());
        $rapor = $okunan->dogrula();

        $this->assertSame([], array_map('strval', $rapor->bulgular));
        $this->assertTrue($rapor->gecerli());
        $this->assertSame(PaketAsamasi::Tamamlandi, $okunan->asama());
        $this->assertSame('2026/41', $okunan->nihaiUstveri()->belgeNo);
        $this->assertSame('Ayşe Yılmaz', $okunan->nihaiUstveri()->imzalar[0]->imzalayan->gorunenAd());
        $this->assertSame($imzalayici->imzala($okunan->paketOzeti()), $okunan->imza());
        $this->assertSame($imzalayici->imzala($okunan->nihaiOzet()), $okunan->muhur());
    }

    public function test_package_signed_with_a_real_cms_signature_is_valid(): void
    {
        if (! function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('ext-openssl yok.');
        }

        $imzalayici = new OpenSslImzalayici;
        $paket = $this->olusturucu()->olustur()->imzala($imzalayici, $this->nihaiUstveri())->muhurle($imzalayici);
        $rapor = Paket::icerikten($paket->icerik())->dogrula();

        $this->assertSame([], array_map('strval', $rapor->bulgular));
        $this->assertNotSame($paket->imza(), $paket->muhur());
    }

    public function test_package_can_be_saved_and_resumed_between_steps(): void
    {
        $yol = tempnam(sys_get_temp_dir(), 'eyp');

        try {
            $ilk = $this->olusturucu()->olustur();
            $ilk->kaydet($yol);

            $ikinci = Paket::ac($yol);
            $this->assertSame($ilk->paketOzeti(), $ikinci->paketOzeti());

            $ikinci->imzaEkle((new SahteImzalayici)->imzala($ikinci->paketOzeti()), $this->nihaiUstveri())->kaydet($yol);

            $ucuncu = Paket::ac($yol);
            $this->assertSame(PaketAsamasi::MuhurBekliyor, $ucuncu->asama());
            $this->assertSame($ikinci->nihaiOzet(), $ucuncu->nihaiOzet());

            $ucuncu->muhurEkle((new SahteImzalayici)->imzala($ucuncu->nihaiOzet()))->kaydet($yol);

            $this->assertTrue(Paket::ac($yol)->dogrula()->gecerli());
        } finally {
            @unlink($yol);
        }
    }

    public function test_unsigned_and_unsealed_packages_are_reported(): void
    {
        $paket = $this->olusturucu()->olustur();

        $this->assertFalse($paket->dogrula()->gecerli());
        $this->assertStringContainsString('imzalanmamış', $this->hatalar($paket));

        $paket->imzala(new SahteImzalayici, $this->nihaiUstveri());

        $this->assertStringContainsString('mühürlenmemiş', $this->hatalar($paket));
        $this->assertStringNotContainsString('imzalanmamış', $this->hatalar($paket));
    }

    public function test_tampered_attachment_fails_validation(): void
    {
        $paket = $this->tamamlanmis();
        $paket->opc()->put('/Ekler/Calisma_Raporu.pdf', '%PDF-1.4 değiştirilmiş rapor');

        $rapor = Paket::icerikten($paket->icerik())->dogrula();

        $this->assertFalse($rapor->gecerli());
        $this->assertSame(['K.33', 'K.43'], array_map(fn (Bulgu $bulgu) => $bulgu->kural, $rapor->hatalar()));
    }

    public function test_unsigned_attachment_may_change_without_breaking_the_package(): void
    {
        $paket = $this->tamamlanmis();
        $paket->opc()->put('/ImzasizEkler/Makrolu_Tablo.xlsm', 'başka içerik');

        $this->assertTrue(Paket::icerikten($paket->icerik())->dogrula()->gecerli());
    }

    public function test_attachment_missing_from_the_package_fails_validation(): void
    {
        $paket = $this->tamamlanmis();
        $paket->opc()->remove('/Ekler/Calisma_Raporu.pdf');

        $this->assertStringContainsString('[K.23]', $this->hatalar(Paket::icerikten($paket->icerik())));
    }

    public function test_attachment_withheld_from_a_recipient_is_only_a_warning(): void
    {
        $paket = $this->olusturucu()
            ->dagitim(new KurumKurulus('24322011', 'Kültür ve Turizm Bakanlığı'), konulmamisEkIdleri: [self::EK_ID])
            ->olustur()
            ->imzala(new SahteImzalayici, $this->nihaiUstveri())
            ->muhurle(new SahteImzalayici);
        $paket->opc()->remove('/Ekler/Calisma_Raporu.pdf');

        $rapor = Paket::icerikten($paket->icerik())->dogrula();

        $this->assertTrue($rapor->gecerli());
        $this->assertSame(['K.23'], array_map(fn (Bulgu $bulgu) => $bulgu->kural, $rapor->uyarilar()));
    }

    public function test_signature_over_other_content_is_flagged(): void
    {
        $paket = $this->olusturucu()->olustur();
        $paket->imzaEkle("\x30\x82başka bir içerik", $this->nihaiUstveri())->muhurle(new SahteImzalayici);

        $rapor = $paket->dogrula();

        $this->assertTrue($rapor->gecerli());
        $this->assertSame(['K.81'], array_map(fn (Bulgu $bulgu) => $bulgu->kural, $rapor->uyarilar()));
    }

    public function test_core_must_match_the_metadata(): void
    {
        $paket = $this->tamamlanmis();
        $core = $paket->bilesenAdi(Ad::ILISKI_CORE);
        $paket->opc()->put($core, str_replace('Şenlik daveti', 'Başka konu', $paket->opc()->get($core)));

        $this->assertStringContainsString('[K.69]', $this->hatalar($paket));
    }

    public function test_steps_cannot_be_taken_out_of_order(): void
    {
        $paket = $this->olusturucu()->olustur();

        try {
            $paket->muhurEkle('muhur');
            $this->fail('İmzasız paket mühürlenebildi.');
        } catch (GecersizPaketException) {
        }

        $paket->imzala(new SahteImzalayici, $this->nihaiUstveri());

        try {
            $paket->imzala(new SahteImzalayici, $this->nihaiUstveri());
            $this->fail('İmzalı paket yeniden imzalanabildi.');
        } catch (GecersizPaketException) {
        }

        $paket->muhurle(new SahteImzalayici);

        $this->expectException(GecersizPaketException::class);

        $paket->muhurle(new SahteImzalayici);
    }

    public function test_missing_information_is_listed(): void
    {
        $this->expectException(EYazismaException::class);
        $this->expectExceptionMessage('konu, oluşturan, en az bir dağıtım, doğrulama adresi, üst yazı');

        Paket::yeni()->olustur();
    }

    public function test_digest_algorithms_must_include_sha512(): void
    {
        $paket = $this->olusturucu()->ozetAlgoritmalari(OzetAlgoritmasi::Sha512, OzetAlgoritmasi::Sha384)->olustur()->imzala(new SahteImzalayici, $this->nihaiUstveri());

        $this->assertSame(
            [OzetAlgoritmasi::Sha512->value, OzetAlgoritmasi::Sha384->value],
            array_map(fn ($kalem) => $kalem->algoritma, Okuyucu::ozet($paket->nihaiOzet(), 'NihaiOzet')['referanslar'][0]->ozetler)
        );

        $this->expectException(EYazismaException::class);

        Paket::yeni()->ozetAlgoritmalari(OzetAlgoritmasi::Sha256, OzetAlgoritmasi::Sha384);
    }

    public function test_attachments_with_the_same_name_get_distinct_parts(): void
    {
        $paket = $this->olusturucu()
            ->ek(Dosya::icerikten('ikinci', 'Çalışma Raporu.pdf'))
            ->olustur();

        $this->assertSame(
            ['Calisma_Raporu.pdf', 'Makrolu_Tablo.xlsm', null, null, 'Calisma_Raporu_2.pdf'],
            array_map(fn ($ek) => $ek->dosyaAdi, $paket->ustveri()->ekler)
        );
    }

    public function test_encrypted_and_update_packages_are_not_supported(): void
    {
        foreach ([Ad::ILISKI_SIFRELI_ICERIK => '/SifreliIcerik/X', Ad::ILISKI_ORIJINAL_PAKET => '/OrijinalPaket/OrijinalPaket.eyp'] as $tur => $hedef) {
            $opc = new Package;
            $opc->put($hedef, 'icerik', 'application/octet-stream');
            $opc->relate('', $tur, $hedef, 'Id1');

            try {
                Paket::icerikten($opc->write());
                $this->fail("Desteklenmeyen paket açıldı: {$tur}");
            } catch (DesteklenmeyenPaketException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_arbitrary_zip_is_not_a_package(): void
    {
        $opc = new Package;
        $opc->put('/word/document.xml', '<document/>');

        $this->expectException(GecersizPaketException::class);

        Paket::icerikten($opc->write());
    }

    private function olusturucu(): PaketOlusturucu
    {
        return Paket::yeni()
            ->konu('Şenlik daveti')
            ->olusturan(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği'))
            ->dagitim(new KurumKurulus('24301050', 'Adalet Bakanlığı'))
            ->dagitim(new GercekSahis(new Kisi('Ali', 'Kaya')), DagitimTuru::Bilgi)
            ->dogrulamaAdresi('https://ornek.org.tr/dogrula')
            ->ustYazi(Dosya::icerikten('%PDF-1.4 üst yazı', 'Üst Yazı.pdf'))
            ->ek(Dosya::icerikten('%PDF-1.4 rapor', 'Çalışma Raporu.pdf'), ad: 'Çalışma raporu', id: self::EK_ID)
            ->ek(Dosya::icerikten('makrolu tablo', 'Makrolu Tablo.xlsm'), ad: 'Tablo', imzasiz: true)
            ->fizikselEk('İki adet CD')
            ->hariciEk('https://ornek.org.tr/video.mp4', ad: 'Tanıtım filmi');
    }

    private function nihaiUstveri(): NihaiUstveri
    {
        return new NihaiUstveri(new DateTimeImmutable('2026-10-08T14:00:00+03:00'), '2026/41', [
            new Imza(new GercekSahis(new Kisi('Ayşe', 'Yılmaz'), gorev: 'Yönetim Kurulu Başkanı'), amac: 'Onay'),
        ]);
    }

    private function tamamlanmis(): Paket
    {
        return $this->olusturucu()->olustur()->imzala(new SahteImzalayici, $this->nihaiUstveri())->muhurle(new SahteImzalayici);
    }

    private function hatalar(Paket $paket): string
    {
        return implode("\n", array_map('strval', $paket->dogrula()->hatalar()));
    }
}
