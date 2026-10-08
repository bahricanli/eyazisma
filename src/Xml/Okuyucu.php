<?php

namespace BahriCanli\EYazisma\Xml;

use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\EkTuru;
use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use BahriCanli\EYazisma\Enums\Ivedilik;
use BahriCanli\EYazisma\Enums\Surum;
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
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Model\Ustveri;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Exception;

/**
 * Paket bileşenlerinin XML'ini modele çevirir. Elemanlar yerel adlarıyla aranır.
 */
final class Okuyucu
{
    /**
     * Rehber bileşenlerde DTD tanımına izin vermez (ISO/IEC 29500-2 M1.18); DTD'li belge reddedilir.
     */
    public static function belge(string $xml, string $bilesen): DOMDocument
    {
        $belge = new DOMDocument;
        $onceki = libxml_use_internal_errors(true);
        $yuklendi = $belge->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        if (! $yuklendi || $belge->documentElement === null) {
            throw new GecersizPaketException("{$bilesen} bileşeni geçerli bir XML değil.");
        }

        if ($belge->doctype !== null) {
            throw new GecersizPaketException("{$bilesen} bileşeni DTD tanımı içeriyor.");
        }

        return $belge;
    }

    /**
     * Üstveri bileşeninin kuşağı; tanınmayan şemada null.
     */
    public static function surum(string $ustveriXml): ?Surum
    {
        return match (self::belge($ustveriXml, 'Üstveri')->documentElement->namespaceURI) {
            Ad::USTVERI => Surum::V2,
            Ad::USTVERI_1 => Surum::V1,
            default => null,
        };
    }

    public static function ustveri(string $xml): Ustveri
    {
        $kok = self::surum($xml) === Surum::V1
            ? self::kok($xml, 'Üstveri', 'Ustveri', Ad::USTVERI_1)
            : self::kok($xml, 'Üstveri', 'UstVeri', Ad::USTVERI);
        $sdpBilgisi = self::cocuk($kok, 'SdpBilgisi');

        return new Ustveri(
            belgeId: self::zorunlu($kok, 'BelgeId'),
            konu: self::zorunlu($kok, 'Konu'),
            mimeTuru: self::metin($kok, 'MimeTuru') ?? '',
            dosyaAdi: self::metin($kok, 'DosyaAdi') ?? '',
            olusturan: self::taraf(self::zorunluCocuk($kok, 'Olusturan')),
            dagitimlar: array_map(self::dagitim(...), self::cocuklar(self::cocuk($kok, 'DagitimListesi'), 'Dagitim')),
            dogrulamaAdresi: self::metin(self::cocuk($kok, 'DogrulamaBilgisi'), 'DogrulamaAdresi') ?? '',
            guvenlikKodu: self::kod(GuvenlikKodu::class, self::zorunlu($kok, 'GuvenlikKodu')),
            guvenlikKoduGecerlilikTarihi: self::tarih($kok, 'GuvenlikKoduGecerlilikTarihi'),
            ozId: self::tanimlayici($kok, 'OzId'),
            ekler: array_map(self::ek(...), self::cocuklar(self::cocuk($kok, 'Ekler'), 'Ek')),
            ilgiler: array_map(self::ilgi(...), self::cocuklar(self::cocuk($kok, 'Ilgiler'), 'Ilgi')),
            dil: self::metin($kok, 'Dil'),
            ilgililer: array_map(self::taraf(...), self::cocuklar(self::cocuk($kok, 'IlgiliListesi'), 'Ilgili')),
            sdpBilgisi: $sdpBilgisi === null ? null : new SdpBilgisi(
                self::sdp(self::zorunluCocuk($sdpBilgisi, 'AnaSdp')),
                array_map(self::sdp(...), self::cocuklar(self::cocuk($sdpBilgisi, 'DigerSdpler'), 'SdpListesi')),
            ),
            heyskler: array_map(
                fn (DOMElement $heysk) => new Heysk((int) self::zorunlu($heysk, 'Kod'), self::zorunlu($heysk, 'Ad'), self::metin($heysk, 'Tanim')),
                self::cocuklar(self::cocuk($kok, 'HeyskListesi'), 'Heysk')
            ),
        );
    }

