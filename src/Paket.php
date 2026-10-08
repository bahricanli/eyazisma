<?php

namespace BahriCanli\EYazisma;

use BahriCanli\EYazisma\Contracts\Imzalayici;
use BahriCanli\EYazisma\Dogrulama\Dogrulayici;
use BahriCanli\EYazisma\Dogrulama\Rapor;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Exceptions\DesteklenmeyenPaketException;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\Ozet;
use BahriCanli\EYazisma\Model\OzetReferansi;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\Ustveri;
use BahriCanli\EYazisma\Opc\Package;
use BahriCanli\EYazisma\Opc\ZipReader;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Okuyucu;
use BahriCanli\EYazisma\Xml\Yazici;

/**
 * Şifresiz e-Yazışma Paketi (".eyp"): 2.x ve 2.0 öncesi (1.x).
 *
 * 1.x paket imzayla tamamlanır; mühür o kuşakta isteğe bağlıdır. 2.x paket üç adımda tamamlanır: PaketOlusturucu paket özetini üretir, paket özeti elektronik imzayla
 * imzalanıp imzaEkle() ile eklenir, bunun ürettiği nihai özet elektronik mühürle mühürlenip
 * muhurEkle() ile eklenir. Paket her adımda kaydedilip sonra ac() ile kaldığı yerden sürdürülebilir.
 */
final class Paket
{
    /** Üretilen paketlerin "Core" bileşenine yazılan e-Yazışma Teknik Rehberi sürümü. */
    public const SURUM = '2.0';

    public const REVIZYON = 'PHP/bahricanli-eyazisma';

    private ?Ustveri $ustveri = null;

    private ?Surum $surum = null;

    /**
     * @internal paket PaketOlusturucu, ac() ya da icerikten() ile elde edilir
     */
    public function __construct(private readonly Package $opc)
    {
    }

    public static function yeni(): PaketOlusturucu
    {
        return new PaketOlusturucu;
    }

    public static function ac(string $yol, int $boyutSiniri = ZipReader::VARSAYILAN_SINIR): self
    {
        $icerik = @file_get_contents($yol);

        if ($icerik === false) {
            throw new EYazismaException("Paket okunamadı: {$yol}");
        }

        return self::icerikten($icerik, $boyutSiniri);
    }

    /**
     * @param  int  $boyutSiniri  paketin açılmış toplam boyutu için üst sınır (bayt)
     */
    public static function icerikten(string $icerik, int $boyutSiniri = ZipReader::VARSAYILAN_SINIR): self
    {
        $opc = Package::read($icerik, $boyutSiniri);

        if ($opc->relationships('', Ad::ILISKI_SIFRELI_ICERIK) !== []) {
            throw new DesteklenmeyenPaketException('Şifreli e-Yazışma Paketi (.eyps) desteklenmiyor.');
        }

        if ($opc->relationships('', Ad::ILISKI_ORIJINAL_PAKET) !== []) {
            throw new DesteklenmeyenPaketException('e-Yazışma Güncelleme Paketi (.eypg) desteklenmiyor.');
        }

        $paket = new self($opc);
        $ustveri = $paket->bilesen(Ad::ILISKI_USTVERI)
            ?? throw new GecersizPaketException('Dosya bir e-Yazışma Paketi değil: "Üstveri" bileşeni yok.');

        if (Okuyucu::surum($ustveri) === null) {
            throw new DesteklenmeyenPaketException('Paketin üstveri şeması tanınmıyor: '.Okuyucu::belge($ustveri, 'Üstveri')->documentElement->namespaceURI);
        }

        return $paket;
    }

    /**
     * Paketin kuşağı: 2.0 öncesi (1.x) ya da 2.x.
     */
    public function surum(): Surum
    {
        if ($this->surum === null) {
            $ustveri = $this->bilesen(Ad::ILISKI_USTVERI);
            $this->surum = ($ustveri === null ? null : Okuyucu::surum($ustveri)) ?? Surum::V2;
        }

        return $this->surum;
    }

    public function belgeId(): string
    {
        return $this->ustveri()->belgeId;
    }

    public function ustveri(): Ustveri
    {
        return $this->ustveri ??= Okuyucu::ustveri(
            $this->bilesen(Ad::ILISKI_USTVERI) ?? throw new GecersizPaketException('Pakette "Üstveri" bileşeni yok.')
        );
    }

