<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Dogrulama\Bulgu;
use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use BahriCanli\EYazisma\Enums\Ivedilik;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Opc\Package;
use BahriCanli\EYazisma\Paket;
use BahriCanli\EYazisma\PaketOlusturucu;
use BahriCanli\EYazisma\Tests\SahteImzalayici;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Okuyucu;
use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 öncesi (1.x) paketler: hâlâ gönderilip alınıyor, mühürsüz ve tek özetli.
 */
class EskiPaketTest extends TestCase
{
    public function test_old_generation_package_is_built_in_the_old_layout(): void
    {
        $paket = $this->olusturucu()->olustur();

        $this->assertSame(Surum::V1, $paket->surum());
        $this->assertSame(PaketAsamasi::ImzaBekliyor, $paket->asama());
        $this->assertSame('1.3', $paket->ozellikler()->surum);

        $ustveri = $this->belge($paket->opc()->get(Ad::PARCA_USTVERI));
        $adlar = [];

        foreach ($ustveri->documentElement->childNodes as $cocuk) {
            $this->assertSame('urn:dpt:eyazisma:schema:xsd:Tipler-1', $cocuk->namespaceURI);
            $adlar[] = $cocuk->localName;
        }

        $this->assertSame('Ustveri', $ustveri->documentElement->localName);
        $this->assertSame(Ad::USTVERI_1, $ustveri->documentElement->namespaceURI);
        $this->assertSame(['BelgeId', 'Konu', 'Tarih', 'BelgeNo', 'GuvenlikKodu', 'MimeTuru', 'DagitimListesi', 'Ekler', 'Dil', 'Olusturan', 'DosyaAdi'], $adlar);

        // Codes and fields that came with 2.0 are written the old way or left out.
        $xml = $paket->opc()->get(Ad::PARCA_USTVERI);
        $this->assertStringContainsString('<tipler:GuvenlikKodu>TSD</tipler:GuvenlikKodu>', $xml);
        $this->assertStringContainsString('<tipler:Ivedilik>IVD</tipler:Ivedilik>', $xml);
        $this->assertStringContainsString('<tipler:BelgeNo>06-061-115-2026-22</tipler:BelgeNo>', $xml);
        $this->assertStringNotContainsString('KepAdresi', $xml);
        $this->assertStringNotContainsString('BirimKKK', $xml);
        $this->assertStringNotContainsString('DogrulamaBilgisi', $xml);

        // One digest for each component, directly under the reference.
        $ozet = $this->belge($paket->paketOzeti());
        $this->assertSame(Ad::PAKET_OZETI_1, $ozet->documentElement->namespaceURI);
        $this->assertSame(0, $ozet->getElementsByTagName('DigestItem')->length);
        $this->assertSame(4, $ozet->getElementsByTagName('DigestValue')->length);
        $this->assertSame(
            ['/Ustveri/Ustveri.xml', '/UstYazi/yazi.pdf', '/BelgeHedef/BelgeHedef.xml', '/Ekler/rapor.pdf'],
            array_map(fn ($referans) => $referans->uri, Okuyucu::ozet($paket->paketOzeti(), 'PaketOzeti')['referanslar'])
        );

        $this->assertSame(['Adalet Bakanlığı', 'Ali Kaya'], array_map(fn ($hedef) => $hedef->gorunenAd(), $paket->hedefler()));
    }