    /**
     * 1.x'te nihai üstverinin karşılığı: tarih ve sayı üstveriden, imza bilgileri "Belge İmza" bileşeninden gelir.
     */
    public static function eskiNihaiUstveri(string $ustveriXml, ?string $belgeImzaXml): NihaiUstveri
    {
        $kok = self::kok($ustveriXml, 'Üstveri', 'Ustveri', Ad::USTVERI_1);
        $imzalar = $belgeImzaXml === null ? null : self::cocuk(self::belge($belgeImzaXml, 'Belge İmza')->documentElement, 'ImzaListesi');

        return new NihaiUstveri(
            tarih: self::tarih($kok, 'Tarih') ?? throw new GecersizPaketException('Üstveri bileşeninde Tarih yok.'),
            belgeNo: self::zorunlu($kok, 'BelgeNo'),
            imzalar: array_map(self::imza(...), self::cocuklar($imzalar, 'Imza')),
        );
    }

    /**
     * "Belge Hedef" bileşeni: paketin elektronik olarak iletileceği alıcılar.
     *
     * @return list<Taraf>
     */
    public static function belgeHedef(string $xml): array
    {
        $liste = self::cocuk(self::belge($xml, 'Belge Hedef')->documentElement, 'HedefListesi');

        return array_map(self::taraf(...), self::cocuklar($liste, 'Hedef'));
    }

    public static function nihaiUstveri(string $xml): NihaiUstveri
    {
        $kok = self::kok($xml, 'Nihai Üstveri', 'NihaiUstVeri', Ad::NIHAI_USTVERI);

        return new NihaiUstveri(
            tarih: self::tarih($kok, 'Tarih') ?? throw new GecersizPaketException('Nihai Üstveri bileşeninde Tarih yok.'),
            belgeNo: self::zorunlu($kok, 'BelgeNo'),
            imzalar: array_map(self::imza(...), self::cocuklar(self::cocuk($kok, 'BelgeImzalar'), 'Imza')),
        );
    }

    /**
     * @param  string  $kok  "PaketOzeti", "NihaiOzet" veya "ParafOzeti"
     * @return array{id: string, referanslar: list<OzetReferansi>}
     */
    public static function ozet(string $xml, string $kok): array
    {
        $eleman = self::belge($xml, $kok)->documentElement;

        if ($eleman->localName !== $kok || ! in_array($eleman->namespaceURI, [Ad::ozet($kok), Ad::eskiOzet($kok)], true)) {
            throw new GecersizPaketException("{$kok} bileşeni tanınan bir şemaya uymuyor.");
        }

        $referanslar = [];

        foreach (self::cocuklar($eleman, 'Reference') as $referans) {
            $referanslar[] = new OzetReferansi(
                $referans->getAttribute('URI'),
                array_map(
                    fn (DOMElement $kalem) => new Ozet(
                        self::cocuk($kalem, 'DigestMethod')?->getAttribute('Algorithm') ?? '',
                        preg_replace('/\s+/', '', self::metin($kalem, 'DigestValue') ?? ''),
                    ),
                    // 1.x'te özet doğrudan referansın altındadır.
                    self::cocuklar($referans, 'DigestItem') ?: [$referans]
                ),
                $referans->getAttribute('Type') ?: OzetReferansi::DAHILI,
            );
        }

        return ['id' => $eleman->getAttribute('Id'), 'referanslar' => $referanslar];
    }

    public static function core(string $xml): PaketOzellikleri
    {
        $kok = self::belge($xml, 'Core')->documentElement;
        $olusturulma = self::metin($kok, 'created');

        try {
            $olusturulma = $olusturulma === null ? null : new DateTimeImmutable($olusturulma);
        } catch (Exception) {
            $olusturulma = null;
        }

        return new PaketOzellikleri(
            tanimlayici: self::metin($kok, 'identifier'),
            konu: self::metin($kok, 'subject'),
            olusturan: self::metin($kok, 'creator'),
            olusturulma: $olusturulma,
            kategori: self::metin($kok, 'category'),
            icerikTuru: self::metin($kok, 'contentType'),
            surum: self::metin($kok, 'version'),
            revizyon: self::metin($kok, 'revision'),
        );
    }

    private static function kok(string $xml, string $bilesen, string $ad, string $adAlani): DOMElement
    {
        $kok = self::belge($xml, $bilesen)->documentElement;

        if ($kok->localName !== $ad || $kok->namespaceURI !== $adAlani) {
            throw new GecersizPaketException("{$bilesen} bileşeni {$adAlani} şemasına uymuyor.");
        }

        return $kok;
    }

