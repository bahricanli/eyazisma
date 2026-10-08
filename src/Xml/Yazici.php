<?php

namespace BahriCanli\EYazisma\Xml;

use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use BahriCanli\EYazisma\Enums\Ivedilik;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Model\Dagitim;
use BahriCanli\EYazisma\Model\Ek;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\Ilgi;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\OzetReferansi;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\Sdp;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Model\Ustveri;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Paket bileşenlerini şemalarındaki eleman sırasıyla XML'e çevirir.
 */
final class Yazici
{
    /** Yazılmakta olan bileşenin kuşağı; ad alanlarını ve kuşağa özgü alanları belirler. */
    private static Surum $surum = Surum::V2;

    /**
     * @param  NihaiUstveri|null  $belgeBilgisi  1.x'te tarih ve sayı üstveride yer alır; o kuşak için zorunludur
     */
    public static function ustveri(Ustveri $ustveri, Surum $surum = Surum::V2, ?NihaiUstveri $belgeBilgisi = null): string
    {
        self::$surum = $surum;
        $eski = $surum === Surum::V1;

        if ($eski && $belgeBilgisi === null) {
            throw new EYazismaException('1.x üstverisi için belgenin tarihi ve sayısı gerekir.');
        }

        $belge = self::belge();
        $kok = $belge->appendChild($eski ? $belge->createElementNS(Ad::USTVERI_1, 'Ustveri') : $belge->createElementNS(Ad::USTVERI, 'UstVeri'));
        $kok->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tipler', self::tipler());

        self::metin($kok, 'BelgeId', $ustveri->belgeId);
        self::metin($kok, 'Konu', $ustveri->konu);

        if ($eski) {
            self::metin($kok, 'Tarih', self::tarih($belgeBilgisi->tarih));
            self::metin($kok, 'BelgeNo', $belgeBilgisi->belgeNo);
        }

        self::metin($kok, 'GuvenlikKodu', self::guvenlikKodu($ustveri->guvenlikKodu));
        self::metin($kok, 'GuvenlikKoduGecerlilikTarihi', self::tarih($ustveri->guvenlikKoduGecerlilikTarihi));
        self::metin($kok, 'MimeTuru', $ustveri->mimeTuru);
        self::tanimlayici($kok, 'OzId', $ustveri->ozId);

        $dagitimListesi = self::eleman($kok, 'DagitimListesi');

        foreach ($ustveri->dagitimlar as $dagitim) {
            self::dagitim($dagitimListesi, $dagitim);
        }

        if ($ustveri->ekler !== []) {
            $ekler = self::eleman($kok, 'Ekler');

            foreach ($ustveri->ekler as $ek) {
                self::ek($ekler, $ek);
            }
        }

        if ($ustveri->ilgiler !== []) {
            $ilgiler = self::eleman($kok, 'Ilgiler');

            foreach ($ustveri->ilgiler as $ilgi) {
                self::ilgi($ilgiler, $ilgi);
            }
        }

        self::metin($kok, 'Dil', $ustveri->dil);
        self::taraf(self::eleman($kok, 'Olusturan'), $ustveri->olusturan);

        if ($ustveri->ilgililer !== []) {
            $ilgiliListesi = self::eleman($kok, 'IlgiliListesi');

            foreach ($ustveri->ilgililer as $ilgili) {
                self::taraf(self::eleman($ilgiliListesi, 'Ilgili'), $ilgili);
            }
        }

        self::metin($kok, 'DosyaAdi', $ustveri->dosyaAdi);

        // Dosya planı, hizmet envanteri ve doğrulama adresi 2.0 ile geldi.
        if ($eski) {
            return $belge->saveXML();
        }

        if ($ustveri->sdpBilgisi !== null) {
            $sdpBilgisi = self::eleman($kok, 'SdpBilgisi');
            self::sdp(self::eleman($sdpBilgisi, 'AnaSdp'), $ustveri->sdpBilgisi->anaSdp);

            if ($ustveri->sdpBilgisi->digerSdpler !== []) {
                $digerSdpler = self::eleman($sdpBilgisi, 'DigerSdpler');

                foreach ($ustveri->sdpBilgisi->digerSdpler as $sdp) {
                    self::sdp(self::eleman($digerSdpler, 'SdpListesi'), $sdp);
                }
            }
        }

        if ($ustveri->heyskler !== []) {
            $heyskListesi = self::eleman($kok, 'HeyskListesi');

            foreach ($ustveri->heyskler as $heysk) {
                $eleman = self::eleman($heyskListesi, 'Heysk');
                self::metin($eleman, 'Kod', (string) $heysk->kod);
                self::metin($eleman, 'Ad', $heysk->ad);
                self::metin($eleman, 'Tanim', $heysk->tanim);
            }
        }

        self::metin(self::eleman($kok, 'DogrulamaBilgisi'), 'DogrulamaAdresi', $ustveri->dogrulamaAdresi);

        return $belge->saveXML();
    }

