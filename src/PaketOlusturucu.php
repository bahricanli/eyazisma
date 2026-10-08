<?php

namespace BahriCanli\EYazisma;

use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\EkTuru;
use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use BahriCanli\EYazisma\Enums\Ivedilik;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Model\Dagitim;
use BahriCanli\EYazisma\Model\Ek;
use BahriCanli\EYazisma\Model\Heysk;
use BahriCanli\EYazisma\Model\Ilgi;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\Ozet;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\Sdp;
use BahriCanli\EYazisma\Model\SdpBilgisi;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\Ustveri;
use BahriCanli\EYazisma\Opc\Package;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Yazici;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * İmzaya hazır e-Yazışma Paketi üretir: üst yazı, üstveri, ekler, "Core" ve paket özeti.
 */
final class PaketOlusturucu
{
    private ?string $belgeId = null;

    private ?string $konu = null;

    private GuvenlikKodu $guvenlikKodu = GuvenlikKodu::Yok;

    private ?DateTimeImmutable $guvenlikKoduGecerlilikTarihi = null;

    private ?Tanimlayici $ozId = null;

    private ?string $dil = 'tur';

    private ?Taraf $olusturan = null;

    private ?string $dogrulamaAdresi = null;

    private ?Dosya $ustYazi = null;

    private ?SdpBilgisi $sdpBilgisi = null;

    private string $surum = Paket::SURUM;

    private ?DateTimeImmutable $tarih = null;

    private ?string $belgeNo = null;

    /** @var list<Taraf>|null */
    private ?array $hedefler = null;

    /** @var list<Dagitim> */
    private array $dagitimlar = [];

    /** @var list<Ilgi> */
    private array $ilgiler = [];

    /** @var list<Taraf> */
    private array $ilgililer = [];

    /** @var list<Heysk> */
    private array $heyskler = [];

    /** @var list<array{ek: Ek, dosya: ?Dosya, imzasiz: bool}> */
    private array $ekler = [];

    /** @var list<OzetAlgoritmasi> */
    private array $ozetAlgoritmalari = [OzetAlgoritmasi::Sha256, OzetAlgoritmasi::Sha512];

    /**
     * Belgenin tekil numarası; verilmezse üretilir. Aynı zamanda belge doğrulama kodudur.
     */
    public function belgeId(string $belgeId): static
    {
        if (! Guid::gecerliMi($belgeId)) {
            throw new EYazismaException("Belge Id bir GUID olmalıdır: {$belgeId}");
        }

        $this->belgeId = strtoupper($belgeId);

        return $this;
    }

    public function konu(string $konu): static
    {
        $this->konu = $konu;

        return $this;
    }

    public function guvenlikKodu(GuvenlikKodu $kod, ?DateTimeInterface $gecerlilikTarihi = null): static
    {
        $this->guvenlikKodu = $kod;
        $this->guvenlikKoduGecerlilikTarihi = $gecerlilikTarihi === null ? null : DateTimeImmutable::createFromInterface($gecerlilikTarihi);

        return $this;
    }

    /**
     * Belgenin üretildiği sistemdeki tekil anahtarı.
     */
    public function ozId(string $deger, string $semaId = 'GUID'): static
    {
        $this->ozId = new Tanimlayici($deger, $semaId);

        return $this;
    }

    /**
     * ISO 639-3 dil kodu; varsayılan "tur". null verilirse üstveriye yazılmaz.
     */
    public function dil(?string $dil): static
    {
        $this->dil = $dil;

        return $this;
    }

    public function olusturan(Taraf $olusturan): static
    {
        $this->olusturan = $olusturan;

        return $this;
    }

    /**
     * Üst yazıdaki dağıtımların tümü eklenmelidir.
     *
     * @param  string|null  $miat  ISO 8601 süre (ör. "P3D")
     * @param  list<string>  $konulmamisEkIdleri
     */
    public function dagitim(
        Taraf|Dagitim $alici,
        DagitimTuru $dagitimTuru = DagitimTuru::Geregi,
        Ivedilik $ivedilik = Ivedilik::Normal,
        ?string $miat = null,
        array $konulmamisEkIdleri = [],
    ): static {
        $this->dagitimlar[] = $alici instanceof Dagitim
            ? $alici
            : new Dagitim($alici, $dagitimTuru, $ivedilik, $miat, array_map('strtoupper', $konulmamisEkIdleri));

        return $this;
    }

