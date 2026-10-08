<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\EkTuru;
use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use BahriCanli\EYazisma\Enums\Ivedilik;
use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use BahriCanli\EYazisma\Model\Dagitim;
use BahriCanli\EYazisma\Model\Ek;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\Heysk;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\Ilgi;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\Ozet;
use BahriCanli\EYazisma\Model\OzetReferansi;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\Sdp;
use BahriCanli\EYazisma\Model\SdpBilgisi;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Model\Ustveri;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Okuyucu;
use BahriCanli\EYazisma\Xml\Yazici;
use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function test_ustveri_survives_a_round_trip_with_every_field(): void
    {
        $iletisim = new IletisimBilgisi('0312 000 00 00', '0532 000 00 00', 'bilgi@ornek.org.tr', 'ornek@hs01.kep.tr', '0312 000 00 01', 'https://ornek.org.tr', 'Çankaya & Kızılay <1>', 'Ankara', 'Çankaya', 'Türkiye');
        $ekId = '48ECB045-06C5-43DA-BACE-50BA73A8D129';

        $ustveri = new Ustveri(
            belgeId: '4F9E2E9B-F4E6-4428-8F99-2E0A0D1D42D9',
            konu: 'Şenlik & "duyuru" <test>',
            mimeTuru: 'application/pdf',
            dosyaAdi: 'yazi.pdf',
            olusturan: TuzelSahis::mersis('0123456789012345', 'Örnek Derneği', $iletisim),
            dagitimlar: [
                new Dagitim(new KurumKurulus('24301050', 'Adalet Bakanlığı', $iletisim, '24301051'), DagitimTuru::Geregi, Ivedilik::Acele, 'P3D', [$ekId]),
                new Dagitim(new GercekSahis(new Kisi('Ayşe', 'Yılmaz', 'Gül', 'Dr.', 'Sayın'), '12345678902', 'Başkan', $iletisim), DagitimTuru::Bilgi, Ivedilik::Gunlu),
            ],
            dogrulamaAdresi: 'https://ornek.org.tr/dogrula',
            guvenlikKodu: GuvenlikKodu::HizmeteOzel,
            guvenlikKoduGecerlilikTarihi: new DateTimeImmutable('2030-01-02T03:04:05+03:00'),
            ozId: new Tanimlayici('8CEA7FF7-75F2-4CCF-B3C1-1B9B2054E8E9', 'GUID'),
            ekler: [
                new Ek($ekId, EkTuru::DahiliElektronikDosya, 1, 'Rapor', 'Açıklama', '2026/41', 'rapor.pdf', 'application/pdf', null, new Tanimlayici('17', 'portal'), true, null, false),
                new Ek('64B89C72-1A9B-45F0-B613-0C0D5EDAAE4E', EkTuru::HariciReferans, 2, 'Video', referans: 'https://ornek.org.tr/video.mp4', imzaliMi: false, ozet: Ozet::hesapla('video')),
                new Ek('12E0130E-E785-467E-90BA-95ED9C930C6E', EkTuru::FizikselNesne, 3, 'İki adet CD', imzaliMi: false),
            ],
            ilgiler: [
                new Ilgi('76C7FD89-40F5-4F0D-AB41-CDC215B39EB6', 'a', '2026/12', new DateTimeImmutable('2026-03-01T00:00:00+03:00'), 'İlgi yazı', 'Açıklama', $ekId, new Tanimlayici('9', 'portal'), true),
            ],
            dil: 'tur',
            ilgililer: [new GercekSahis(new Kisi('Ali', 'Kaya'))],
            sdpBilgisi: new SdpBilgisi(new Sdp('010.06.01', 'İç Genelgeler'), [new Sdp('010.07.01', 'Duyurular', 'Not')]),
            heyskler: [new Heysk(999999, 'Hizmet', 'Tanım')],
        );

        $xml = Yazici::ustveri($ustveri);

        $this->assertEquals($ustveri, Okuyucu::ustveri($xml));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }

    public function test_ustveri_elements_follow_the_schema_order(): void
    {
        $xml = Yazici::ustveri(new Ustveri(
            belgeId: '4F9E2E9B-F4E6-4428-8F99-2E0A0D1D42D9',
            konu: 'Konu',
            mimeTuru: 'application/pdf',
            dosyaAdi: 'yazi.pdf',
            olusturan: TuzelSahis::mersis('0123456789012345'),
            dagitimlar: [new Dagitim(new KurumKurulus('24301050'))],
            dogrulamaAdresi: 'https://ornek.org.tr/dogrula',
            dil: 'tur',
        ));

        $belge = new DOMDocument;
        $belge->loadXML($xml);
        $kok = $belge->documentElement;

        $this->assertSame('UstVeri', $kok->localName);
        $this->assertSame(Ad::USTVERI, $kok->namespaceURI);

        $adlar = [];

        foreach ($kok->childNodes as $cocuk) {
            $this->assertSame(Ad::TIPLER, $cocuk->namespaceURI);
            $adlar[] = $cocuk->localName;
        }

        $this->assertSame(['BelgeId', 'Konu', 'GuvenlikKodu', 'MimeTuru', 'DagitimListesi', 'Dil', 'Olusturan', 'DosyaAdi', 'DogrulamaBilgisi'], $adlar);
        $this->assertStringContainsString('<tipler:Id schemeID="MERSIS">0123456789012345</tipler:Id>', $xml);
    }

    public function test_nihai_ustveri_survives_a_round_trip(): void
    {
        $kisi = new GercekSahis(new Kisi('Ayşe', 'Yılmaz'), '12345678902', 'Başkan');
        $nihaiUstveri = new NihaiUstveri(new DateTimeImmutable('2026-10-08T14:00:00+03:00'), '2026/41', [
            new Imza($kisi, 'Başkanlık', 'Onay', 'Açıklama', new DateTimeImmutable('2026-10-08T13:59:00+03:00'), $kisi, $kisi),
            new Imza(new GercekSahis(new Kisi('Ali', 'Kaya'))),
        ]);

        $this->assertEquals($nihaiUstveri, Okuyucu::nihaiUstveri(Yazici::nihaiUstveri($nihaiUstveri)));
    }

    public function test_digest_components_survive_a_round_trip(): void
    {
        $referanslar = [
            new OzetReferansi('/Ustveri/Ustveri.xml', [Ozet::hesapla('a'), new Ozet('http://www.w3.org/2001/04/xmlenc#sha512', 'AAAA')]),
            new OzetReferansi('urn:harici:1', [Ozet::hesapla('b')], OzetReferansi::HARICI),
        ];

        foreach (['PaketOzeti' => Ad::PAKET_OZETI, 'NihaiOzet' => Ad::NIHAI_OZET, 'ParafOzeti' => Ad::PARAF_OZETI] as $kok => $adAlani) {
            $xml = Yazici::ozet($kok, 'BCF37F50-CD6E-408D-B24F-CFB7838603D8', $referanslar);
            $okunan = Okuyucu::ozet($xml, $kok);

            $this->assertSame('BCF37F50-CD6E-408D-B24F-CFB7838603D8', $okunan['id']);
            $this->assertEquals($referanslar, $okunan['referanslar']);

            $belge = new DOMDocument;
            $belge->loadXML($xml);
            $this->assertSame($adAlani, $belge->documentElement->namespaceURI);
            $this->assertSame(Ad::PAKET_OZETI, $belge->documentElement->firstChild->namespaceURI);
        }
    }

    public function test_core_properties_survive_a_round_trip(): void
    {
        $ozellikler = new PaketOzellikleri(
            'BCF37F50-CD6E-408D-B24F-CFB7838603D8',
            'Konu & başlık',
            'Örnek Derneği/0123456789012345',
            new DateTimeImmutable('2026-10-08T11:00:00+00:00'),
            PaketOzellikleri::KATEGORI_RESMI_YAZISMA,
            PaketOzellikleri::ICERIK_TURU,
            '2.0',
            'PHP/test',
        );

        $xml = Yazici::core($ozellikler);

        $this->assertEquals($ozellikler, Okuyucu::core($xml));
        $this->assertStringContainsString('<dcterms:created xsi:type="dcterms:W3CDTF">2026-10-08T11:00:00Z</dcterms:created>', $xml);
    }

    public function test_component_with_a_doctype_is_rejected(): void
    {
        $this->expectException(GecersizPaketException::class);

        Okuyucu::ustveri('<?xml version="1.0"?><!DOCTYPE UstVeri [<!ENTITY x "y">]><UstVeri xmlns="'.Ad::USTVERI.'"/>');
    }

    public function test_component_in_another_schema_is_rejected(): void
    {
        $this->expectException(GecersizPaketException::class);

        Okuyucu::ustveri('<UstVeri xmlns="urn:dpt:eyazisma:schema:xsd:Ustveri-1"/>');
    }
}