    /**
     * "Belge Hedef" bileşeni: paketin elektronik olarak iletileceği alıcılar.
     *
     * @param  list<Taraf>  $hedefler
     */
    public static function belgeHedef(array $hedefler, Surum $surum = Surum::V2): string
    {
        self::$surum = $surum;

        $belge = self::belge();
        $kok = $belge->appendChild($belge->createElementNS('urn:dpt:eyazisma:schema:xsd:BelgeHedef-'.($surum === Surum::V1 ? '1' : '2'), 'BelgeHedef'));
        $kok->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tipler', self::tipler());

        $liste = self::eleman($kok, 'HedefListesi');

        foreach ($hedefler as $hedef) {
            self::taraf(self::eleman($liste, 'Hedef'), $hedef);
        }

        return $belge->saveXML();
    }

    /**
     * 1.x'in "Belge İmza" bileşeni: belgedeki imzalara ilişkin bilgi.
     *
     * @param  list<Imza>  $imzalar
     */
    public static function belgeImza(array $imzalar): string
    {
        if ($imzalar === []) {
            throw new EYazismaException('En az bir imza bilgisi bulunmalıdır.');
        }

        self::$surum = Surum::V1;

        $belge = self::belge();
        $kok = $belge->appendChild($belge->createElementNS('urn:dpt:eyazisma:schema:xsd:BelgeImza-1', 'BelgeImza'));
        $kok->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tipler', self::tipler());

        $liste = self::eleman($kok, 'ImzaListesi');

        foreach ($imzalar as $imza) {
            self::imza($liste, $imza);
        }

        return $belge->saveXML();
    }

    public static function nihaiUstveri(NihaiUstveri $nihaiUstveri): string
    {
        self::$surum = Surum::V2;

        if ($nihaiUstveri->imzalar === []) {
            throw new EYazismaException('Nihai üstveride en az bir imza bilgisi bulunmalıdır.');
        }

        $belge = self::belge();
        $kok = $belge->appendChild($belge->createElementNS(Ad::NIHAI_USTVERI, 'NihaiUstVeri'));
        $kok->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tipler', Ad::TIPLER);

        self::metin($kok, 'Tarih', self::tarih($nihaiUstveri->tarih));
        self::metin($kok, 'BelgeNo', $nihaiUstveri->belgeNo);

        $imzalar = self::eleman($kok, 'BelgeImzalar');

        foreach ($nihaiUstveri->imzalar as $imza) {
            self::imza($imzalar, $imza);
        }

        return $belge->saveXML();
    }