    /**
     * @return list<DOMElement>
     */
    private static function cocuklar(?DOMElement $ust, string $ad): array
    {
        $cocuklar = [];

        foreach ($ust?->childNodes ?? [] as $dugum) {
            if ($dugum instanceof DOMElement && $dugum->localName === $ad) {
                $cocuklar[] = $dugum;
            }
        }

        return $cocuklar;
    }

    private static function cocuk(?DOMElement $ust, string $ad): ?DOMElement
    {
        return self::cocuklar($ust, $ad)[0] ?? null;
    }

    private static function zorunluCocuk(DOMElement $ust, string $ad): DOMElement
    {
        return self::cocuk($ust, $ad) ?? throw new GecersizPaketException("{$ust->localName} içinde {$ad} elemanı yok.");
    }

    /** Elemanın metni; eleman yoksa ya da boşsa null (eski araçlar boş elemanlar yazar). */
    private static function metin(?DOMElement $ust, string $ad): ?string
    {
        $metin = trim(self::cocuk($ust, $ad)?->textContent ?? '');

        return $metin === '' ? null : $metin;
    }

    private static function zorunlu(DOMElement $ust, string $ad): string
    {
        return trim(self::zorunluCocuk($ust, $ad)->textContent);
    }

    private static function tarih(DOMElement $ust, string $ad): ?DateTimeImmutable
    {
        $deger = self::metin($ust, $ad);

        if ($deger === null || $deger === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($deger);
        } catch (Exception) {
            throw new GecersizPaketException("{$ad} geçerli bir tarih değil: {$deger}");
        }
    }