    public function ilgi(Ilgi $ilgi): static
    {
        $this->ilgiler[] = $ilgi;

        return $this;
    }

    /**
     * Belgeyle ilgili iletişim kurulacak taraf.
     */
    public function ilgili(Taraf $ilgili): static
    {
        $this->ilgililer[] = $ilgili;

        return $this;
    }

    public function sdp(Sdp $anaSdp, Sdp ...$digerSdpler): static
    {
        $this->sdpBilgisi = new SdpBilgisi($anaSdp, array_values($digerSdpler));

        return $this;
    }

    public function heysk(Heysk $heysk): static
    {
        $this->heyskler[] = $heysk;

        return $this;
    }

    /**
     * Belgenin doğrulanacağı web adresi.
     */
    public function dogrulamaAdresi(string $adres): static
    {
        $this->dogrulamaAdresi = $adres;

        return $this;
    }

    /**
     * Üst yazı; rehber PDF/A (ISO 19005-1) biçimini ister.
     */
    public function ustYazi(Dosya $dosya): static
    {
        $this->ustYazi = $dosya;

        return $this;
    }

    /**
     * Dosyası pakete konan ek (dahili elektronik dosya).
     *
     * @param  bool  $imzasiz  true ise ekin özeti paket özetine girmez ("İmzasız Ek")
     * @param  string|null  $id  dağıtımda ya da ilgide bu eke başvurulacaksa verilir; verilmezse üretilir
     */
    public function ek(
        Dosya $dosya,
        ?string $ad = null,
        ?string $aciklama = null,
        ?string $belgeNo = null,
        bool $imzasiz = false,
        ?string $id = null,
        ?Tanimlayici $ozId = null,
        ?bool $eyazismaIdMi = null,
    ): static {
        $this->ekler[] = ['dosya' => $dosya, 'imzasiz' => $imzasiz, 'ek' => new Ek(
            id: $this->ekId($id),
            tur: EkTuru::DahiliElektronikDosya,
            siraNo: count($this->ekler) + 1,
            ad: $ad,
            aciklama: $aciklama,
            belgeNo: $belgeNo,
            mimeTuru: $dosya->mimeTuru,
            ozId: $ozId,
            imzaliMi: ! $imzasiz,
            eyazismaIdMi: $eyazismaIdMi,
        )];

        return $this;
    }

    /**
     * Elektronik olarak ifade edilemeyen ek (kitap, CD...); yalnız üstveride tanımlanır.
     */
    public function fizikselEk(string $ad, ?string $aciklama = null, ?string $belgeNo = null, ?string $id = null): static
    {
        $this->ekler[] = ['dosya' => null, 'imzasiz' => true, 'ek' => new Ek(
            id: $this->ekId($id),
            tur: EkTuru::FizikselNesne,
            siraNo: count($this->ekler) + 1,
            ad: $ad,
            aciklama: $aciklama,
            belgeNo: $belgeNo,
            imzaliMi: false,
        )];

        return $this;
    }

    /**
     * Paket dışındaki bir adresi gösteren ek.
     *
     * @param  Ozet|null  $ozet  adresteki dosyanın sonradan değişmediğini denetlemek için özeti
     */
    public function hariciEk(
        string $referans,
        ?string $ad = null,
        ?string $aciklama = null,
        ?string $belgeNo = null,
        ?string $dosyaAdi = null,
        ?string $mimeTuru = null,
        ?Ozet $ozet = null,
        ?string $id = null,
    ): static {
        $this->ekler[] = ['dosya' => null, 'imzasiz' => true, 'ek' => new Ek(
            id: $this->ekId($id),
            tur: EkTuru::HariciReferans,
            siraNo: count($this->ekler) + 1,
            ad: $ad,
            aciklama: $aciklama,
            belgeNo: $belgeNo,
            dosyaAdi: $dosyaAdi,
            mimeTuru: $mimeTuru,
            referans: $referans,
            imzaliMi: false,
            ozet: $ozet,
        )];

        return $this;
    }