    public function test_old_generation_package_is_complete_with_the_signature(): void
    {
        $paket = $this->olusturucu()->olustur()->imzala(new SahteImzalayici, $this->nihaiUstveri());

        $this->assertSame(PaketAsamasi::Tamamlandi, $paket->asama());
        $this->assertNull($paket->muhur());

        $okunan = Paket::icerikten($paket->icerik());
        $rapor = $okunan->dogrula();

        $this->assertSame([], array_map('strval', $rapor->bulgular));
        $this->assertSame(Surum::V1, $okunan->surum());
        $this->assertSame(PaketAsamasi::Tamamlandi, $okunan->asama());

        $ustveri = $okunan->ustveri();
        $this->assertSame('Şenlik daveti', $ustveri->konu);
        $this->assertSame(GuvenlikKodu::TasnifDisi, $ustveri->guvenlikKodu);
        $this->assertSame(Ivedilik::Ivedi, $ustveri->dagitimlar[0]->ivedilik);
        $this->assertSame(DagitimTuru::Bilgi, $ustveri->dagitimlar[1]->dagitimTuru);
        $this->assertEquals(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği', new IletisimBilgisi(ePosta: 'bilgi@ornek.org.tr')), $ustveri->olusturan);
        $this->assertSame('', $ustveri->dogrulamaAdresi);

        // Date, number and signers are read through the same call as in 2.x.
        $nihai = $okunan->nihaiUstveri();
        $this->assertSame('06-061-115-2026-22', $nihai->belgeNo);
        $this->assertSame('2026-10-08', $nihai->tarih->format('Y-m-d'));
        $this->assertSame('Ayşe Yılmaz', $nihai->imzalar[0]->imzalayan->gorunenAd());

        $this->assertSame('%PDF-1.4 üst yazı', $okunan->ustYazi()->icerik);
        $this->assertSame('%PDF-1.4 rapor', array_values($okunan->ekDosyalari())[0]->icerik);

        $nihaiOzet = Okuyucu::ozet($okunan->nihaiOzet(), 'NihaiOzet');
        $this->assertContains('/Imzalar/BelgeImza.xml', array_map(fn ($referans) => $referans->uri, $nihaiOzet['referanslar']));
        $this->assertSame([OzetAlgoritmasi::Sha256->value], array_map(fn ($kalem) => $kalem->algoritma, $nihaiOzet['referanslar'][0]->ozetler));

        // A seal may still be added.
        $okunan->muhurle(new SahteImzalayici);
        $this->assertTrue(Paket::icerikten($okunan->icerik())->dogrula()->gecerli());
    }

    public function test_old_generation_package_needs_its_date_and_number_up_front(): void
    {
        try {
            $this->olusturucu(belgeli: false)->olustur();
            $this->fail('Tarih ve sayı olmadan 1.x paket oluştu.');
        } catch (EYazismaException $hata) {
            $this->assertStringContainsString('belgenin tarihi ve sayısı', $hata->getMessage());
        }

        // The number is part of what was signed.
        $this->expectException(GecersizPaketException::class);

        $this->olusturucu()->olustur()->imzala(new SahteImzalayici, new NihaiUstveri(new DateTimeImmutable, 'başka-sayı', $this->nihaiUstveri()->imzalar));
    }

    public function test_tampered_old_generation_package_fails_validation(): void
    {
        $paket = $this->olusturucu()->olustur()->imzala(new SahteImzalayici, $this->nihaiUstveri());
        $paket->opc()->put('/UstYazi/yazi.pdf', '%PDF-1.4 değiştirilmiş');

        $rapor = Paket::icerikten($paket->icerik())->dogrula();

        $this->assertFalse($rapor->gecerli());
        $this->assertSame(['K.33', 'K.43'], array_map(fn (Bulgu $bulgu) => $bulgu->kural, $rapor->hatalar()));

        $imzasiz = $this->olusturucu()->olustur();
        $this->assertStringContainsString('imzalanmamış', implode("\n", array_map('strval', $imzasiz->dogrula()->hatalar())));
    }

    /**
     * Packages written by older tools: other prefixes, empty elements, a nil date, no final digest.
     */
    public function test_package_written_by_an_older_tool_is_read(): void
    {
        $id = '1CC59C81-513D-46CF-B145-407ED4758EDA';
        $yazi = '%PDF-1.4 eski yazı';
        $ustveri = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><ns2:Ustveri xmlns="urn:dpt:eyazisma:schema:xsd:Tipler-1" xmlns:ns2="urn:dpt:eyazisma:schema:xsd:Ustveri-1">'
            .'<BelgeId>'.$id.'</BelgeId><Konu>Kış Kampı hk.</Konu><Tarih>2024-01-07T00:00:00.000+03:00</Tarih><BelgeNo>06-061-115-2024-4</BelgeNo><GuvenlikKodu>TSD</GuvenlikKodu>'
            .'<GuvenlikKoduGecerlilikTarihi xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/><MimeTuru>application/pdf</MimeTuru><OzId schemeID="GUID">29DED672-15FB-4E78-92C1-5970D8E34FFC</OzId>'
            .'<DagitimListesi><Dagitim><KurumKurulus><KKK>25609554</KKK><BYK/><Adi>Eskişehir Valiliği</Adi><IletisimBilgisi><Telefon/><EPosta/><Adres/></IletisimBilgisi></KurumKurulus><Ivedilik>NRM</Ivedilik><DagitimTuru>GRG</DagitimTuru></Dagitim></DagitimListesi>'
            .'<Dil>tur</Dil><Olusturan><GercekSahis><Kisi><IlkAdi>Ayşe</IlkAdi><Soyadi>Yılmaz</Soyadi></Kisi><TCKN>12345678902</TCKN></GercekSahis></Olusturan><DosyaAdi>yazi.pdf</DosyaAdi></ns2:Ustveri>';
        $ozet = fn (string $icerik) => '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/><DigestValue>'.base64_encode(sha1($icerik, true)).'</DigestValue>';
        $paketOzeti = '<?xml version="1.0"?><PaketOzeti xmlns="urn:dpt:eyazisma:schema:xsd:PaketOzeti-1" Id="'.$id.'">'
            .'<Reference URI="/UstYazi/yazi.pdf" Type="http://eyazisma.dpt/bilesen#dahili">'.$ozet($yazi).'</Reference>'
            .'<Reference URI="/Ustveri/Ustveri.xml" Type="http://eyazisma.dpt/bilesen#dahili">'.$ozet($ustveri).'</Reference></PaketOzeti>';
        $belgeImza = '<ns2:BelgeImza xmlns="urn:dpt:eyazisma:schema:xsd:Tipler-1" xmlns:ns2="urn:dpt:eyazisma:schema:xsd:BelgeImza-1"><ImzaListesi><Imza><Imzalayan><Kisi><IlkAdi>Ayşe</IlkAdi><Soyadi>Yılmaz</Soyadi></Kisi><TCKN>12345678902</TCKN></Imzalayan></Imza></ImzaListesi></ns2:BelgeImza>';
        $core = '<coreProperties xmlns="'.Ad::CORE.'" xmlns:dc="'.Ad::DC.'" xmlns:dcterms="'.Ad::DCTERMS.'" xmlns:xsi="'.Ad::XSI.'"><category>RESMIYAZISMA</category><contentStatus>Son</contentStatus><contentType>application/eyazisma</contentType>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">2024-01-07T14:59:08Z</dcterms:created><dc:creator>Ayşe Yılmaz</dc:creator><dc:identifier>'.$id.'</dc:identifier><revision>Java/1.0.0.1</revision><dc:subject>Kış Kampı hk.</dc:subject><version>1.0</version></coreProperties>';

        $opc = new Package;
        $opc->put('/UstYazi/yazi.pdf', $yazi, 'application/pdf');
        $opc->put('/Ustveri/Ustveri.xml', $ustveri);
        $opc->put('/PaketOzeti/PaketOzeti.xml', $paketOzeti);
        $opc->put('/Imzalar/BelgeImza.xml', $belgeImza);
        $opc->put('/Imzalar/ImzaCades.imz', (new SahteImzalayici)->imzala($paketOzeti), 'application/octet-stream');
        $opc->put('/docProps/core.xml', $core, Ad::TUR_CORE);
        // Old tools write relative targets.
        $opc->relate('', Ad::ILISKI_USTYAZI, 'UstYazi/yazi.pdf', 'IdUstYazi');
        $opc->relate('', Ad::ILISKI_USTVERI, 'Ustveri/Ustveri.xml', 'IdUstveri');
        $opc->relate('', Ad::ILISKI_PAKET_OZETI, 'PaketOzeti/PaketOzeti.xml', 'IdPaketOzeti');
        $opc->relate('', Ad::ILISKI_BELGE_IMZA, 'Imzalar/BelgeImza.xml', 'IdBelgeImza');
        $opc->relate('', Ad::ILISKI_CORE, 'docProps/core.xml', 'rId1');
        $opc->relate('/PaketOzeti/PaketOzeti.xml', Ad::ILISKI_IMZA, '../Imzalar/ImzaCades.imz', 'IdImzaCades');

        $paket = Paket::icerikten($opc->write());

        $this->assertSame(Surum::V1, $paket->surum());
        $this->assertSame(PaketAsamasi::Tamamlandi, $paket->asama());
        $this->assertNull($paket->nihaiOzet());
        $this->assertSame([], $paket->hedefler());

        $okunan = $paket->ustveri();
        $this->assertNull($okunan->guvenlikKoduGecerlilikTarihi);
        $this->assertEquals(new KurumKurulus('25609554', 'Eskişehir Valiliği'), $okunan->dagitimlar[0]->taraf);
        $this->assertInstanceOf(GercekSahis::class, $okunan->olusturan);
        $this->assertSame('06-061-115-2024-4', $paket->nihaiUstveri()->belgeNo);
        $this->assertSame('1.0', $paket->ozellikler()->surum);

        $this->assertSame([], array_map('strval', $paket->dogrula()->bulgular));
    }

    private function olusturucu(bool $belgeli = true): PaketOlusturucu
    {
        $olusturucu = Paket::yeni()
            ->surum('1.3')
            ->konu('Şenlik daveti')
            ->olusturan(TuzelSahis::mersis('0123456789012345', 'Örnek Derneği', new IletisimBilgisi(ePosta: 'bilgi@ornek.org.tr', kepAdresi: 'ornek@hs01.kep.tr')))
            ->dagitim(new KurumKurulus('24301050', 'Adalet Bakanlığı', birimKkk: '24301051'), ivedilik: Ivedilik::Acele)
            ->dagitim(new GercekSahis(new Kisi('Ali', 'Kaya')), DagitimTuru::Bilgi)
            ->ustYazi(Dosya::icerikten('%PDF-1.4 üst yazı', 'yazi.pdf'))
            ->ek(Dosya::icerikten('%PDF-1.4 rapor', 'rapor.pdf'), ad: 'Rapor');

        return $belgeli ? $olusturucu->belge(new DateTimeImmutable('2026-10-08T00:00:00+03:00'), '06-061-115-2026-22') : $olusturucu;
    }

    private function nihaiUstveri(): NihaiUstveri
    {
        return new NihaiUstveri(new DateTimeImmutable('2026-10-08T00:00:00+03:00'), '06-061-115-2026-22', [
            new Imza(new GercekSahis(new Kisi('Ayşe', 'Yılmaz'), '12345678902', 'Yönetim Kurulu Başkanı')),
        ]);
    }

    private function belge(string $xml): DOMDocument
    {
        $belge = new DOMDocument;
        $belge->loadXML($xml);

        return $belge;
    }
}