    /**
     * Belgenin tarihi, sayısı ve imza bilgileri. 2.x'te "Nihai Üstveri" bileşenidir ve imzayla eklenir;
     * 1.x'te tarih ve sayı üstveriden, imza bilgileri "Belge İmza" bileşeninden derlenir.
     */
    public function nihaiUstveri(): ?NihaiUstveri
    {
        if ($this->surum() === Surum::V1) {
            return Okuyucu::eskiNihaiUstveri($this->bilesen(Ad::ILISKI_USTVERI), $this->bilesen(Ad::ILISKI_BELGE_IMZA));
        }

        $xml = $this->bilesen(Ad::ILISKI_NIHAI_USTVERI);

        return $xml === null ? null : Okuyucu::nihaiUstveri($xml);
    }

    /**
     * "Belge Hedef" bileşenindeki alıcılar: paketin elektronik olarak iletileceği taraflar.
     * Bileşen 1.x paketlerde bulunur; yoksa boş liste döner.
     *
     * @return list<Model\Taraf>
     */
    public function hedefler(): array
    {
        $xml = $this->bilesen(Ad::ILISKI_BELGE_HEDEF);

        return $xml === null ? [] : Okuyucu::belgeHedef($xml);
    }

    public function ozellikler(): ?PaketOzellikleri
    {
        $xml = $this->bilesen(Ad::ILISKI_CORE);

        return $xml === null ? null : Okuyucu::core($xml);
    }

    public function ustYazi(): Dosya
    {
        $ad = $this->bilesenAdi(Ad::ILISKI_USTYAZI) ?? throw new GecersizPaketException('Pakette "Üst Yazı" bileşeni yok.');

        return new Dosya($this->opc->get($ad), basename($ad), $this->opc->contentType($ad) ?? $this->ustveri()->mimeTuru);
    }

    /**
     * Dahili elektronik dosya türündeki ekin dosyası; ek pakete konulmamışsa null.
     */
    public function ekDosyasi(string $ekId): ?Dosya
    {
        return $this->ekDosyalari()[strtoupper($ekId)] ?? null;
    }

    /**
     * @return array<string, Dosya> büyük harfli ek Id => dosya; imzasız ekler dahil
     */
    public function ekDosyalari(): array
    {
        $dosyalar = [];

        foreach ($this->ekBilesenleri() as $ekId => $ad) {
            $dosyalar[$ekId] = new Dosya(
                $this->opc->get($ad),
                basename($ad),
                $this->opc->contentType($ad) ?? $this->ustveri()->ek($ekId)?->mimeTuru ?? Dosya::mimeTuruTahmini($ad),
            );
        }

        return $dosyalar;
    }

    /**
     * Elektronik imzayla imzalanacak "Paket Özeti" bileşeninin içeriği.
     */
    public function paketOzeti(): string
    {
        return $this->bilesen(Ad::ILISKI_PAKET_OZETI) ?? throw new GecersizPaketException('Pakette "Paket Özeti" bileşeni yok.');
    }

    /**
     * Elektronik mühürle mühürlenecek "Nihai Özet" bileşeninin içeriği; imza eklenmeden önce null.
     */
    public function nihaiOzet(): ?string
    {
        return $this->bilesen(Ad::ILISKI_NIHAI_OZET);
    }

    /** "Elektronik İmza" bileşeni (CAdES). */
    public function imza(): ?string
    {
        return $this->bilesen(Ad::ILISKI_IMZA, $this->bilesenAdi(Ad::ILISKI_PAKET_OZETI));
    }

    /** "Elektronik Mühür" bileşeni (CAdES). */
    public function muhur(): ?string
    {
        return $this->bilesen(Ad::ILISKI_MUHUR, $this->bilesenAdi(Ad::ILISKI_NIHAI_OZET));
    }

    /**
     * 1.x paket imzayla tamamlanır; mühür o kuşakta isteğe bağlıdır.
     */
    public function asama(): PaketAsamasi
    {
        if ($this->surum() === Surum::V1) {
            return $this->imza() === null ? PaketAsamasi::ImzaBekliyor : PaketAsamasi::Tamamlandi;
        }

        return match (true) {
            $this->imza() === null || $this->nihaiOzet() === null => PaketAsamasi::ImzaBekliyor,
            $this->muhur() === null => PaketAsamasi::MuhurBekliyor,
            default => PaketAsamasi::Tamamlandi,
        };
    }