    /**
     * Her bileşen için iki ayrı algoritmayla özet alınır; biri SHA-512 olmalıdır.
     */
    public function ozetAlgoritmalari(OzetAlgoritmasi $birinci, OzetAlgoritmasi $ikinci): static
    {
        if ($birinci === $ikinci || ! in_array(OzetAlgoritmasi::Sha512, [$birinci, $ikinci], true)) {
            throw new EYazismaException('İki farklı özet algoritması verilmeli ve biri SHA-512 olmalıdır.');
        }

        $this->ozetAlgoritmalari = [$birinci, $ikinci];

        return $this;
    }

    /**
     * Paketin uyacağı e-Yazışma Teknik Rehberi sürümü (varsayılan Paket::SURUM). "1" ile başlayan
     * sürüm (ör. "1.3") 2.0 öncesi yapıda paket üretir: tarih ve sayı üstveriye yazılır, mühür gerekmez.
     */
    public function surum(string $surum): static
    {
        $this->surum = $surum;

        return $this;
    }

    /**
     * Belgenin tarihi ve sayısı. Yalnız 1.x paketlerde oluştururken verilir, çünkü o kuşakta
     * imzalanan üstverinin parçasıdır; 2.x'te imzayla birlikte nihai üstveride verilir.
     */
    public function belge(DateTimeInterface $tarih, string $belgeNo): static
    {
        $this->tarih = DateTimeImmutable::createFromInterface($tarih);
        $this->belgeNo = $belgeNo;

        return $this;
    }

    /**
     * 1.x paketlerde paketin elektronik olarak iletileceği alıcılar; verilmezse dağıtımdaki herkes.
     */
    public function hedefler(Taraf ...$hedefler): static
    {
        $this->hedefler = array_values($hedefler);

        return $this;
    }

    public function olustur(): Paket
    {
        $eski = str_starts_with($this->surum, '1');
        $kusak = $eski ? Surum::V1 : Surum::V2;

        $eksikler = array_keys(array_filter([
            'konu' => $this->konu === null || $this->konu === '',
            'oluşturan' => $this->olusturan === null,
            'en az bir dağıtım' => $this->dagitimlar === [],
            'doğrulama adresi' => ! $eski && ($this->dogrulamaAdresi === null || $this->dogrulamaAdresi === ''),
            'belgenin tarihi ve sayısı' => $eski && ($this->tarih === null || $this->belgeNo === null || $this->belgeNo === ''),
            'üst yazı' => $this->ustYazi === null,
        ]));

        if ($eksikler !== []) {
            throw new EYazismaException('Paket oluşturmak için eksik bilgi: '.implode(', ', $eksikler).'.');
        }

        $belgeId = $this->belgeId ?? Guid::uret();
        $opc = new Package;
        $imzalananlar = [Ad::PARCA_USTVERI];

        $ustYazi = '/UstYazi/'.$this->ustYazi->paketAdi();
        $opc->put($ustYazi, $this->ustYazi->icerik, $this->ustYazi->mimeTuru);
        $opc->relate('', Ad::ILISKI_USTYAZI, $ustYazi, 'IdUstYazi');
        $imzalananlar[] = $ustYazi;

        if ($eski) {
            $belgeHedef = '/BelgeHedef/BelgeHedef.xml';
            $opc->put($belgeHedef, Yazici::belgeHedef($this->hedefler ?? array_map(fn (Dagitim $dagitim) => $dagitim->taraf, $this->dagitimlar), Surum::V1));
            $opc->relate('', Ad::ILISKI_BELGE_HEDEF, $belgeHedef, 'IdBelgeHedef');
            $imzalananlar[] = $belgeHedef;
        }

        $ekler = [];

        foreach ($this->ekler as ['ek' => $ek, 'dosya' => $dosya, 'imzasiz' => $imzasiz]) {
            if ($dosya !== null) {
                $ad = self::benzersizAd($opc, $imzasiz ? '/ImzasizEkler/' : '/Ekler/', $dosya->paketAdi());
                $opc->put($ad, $dosya->icerik, $dosya->mimeTuru);
                $opc->relate('', $imzasiz ? Ad::ILISKI_IMZASIZ_EK : Ad::ILISKI_EK, $ad, ($imzasiz ? 'IdImzasizEk_' : 'IdEk_').$ek->id);
                $ek = self::dosyaAdiyla($ek, basename($ad));

                if (! $imzasiz) {
                    $imzalananlar[] = $ad;
                }
            }

            $ekler[] = $ek;
        }

        $opc->put(Ad::PARCA_USTVERI, Yazici::ustveri(new Ustveri(
            belgeId: $belgeId,
            konu: $this->konu,
            mimeTuru: $this->ustYazi->mimeTuru,
            dosyaAdi: basename($ustYazi),
            olusturan: $this->olusturan,
            dagitimlar: $this->dagitimlar,
            dogrulamaAdresi: $this->dogrulamaAdresi ?? '',
            guvenlikKodu: $this->guvenlikKodu,
            guvenlikKoduGecerlilikTarihi: $this->guvenlikKoduGecerlilikTarihi,
            ozId: $this->ozId,
            ekler: $ekler,
            ilgiler: $this->ilgiler,
            dil: $this->dil,
            ilgililer: $this->ilgililer,
            sdpBilgisi: $this->sdpBilgisi,
            heyskler: $this->heyskler,
        ), $kusak, $eski ? new NihaiUstveri($this->tarih, $this->belgeNo, []) : null));
        $opc->relate('', Ad::ILISKI_USTVERI, Ad::PARCA_USTVERI, 'IdUstveri');

        $core = '/package/services/metadata/core-properties/'.bin2hex(random_bytes(16)).'.psmdcp';
        $opc->put($core, Yazici::core(new PaketOzellikleri(
            tanimlayici: $belgeId,
            konu: $this->konu,
            olusturan: implode('/', array_filter([$this->olusturan->gorunenAd(), $this->olusturan->kimlik()])),
            olusturulma: new DateTimeImmutable,
            kategori: PaketOzellikleri::KATEGORI_RESMI_YAZISMA,
            icerikTuru: PaketOzellikleri::ICERIK_TURU,
            surum: $this->surum,
            revizyon: Paket::REVIZYON,
        )), Ad::TUR_CORE);
        $opc->relate('', Ad::ILISKI_CORE, $core, 'R'.bin2hex(random_bytes(8)));

        $opc->put(Ad::PARCA_PAKET_OZETI, Yazici::ozet(
            'PaketOzeti',
            $belgeId,
            Paket::referanslar($opc, $imzalananlar, $eski ? [OzetAlgoritmasi::Sha256] : $this->ozetAlgoritmalari),
            $kusak,
        ));
        $opc->relate('', Ad::ILISKI_PAKET_OZETI, Ad::PARCA_PAKET_OZETI, 'IdPaketOzeti');

        return new Paket($opc);
    }