    /**
     * Paket Özeti, Nihai Özet ya da Paraf Özeti bileşeni.
     *
     * @param  string  $kok  "PaketOzeti", "NihaiOzet" veya "ParafOzeti"
     * @param  string  $paketId  özeti alınan paketin Id değeri
     * @param  list<OzetReferansi>  $referanslar
     */
    public static function ozet(string $kok, string $paketId, array $referanslar, Surum $surum = Surum::V2): string
    {
        $eski = $surum === Surum::V1;
        $adAlani = $eski ? Ad::PAKET_OZETI_1 : Ad::PAKET_OZETI;

        $belge = self::belge();
        $eleman = $belge->appendChild($belge->createElementNS($eski ? Ad::eskiOzet($kok) : Ad::ozet($kok), $kok));
        $eleman->setAttribute('Id', $paketId);

        foreach ($referanslar as $referans) {
            $satir = $eleman->appendChild($belge->createElementNS($adAlani, 'Reference'));
            $satir->setAttribute('URI', $referans->uri);
            $satir->setAttribute('Type', $referans->tur);

            // 1.x'te bileşen başına tek özet vardır ve doğrudan referansın altında durur.
            foreach ($eski ? array_slice($referans->ozetler, 0, 1) : $referans->ozetler as $ozet) {
                $kalem = $eski ? $satir : $satir->appendChild($belge->createElementNS($adAlani, 'DigestItem'));
                $kalem->appendChild($belge->createElementNS($adAlani, 'DigestMethod'))->setAttribute('Algorithm', $ozet->algoritma);
                $kalem->appendChild($belge->createElementNS($adAlani, 'DigestValue'))->appendChild($belge->createTextNode($ozet->deger));
            }
        }

        return $belge->saveXML();
    }

    public static function core(PaketOzellikleri $ozellikler): string
    {
        self::$surum = Surum::V2;

        $belge = self::belge();
        $kok = $belge->appendChild($belge->createElementNS(Ad::CORE, 'coreProperties'));

        foreach (['dc' => Ad::DC, 'dcterms' => Ad::DCTERMS, 'xsi' => Ad::XSI] as $onEk => $adAlani) {
            $kok->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$onEk, $adAlani);
        }

        $ekle = function (string $adAlani, string $ad, ?string $deger) use ($belge, $kok): ?DOMElement {
            if ($deger === null) {
                return null;
            }

            $eleman = $kok->appendChild($belge->createElementNS($adAlani, $ad));
            $eleman->appendChild($belge->createTextNode($deger));

            return $eleman;
        };

        $ekle(Ad::DC, 'dc:identifier', $ozellikler->tanimlayici);
        $ekle(Ad::DCTERMS, 'dcterms:created', $ozellikler->olusturulma?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
            ?->setAttributeNS(Ad::XSI, 'xsi:type', 'dcterms:W3CDTF');
        $ekle(Ad::DC, 'dc:creator', $ozellikler->olusturan);
        $ekle(Ad::DC, 'dc:subject', $ozellikler->konu);
        $ekle(Ad::CORE, 'category', $ozellikler->kategori);
        $ekle(Ad::CORE, 'contentType', $ozellikler->icerikTuru);
        $ekle(Ad::CORE, 'version', $ozellikler->surum);
        $ekle(Ad::CORE, 'revision', $ozellikler->revizyon);

        return $belge->saveXML();
    }

    private static function belge(): DOMDocument
    {
        return new DOMDocument('1.0', 'UTF-8');
    }

    private static function tipler(): string
    {
        return self::$surum === Surum::V1 ? 'urn:dpt:eyazisma:schema:xsd:Tipler-1' : Ad::TIPLER;
    }

    /** 2.0 ile değişen kodların 1.x karşılığı. */
    private static function guvenlikKodu(GuvenlikKodu $kod): string
    {
        return self::$surum === Surum::V1 && $kod === GuvenlikKodu::Yok ? GuvenlikKodu::TasnifDisi->value : $kod->value;
    }

    private static function ivedilik(Ivedilik $ivedilik): string
    {
        return self::$surum === Surum::V1 && $ivedilik === Ivedilik::Acele ? Ivedilik::Ivedi->value : $ivedilik->value;
    }

    private static function eleman(DOMElement $ust, string $ad): DOMElement
    {
        return $ust->appendChild($ust->ownerDocument->createElementNS(self::tipler(), 'tipler:'.$ad));
    }