    /**
     * Paket özetinin CAdES tümleşik imzasını ve nihai üstveriyi ekler, nihai özeti üretir.
     */
    public function imzaEkle(string $imza, NihaiUstveri $nihaiUstveri): static
    {
        if ($this->asama() !== PaketAsamasi::ImzaBekliyor) {
            throw new GecersizPaketException('Paket zaten imzalı; imzalı paketin içeriği değiştirilemez.');
        }

        if ($imza === '') {
            throw new EYazismaException('İmza içeriği boş olamaz.');
        }

        $paketOzeti = $this->bilesenAdi(Ad::ILISKI_PAKET_OZETI) ?? throw new GecersizPaketException('Pakette "Paket Özeti" bileşeni yok.');
        $mevcut = Okuyucu::ozet($this->opc->get($paketOzeti), 'PaketOzeti');

        $this->opc->put(Ad::PARCA_IMZA, $imza, Ad::TUR_IMZA);
        $this->opc->relate($paketOzeti, Ad::ILISKI_IMZA, '../Imzalar/ImzaCades.imz', 'IdImzaCades');

        if ($this->surum() === Surum::V1) {
            return $this->eskiPaketiTamamla($paketOzeti, $mevcut, $nihaiUstveri);
        }

        $this->opc->put(Ad::PARCA_NIHAI_USTVERI, Yazici::nihaiUstveri($nihaiUstveri));
        $this->opc->relate('', Ad::ILISKI_NIHAI_USTVERI, Ad::PARCA_NIHAI_USTVERI, 'IdNihaiUstveri');

        $bilesenler = array_filter([
            $this->bilesenAdi(Ad::ILISKI_USTVERI),
            $this->bilesenAdi(Ad::ILISKI_USTYAZI),
            $parafOzeti = $this->bilesenAdi(Ad::ILISKI_PARAF_OZETI),
            $this->bilesenAdi(Ad::ILISKI_PARAF_IMZA, $parafOzeti),
            $paketOzeti,
            Ad::PARCA_IMZA,
            Ad::PARCA_NIHAI_USTVERI,
            ...$this->imzaliEkBilesenleri(),
            $this->bilesenAdi(Ad::ILISKI_CORE),
        ]);

        $algoritmalar = array_filter(array_map(
            fn (Ozet $ozet) => OzetAlgoritmasi::tryFrom($ozet->algoritma),
            $mevcut['referanslar'][0]->ozetler ?? []
        )) ?: [OzetAlgoritmasi::Sha256, OzetAlgoritmasi::Sha512];

        $this->opc->put(Ad::PARCA_NIHAI_OZET, Yazici::ozet(
            'NihaiOzet',
            $mevcut['id'] ?: $this->belgeId(),
            self::referanslar($this->opc, $bilesenler, $algoritmalar),
        ));
        $this->opc->relate('', Ad::ILISKI_NIHAI_OZET, Ad::PARCA_NIHAI_OZET, 'IdNihaiOzet');

        return $this;
    }

    /**
     * 1.x: imza bilgileri "Belge İmza" bileşenine yazılır ve nihai özet üretilir; paket tamamlanır.
     *
     * @param  array{id: string, referanslar: list<OzetReferansi>}  $paketOzetiVerisi
     */
    private function eskiPaketiTamamla(string $paketOzeti, array $paketOzetiVerisi, NihaiUstveri $nihaiUstveri): static
    {
        $kayitli = Okuyucu::eskiNihaiUstveri($this->bilesen(Ad::ILISKI_USTVERI), null);

        if ($nihaiUstveri->belgeNo !== $kayitli->belgeNo) {
            throw new GecersizPaketException("1.x pakette belgenin sayısı üstveridedir ve imzayla değişemez: {$kayitli->belgeNo}");
        }

        $belgeImza = '/Imzalar/BelgeImza.xml';
        $this->opc->put($belgeImza, Yazici::belgeImza($nihaiUstveri->imzalar));
        $this->opc->relate('', Ad::ILISKI_BELGE_IMZA, $belgeImza, 'IdBelgeImza');

        $bilesenler = array_filter([
            $this->bilesenAdi(Ad::ILISKI_USTYAZI),
            $this->bilesenAdi(Ad::ILISKI_USTVERI),
            $this->bilesenAdi(Ad::ILISKI_BELGE_HEDEF),
            ...$this->imzaliEkBilesenleri(),
            $belgeImza,
            $paketOzeti,
            Ad::PARCA_IMZA,
            $this->bilesenAdi(Ad::ILISKI_CORE),
        ]);

        $this->opc->put(Ad::PARCA_NIHAI_OZET, Yazici::ozet(
            'NihaiOzet',
            $paketOzetiVerisi['id'] ?: $this->belgeId(),
            self::referanslar($this->opc, $bilesenler, [OzetAlgoritmasi::Sha256]),
            Surum::V1,
        ));
        $this->opc->relate('', Ad::ILISKI_NIHAI_OZET, Ad::PARCA_NIHAI_OZET, 'IdNihaiOzet');

        return $this;
    }