    private static function mantiksal(?string $deger): ?bool
    {
        return match ($deger === null ? null : strtolower($deger)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private static function kod(string $enum, string $deger): \BackedEnum
    {
        return $enum::tryFrom($deger) ?? throw new GecersizPaketException("Bilinmeyen kod: {$deger}");
    }

    private static function tanimlayici(?DOMElement $ust, string $ad): ?Tanimlayici
    {
        $eleman = self::cocuk($ust, $ad);

        if ($eleman === null) {
            return null;
        }

        return new Tanimlayici(trim($eleman->textContent), $eleman->hasAttribute('schemeID') ? $eleman->getAttribute('schemeID') : null);
    }

    private static function taraf(DOMElement $ust): Taraf
    {
        if ($kurum = self::cocuk($ust, 'KurumKurulus')) {
            return new KurumKurulus(
                self::zorunlu($kurum, 'KKK'),
                self::metin($kurum, 'Adi'),
                self::iletisim($kurum),
                self::metin($kurum, 'BirimKKK'),
            );
        }

        if ($tuzel = self::cocuk($ust, 'TuzelSahis')) {
            return new TuzelSahis(
                self::tanimlayici($tuzel, 'Id') ?? throw new GecersizPaketException('TuzelSahis içinde Id elemanı yok.'),
                self::metin($tuzel, 'Adi'),
                self::iletisim($tuzel),
            );
        }

        if ($gercek = self::cocuk($ust, 'GercekSahis')) {
            return self::gercekSahis($gercek);
        }

        throw new GecersizPaketException("{$ust->localName} içinde kurum, gerçek kişi ya da tüzel kişi bilgisi yok.");
    }

    private static function gercekSahis(DOMElement $eleman): GercekSahis
    {
        $kisi = self::zorunluCocuk($eleman, 'Kisi');

        return new GercekSahis(
            new Kisi(
                self::zorunlu($kisi, 'IlkAdi'),
                self::zorunlu($kisi, 'Soyadi'),
                self::metin($kisi, 'IkinciAdi'),
                self::metin($kisi, 'Unvan'),
                self::metin($kisi, 'OnEk'),
            ),
            self::metin($eleman, 'TCKN'),
            self::metin($eleman, 'Gorev'),
            self::iletisim($eleman),
        );
    }

    private static function iletisim(DOMElement $ust): ?IletisimBilgisi
    {
        $eleman = self::cocuk($ust, 'IletisimBilgisi');

        if ($eleman === null) {
            return null;
        }

        $bilgi = new IletisimBilgisi(
            self::metin($eleman, 'Telefon'),
            self::metin($eleman, 'TelefonDiger'),
            self::metin($eleman, 'EPosta'),
            self::metin($eleman, 'KepAdresi'),
            self::metin($eleman, 'Faks'),
            self::metin($eleman, 'WebAdresi'),
            self::metin($eleman, 'Adres'),
            self::metin($eleman, 'Il'),
            self::metin($eleman, 'Ilce'),
            self::metin($eleman, 'Ulke'),
        );

        return array_filter(get_object_vars($bilgi), fn ($deger) => $deger !== null) === [] ? null : $bilgi;
    }

    private static function dagitim(DOMElement $eleman): Dagitim
    {
        return new Dagitim(
            self::taraf($eleman),
            self::kod(DagitimTuru::class, self::zorunlu($eleman, 'DagitimTuru')),
            self::kod(Ivedilik::class, self::zorunlu($eleman, 'Ivedilik')),
            self::metin($eleman, 'Miat'),
            array_map(
                fn (DOMElement $konulmamis) => self::zorunlu($konulmamis, 'EkId'),
                self::cocuklar(self::cocuk($eleman, 'KonulmamisEkListesi'), 'KonulmamisEk')
            ),
        );
    }

    private static function ek(DOMElement $eleman): Ek
    {
        $id = self::zorunluCocuk($eleman, 'Id');
        $ozet = self::cocuk($eleman, 'Ozet');

        return new Ek(
            id: $id->getAttribute('Value'),
            tur: self::kod(EkTuru::class, self::zorunlu($eleman, 'Tur')),
            siraNo: (int) self::zorunlu($eleman, 'SiraNo'),
            ad: self::metin($eleman, 'Ad'),
            aciklama: self::metin($eleman, 'Aciklama'),
            belgeNo: self::metin($eleman, 'BelgeNo'),
            dosyaAdi: self::metin($eleman, 'DosyaAdi'),
            mimeTuru: self::metin($eleman, 'MimeTuru'),
            referans: self::metin($eleman, 'Referans'),
            ozId: self::tanimlayici($eleman, 'OzId'),
            imzaliMi: self::mantiksal(self::metin($eleman, 'ImzaliMi')),
            ozet: $ozet === null ? null : new Ozet(
                self::cocuk($ozet, 'OzetAlgoritmasi')?->getAttribute('Algorithm') ?? '',
                self::metin($ozet, 'OzetDegeri') ?? '',
            ),
            eyazismaIdMi: $id->hasAttribute('EYazismaIdMi') ? self::mantiksal($id->getAttribute('EYazismaIdMi')) : null,
        );
    }

    private static function ilgi(DOMElement $eleman): Ilgi
    {
        $id = self::zorunluCocuk($eleman, 'Id');

        return new Ilgi(
            id: $id->getAttribute('Value'),
            etiket: self::zorunlu($eleman, 'Etiket'),
            belgeNo: self::metin($eleman, 'BelgeNo'),
            tarih: self::tarih($eleman, 'Tarih'),
            ad: self::metin($eleman, 'Ad'),
            aciklama: self::metin($eleman, 'Aciklama'),
            ekId: self::metin($eleman, 'EkId'),
            ozId: self::tanimlayici($eleman, 'OzId'),
            eyazismaIdMi: $id->hasAttribute('EYazismaIdMi') ? self::mantiksal($id->getAttribute('EYazismaIdMi')) : null,
        );
    }

    private static function imza(DOMElement $eleman): Imza
    {
        $yetkiDevreden = self::cocuk($eleman, 'YetkiDevreden');
        $vekaletVeren = self::cocuk($eleman, 'VekaletVeren');

        return new Imza(
            imzalayan: self::gercekSahis(self::zorunluCocuk($eleman, 'Imzalayan')),
            makam: self::metin($eleman, 'Makam'),
            amac: self::metin($eleman, 'Amac'),
            aciklama: self::metin($eleman, 'Aciklama'),
            tarih: self::tarih($eleman, 'Tarih'),
            yetkiDevreden: $yetkiDevreden === null ? null : self::gercekSahis($yetkiDevreden),
            vekaletVeren: $vekaletVeren === null ? null : self::gercekSahis($vekaletVeren),
        );
    }

    private static function sdp(DOMElement $eleman): Sdp
    {
        return new Sdp(self::zorunlu($eleman, 'Kod'), self::zorunlu($eleman, 'Ad'), self::metin($eleman, 'Aciklama'));
    }
}