    private static function metin(DOMElement $ust, string $ad, ?string $deger): ?DOMElement
    {
        if ($deger === null) {
            return null;
        }

        $eleman = self::eleman($ust, $ad);
        $eleman->appendChild($ust->ownerDocument->createTextNode($deger));

        return $eleman;
    }

    private static function tarih(?DateTimeInterface $tarih): ?string
    {
        return $tarih?->format('Y-m-d\TH:i:sP');
    }

    private static function mantiksal(?bool $deger): ?string
    {
        return $deger === null ? null : ($deger ? 'true' : 'false');
    }

    private static function tanimlayici(DOMElement $ust, string $ad, ?Tanimlayici $tanimlayici): void
    {
        $eleman = self::metin($ust, $ad, $tanimlayici?->deger);

        if ($eleman !== null && $tanimlayici->semaId !== null) {
            $eleman->setAttribute('schemeID', $tanimlayici->semaId);
        }
    }

    private static function id(DOMElement $ust, string $id, ?bool $eyazismaIdMi): void
    {
        $eleman = self::eleman($ust, 'Id');
        $eleman->setAttribute('Value', $id);

        if ($eyazismaIdMi !== null) {
            $eleman->setAttribute('EYazismaIdMi', self::mantiksal($eyazismaIdMi));
        }
    }

    private static function taraf(DOMElement $ust, Taraf $taraf): void
    {
        if ($taraf instanceof KurumKurulus) {
            $eleman = self::eleman($ust, 'KurumKurulus');
            self::metin($eleman, 'KKK', $taraf->kkk);
            self::metin($eleman, 'Adi', $taraf->adi);
            self::iletisim($eleman, $taraf->iletisimBilgisi);
            self::metin($eleman, 'BirimKKK', self::$surum === Surum::V1 ? null : $taraf->birimKkk);
        } elseif ($taraf instanceof GercekSahis) {
            self::gercekSahis($ust, 'GercekSahis', $taraf);
        } elseif ($taraf instanceof TuzelSahis) {
            $eleman = self::eleman($ust, 'TuzelSahis');
            self::tanimlayici($eleman, 'Id', $taraf->id);
            self::metin($eleman, 'Adi', $taraf->adi);
            self::iletisim($eleman, $taraf->iletisimBilgisi);
        } else {
            throw new EYazismaException('Bilinmeyen taraf türü: '.$taraf::class);
        }
    }

    private static function gercekSahis(DOMElement $ust, string $ad, ?GercekSahis $sahis): void
    {
        if ($sahis === null) {
            return;
        }

        $eleman = self::eleman($ust, $ad);
        $kisi = self::eleman($eleman, 'Kisi');
        self::metin($kisi, 'IlkAdi', $sahis->kisi->ilkAdi);
        self::metin($kisi, 'Soyadi', $sahis->kisi->soyadi);
        self::metin($kisi, 'IkinciAdi', $sahis->kisi->ikinciAdi);
        self::metin($kisi, 'Unvan', $sahis->kisi->unvan);
        self::metin($kisi, 'OnEk', $sahis->kisi->onEk);
        self::metin($eleman, 'TCKN', $sahis->tckn);
        self::metin($eleman, 'Gorev', $sahis->gorev);
        self::iletisim($eleman, $sahis->iletisimBilgisi);
    }

    private static function iletisim(DOMElement $ust, ?IletisimBilgisi $iletisim): void
    {
        if ($iletisim === null) {
            return;
        }

        $eleman = self::eleman($ust, 'IletisimBilgisi');
        self::metin($eleman, 'Telefon', $iletisim->telefon);
        self::metin($eleman, 'TelefonDiger', $iletisim->telefonDiger);
        self::metin($eleman, 'EPosta', $iletisim->ePosta);
        self::metin($eleman, 'KepAdresi', self::$surum === Surum::V1 ? null : $iletisim->kepAdresi);
        self::metin($eleman, 'Faks', $iletisim->faks);
        self::metin($eleman, 'WebAdresi', $iletisim->webAdresi);
        self::metin($eleman, 'Adres', $iletisim->adres);
        self::metin($eleman, 'Il', $iletisim->il);
        self::metin($eleman, 'Ilce', $iletisim->ilce);
        self::metin($eleman, 'Ulke', $iletisim->ulke);
    }