    public function imzala(Imzalayici $imzalayici, NihaiUstveri $nihaiUstveri): static
    {
        return $this->imzaEkle($imzalayici->imzala($this->paketOzeti()), $nihaiUstveri);
    }

    /**
     * Nihai özetin CAdES tümleşik imzasını (elektronik mühür) ekler; paket tamamlanır.
     */
    public function muhurEkle(string $muhur): static
    {
        if ($this->imza() === null || $this->nihaiOzet() === null) {
            throw new GecersizPaketException('Paket önce imzalanmalıdır; mühür nihai özete atılır.');
        }

        if ($this->muhur() !== null) {
            throw new GecersizPaketException('Paket zaten mühürlü.');
        }

        if ($muhur === '') {
            throw new EYazismaException('Mühür içeriği boş olamaz.');
        }

        $this->opc->put(Ad::PARCA_MUHUR, $muhur, Ad::TUR_IMZA);
        $this->opc->relate($this->bilesenAdi(Ad::ILISKI_NIHAI_OZET), Ad::ILISKI_MUHUR, '../Muhur/MuhurCades.imz', 'IdMuhurCades');

        return $this;
    }

    public function muhurle(Imzalayici $muhurleyici): static
    {
        return $this->muhurEkle($muhurleyici->imzala(
            $this->nihaiOzet() ?? throw new GecersizPaketException('Paket önce imzalanmalıdır; mühür nihai özete atılır.')
        ));
    }

    /**
     * Paketi rehberin kurallar listesine göre denetler. İmzaların kriptografik doğrulaması yapılmaz.
     */
    public function dogrula(): Rapor
    {
        return (new Dogrulayici)->dogrula($this);
    }

    /** Paketin önerilen dosya adı: [BelgeId].eyp (K.66). */
    public function dosyaAdi(): string
    {
        return $this->belgeId().'.eyp';
    }

    public function icerik(): string
    {
        return $this->opc->write();
    }

    public function kaydet(string $yol): void
    {
        if (@file_put_contents($yol, $this->icerik()) === false) {
            throw new EYazismaException("Paket yazılamadı: {$yol}");
        }
    }

    /**
     * @internal
     */
    public function opc(): Package
    {
        return $this->opc;
    }

    /**
     * İlişki türüyle gösterilen bileşenin paket içindeki adı.
     *
     * @internal
     */
    public function bilesenAdi(string $iliskiTuru, ?string $kaynak = ''): ?string
    {
        if ($kaynak === null) {
            return null;
        }

        foreach ($this->opc->relationships($kaynak, $iliskiTuru) as $iliski) {
            $ad = $this->opc->resolve($iliski);

            if ($this->opc->has($ad)) {
                return $ad;
            }
        }

        return null;
    }

    /**
     * @internal
     *
     * @return array<string, string> büyük harfli ek Id => bileşen adı; imzasız ekler dahil
     */
    public function ekBilesenleri(): array
    {
        $bilesenler = [];

        foreach ([Ad::ILISKI_EK => 'IdEk_', Ad::ILISKI_IMZASIZ_EK => 'IdImzasizEk_'] as $tur => $onEk) {
            foreach ($this->opc->relationships('', $tur) as $iliski) {
                $ad = $this->opc->resolve($iliski);

                if ($this->opc->has($ad) && stripos($iliski->id, $onEk) === 0) {
                    $bilesenler[strtoupper(substr($iliski->id, strlen($onEk)))] = $ad;
                }
            }
        }

        return $bilesenler;
    }

    /**
     * @internal
     *
     * @return list<string> özeti paket özetine giren (imzalı) eklerin bileşen adları
     */
    public function imzaliEkBilesenleri(): array
    {
        return array_values(array_filter(array_map(
            fn ($iliski) => $this->opc->resolve($iliski),
            $this->opc->relationships('', Ad::ILISKI_EK)
        ), $this->opc->has(...)));
    }

    /**
     * @internal
     *
     * @param  list<string>  $bilesenler
     * @param  list<OzetAlgoritmasi>  $algoritmalar
     * @return list<OzetReferansi>
     */
    public static function referanslar(Package $opc, array $bilesenler, array $algoritmalar): array
    {
        return array_map(fn (string $ad) => new OzetReferansi($ad, array_map(
            fn (OzetAlgoritmasi $algoritma) => Ozet::hesapla($opc->get($ad), $algoritma),
            $algoritmalar
        )), array_values($bilesenler));
    }

    private function bilesen(string $iliskiTuru, ?string $kaynak = ''): ?string
    {
        $ad = $this->bilesenAdi($iliskiTuru, $kaynak);

        return $ad === null ? null : $this->opc->get($ad);
    }
}