    private function ekId(?string $id): string
    {
        if ($id === null) {
            return Guid::uret();
        }

        if (! Guid::gecerliMi($id)) {
            throw new EYazismaException("Ek Id bir GUID olmalıdır: {$id}");
        }

        $id = strtoupper($id);

        foreach ($this->ekler as $mevcut) {
            if ($mevcut['ek']->id === $id) {
                throw new EYazismaException("Aynı Id ile iki ek eklenemez: {$id}");
            }
        }

        return $id;
    }

    private static function benzersizAd(Package $opc, string $dizin, string $ad): string
    {
        $uzanti = pathinfo($ad, PATHINFO_EXTENSION);
        $govde = $uzanti === '' ? $ad : substr($ad, 0, -strlen($uzanti) - 1);
        $aday = $dizin.$ad;

        for ($i = 2; $opc->has($aday); $i++) {
            $aday = $dizin.$govde.'_'.$i.($uzanti === '' ? '' : '.'.$uzanti);
        }

        return $aday;
    }

    private static function dosyaAdiyla(Ek $ek, string $dosyaAdi): Ek
    {
        return new Ek(
            id: $ek->id,
            tur: $ek->tur,
            siraNo: $ek->siraNo,
            ad: $ek->ad,
            aciklama: $ek->aciklama,
            belgeNo: $ek->belgeNo,
            dosyaAdi: $dosyaAdi,
            mimeTuru: $ek->mimeTuru,
            referans: $ek->referans,
            ozId: $ek->ozId,
            imzaliMi: $ek->imzaliMi,
            ozet: $ek->ozet,
            eyazismaIdMi: $ek->eyazismaIdMi,
        );
    }
}