    private static function dagitim(DOMElement $ust, Dagitim $dagitim): void
    {
        $eleman = self::eleman($ust, 'Dagitim');
        self::taraf($eleman, $dagitim->taraf);
        self::metin($eleman, 'Ivedilik', self::ivedilik($dagitim->ivedilik));
        self::metin($eleman, 'DagitimTuru', $dagitim->dagitimTuru->value);
        self::metin($eleman, 'Miat', $dagitim->miat);

        if ($dagitim->konulmamisEkIdleri !== []) {
            $liste = self::eleman($eleman, 'KonulmamisEkListesi');

            foreach ($dagitim->konulmamisEkIdleri as $ekId) {
                self::metin(self::eleman($liste, 'KonulmamisEk'), 'EkId', $ekId);
            }
        }
    }

    private static function ek(DOMElement $ust, Ek $ek): void
    {
        $eleman = self::eleman($ust, 'Ek');
        self::id($eleman, $ek->id, $ek->eyazismaIdMi);
        self::metin($eleman, 'BelgeNo', $ek->belgeNo);
        self::metin($eleman, 'Tur', $ek->tur->value);
        self::metin($eleman, 'DosyaAdi', $ek->dosyaAdi);
        self::metin($eleman, 'MimeTuru', $ek->mimeTuru);
        self::metin($eleman, 'Ad', $ek->ad);
        self::metin($eleman, 'SiraNo', (string) $ek->siraNo);
        self::metin($eleman, 'Aciklama', $ek->aciklama);
        self::metin($eleman, 'Referans', $ek->referans);
        self::tanimlayici($eleman, 'OzId', $ek->ozId);
        self::metin($eleman, 'ImzaliMi', self::mantiksal($ek->imzaliMi));

        if ($ek->ozet !== null) {
            $ozet = self::eleman($eleman, 'Ozet');
            self::eleman($ozet, 'OzetAlgoritmasi')->setAttribute('Algorithm', $ek->ozet->algoritma);
            self::metin($ozet, 'OzetDegeri', $ek->ozet->deger);
        }
    }

    private static function ilgi(DOMElement $ust, Ilgi $ilgi): void
    {
        $eleman = self::eleman($ust, 'Ilgi');
        self::id($eleman, $ilgi->id, $ilgi->eyazismaIdMi);
        self::metin($eleman, 'BelgeNo', $ilgi->belgeNo);
        self::metin($eleman, 'Tarih', self::tarih($ilgi->tarih));
        self::metin($eleman, 'Etiket', $ilgi->etiket);
        self::metin($eleman, 'EkId', $ilgi->ekId);
        self::metin($eleman, 'Ad', $ilgi->ad);
        self::metin($eleman, 'Aciklama', $ilgi->aciklama);
        self::tanimlayici($eleman, 'OzId', $ilgi->ozId);
    }

    private static function imza(DOMElement $ust, Imza $imza): void
    {
        $eleman = self::eleman($ust, 'Imza');
        self::gercekSahis($eleman, 'Imzalayan', $imza->imzalayan);
        self::gercekSahis($eleman, 'YetkiDevreden', $imza->yetkiDevreden);
        self::gercekSahis($eleman, 'VekaletVeren', $imza->vekaletVeren);
        self::metin($eleman, 'Makam', $imza->makam);
        self::metin($eleman, 'Amac', $imza->amac);
        self::metin($eleman, 'Aciklama', $imza->aciklama);
        self::metin($eleman, 'Tarih', self::tarih($imza->tarih));
    }

    private static function sdp(DOMElement $eleman, Sdp $sdp): void
    {
        self::metin($eleman, 'Kod', $sdp->kod);
        self::metin($eleman, 'Ad', $sdp->ad);
        self::metin($eleman, 'Aciklama', $sdp->aciklama);
    }
}
