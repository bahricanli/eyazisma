<?php

namespace BahriCanli\EYazisma\Dogrulama;

use BahriCanli\EYazisma\Enums\EkTuru;
use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Enums\Seviye;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Guid;
use BahriCanli\EYazisma\Model\OzetReferansi;
use BahriCanli\EYazisma\Model\PaketOzellikleri;
use BahriCanli\EYazisma\Model\Ustveri;
use BahriCanli\EYazisma\Opc\Package;
use BahriCanli\EYazisma\Paket;
use BahriCanli\EYazisma\Xml\Ad;
use BahriCanli\EYazisma\Xml\Okuyucu;

/**
 * Şifresiz e-Yazışma Paketini rehberin kurallar listesine (K.1–K.100) göre denetler.
 *
 * Yapı, ilişkiler, üstveri tutarlılığı ve özet değerleri denetlenir. İmza ve mührün kriptografik
 * doğrulaması (sertifika zinciri, iptal durumu, zaman damgası, CAdES profili) kapsam dışıdır.
 */
final class Dogrulayici
{
    /** @var list<Bulgu> */
    private array $bulgular = [];

    private Paket $paket;

    private Package $opc;

    public function dogrula(Paket $paket): Rapor
    {
        $this->bulgular = [];
        $this->paket = $paket;
        $this->opc = $paket->opc();

        $ustveri = $this->ustveri();
        $this->ustYazi($ustveri);

        if ($ustveri !== null) {
            $this->ekler($ustveri);
            $this->core($ustveri);
        }

        $this->paketOzeti($ustveri);
        $imzali = $this->imza();
        $this->nihaiUstveri($imzali);
        $this->nihaiOzet($ustveri, $imzali);

        return new Rapor($this->bulgular);
    }

    private function ustveri(): ?Ustveri
    {
        $ad = $this->tekBilesen(Ad::ILISKI_USTVERI, 'Üstveri', 'K.16');

        if ($ad === null) {
            return null;
        }

        if (strcasecmp($ad, Ad::PARCA_USTVERI) !== 0) {
            $this->hata('K.18', '"Üstveri" bileşeninin paket içindeki yeri '.Ad::PARCA_USTVERI.' olmalıdır.');
        }

        try {
            $ustveri = Okuyucu::ustveri($this->opc->get($ad));
        } catch (EYazismaException $hata) {
            $this->hata('K.19', $hata->getMessage());

            return null;
        }

        $this->id('BelgeId', $ustveri->belgeId);

        if ($ustveri->dagitimlar === []) {
            $this->hata('K.19', '"Üstveri" bileşeninde en az bir dağıtım bulunmalıdır.');
        }

        if ($ustveri->ozId !== null && ($ustveri->ozId->semaId ?? '') === '') {
            $this->hata('K.19', 'OzId verildiğinde schemeID değeri de tanımlanmalıdır.');
        }

        if ($ustveri->dogrulamaAdresi === '') {
            $this->hata('K.19', '"Üstveri" bileşeninde doğrulama adresi bulunmalıdır.');
        }

        return $ustveri;
    }

    private function ustYazi(?Ustveri $ustveri): void
    {
        $ad = $this->tekBilesen(Ad::ILISKI_USTYAZI, 'Üst Yazı', 'K.2');

        if ($ad === null) {
            return;
        }

        if (stripos($ad, '/UstYazi/') !== 0) {
            $this->hata('K.4', '"Üst Yazı" bileşeni paket içinde /UstYazi/ konumunda bulunmalıdır.');
        }

        if (preg_match('/\s/', $ad)) {
            $this->hata('K.5', '"Üst Yazı" bileşeninin dosya adı boşluk içermemelidir.');
        }

        if (! str_starts_with($this->opc->get($ad), '%PDF-')) {
            $this->uyari('K.6', '"Üst Yazı" bileşeni PDF değil; rehber PDF/A (ISO 19005-1) biçimini ister.');
        }

        if ($ustveri !== null && $ustveri->dosyaAdi !== basename($ad)) {
            $this->uyari(null, "Üstverideki dosya adı ({$ustveri->dosyaAdi}) üst yazı bileşeninin adıyla (".basename($ad).') aynı değil.');
        }
    }

    private function ekler(Ustveri $ustveri): void
    {
        $bilesenler = $this->paket->ekBilesenleri();
        $konulmamislar = array_map('strtoupper', array_merge(...array_map(fn ($dagitim) => $dagitim->konulmamisEkIdleri, $ustveri->dagitimlar)));
        $idler = [];

        foreach ($ustveri->ekler as $ek) {
            $this->id('Ek Id', $ek->id);
            $idler[] = strtoupper($ek->id);

            if ($ek->tur === EkTuru::DahiliElektronikDosya && ! isset($bilesenler[strtoupper($ek->id)])) {
                // Bir alıcıya gönderilmeyen ek, o alıcının paketinden çıkarılmış olabilir.
                in_array(strtoupper($ek->id), $konulmamislar, true)
                    ? $this->uyari('K.23', "Ek pakette yok; dağıtımda konulmamış ek olarak belirtilmiş: {$ek->id}")
                    : $this->hata('K.23', "Üstveride dahili elektronik dosya olarak belirtilen ek pakette yok: {$ek->id}");
            }

            if ($ek->tur === EkTuru::HariciReferans && ($ek->referans ?? '') === '') {
                $this->hata('K.19', "Harici referans türündeki ekin referansı yok: {$ek->id}");
            }
        }

        foreach ($bilesenler as $ekId => $ad) {
            if ($ustveri->ek($ekId)?->tur !== EkTuru::DahiliElektronikDosya) {
                $this->hata('K.24', "Pakete eklenen ek üstveride belirtilmemiş: {$ad}");
            }
        }

        foreach ($this->opc->relationships('', Ad::ILISKI_EK) as $iliski) {
            if (stripos($this->opc->resolve($iliski), '/Ekler/') !== 0) {
                $this->hata('K.12', '"Ek" bileşeni paket içinde /Ekler/ konumunda bulunmalıdır: '.$this->opc->resolve($iliski));
            }
        }

        foreach ($ustveri->ilgiler as $ilgi) {
            $this->id('İlgi Id', $ilgi->id);
            $idler[] = strtoupper($ilgi->id);

            if ($ilgi->ekId !== null && $ilgi->ekId !== '' && $ustveri->ek($ilgi->ekId) === null) {
                $this->hata('K.25', "İlginin gösterdiği ek pakette yok: {$ilgi->ekId}");
            }
        }

        foreach ($ustveri->dagitimlar as $dagitim) {
            foreach ($dagitim->konulmamisEkIdleri as $ekId) {
                if ($ustveri->ek($ekId) === null) {
                    $this->hata('K.19', "Dağıtımda konulmamış olarak belirtilen ek üstveride yok: {$ekId}");
                }
            }
        }

        $idler[] = strtoupper($ustveri->belgeId);

        if (count($idler) !== count(array_unique($idler))) {
            $this->hata('K.61', 'Üst yazı, ekler ve ilgiler tekil Id değerlerine sahip olmalıdır.');
        }
    }

    private function core(Ustveri $ustveri): void
    {
        $ad = $this->tekBilesen(Ad::ILISKI_CORE, 'Core', null);

        if ($ad === null) {
            return;
        }

        try {
            $core = Okuyucu::core($this->opc->get($ad));
        } catch (EYazismaException $hata) {
            $this->hata(null, $hata->getMessage());

            return;
        }

        if (strcasecmp($core->tanimlayici ?? '', $ustveri->belgeId) !== 0) {
            $this->hata('K.68', '"Core" bileşeninin identifier değeri üstverideki BelgeId ile aynı olmalıdır.');
        }

        if ($core->konu !== $ustveri->konu) {
            $this->hata('K.69', '"Core" bileşeninin subject değeri üstverideki konu ile aynı olmalıdır.');
        }

        if ($core->kategori !== PaketOzellikleri::KATEGORI_RESMI_YAZISMA) {
            $this->hata('K.71', '"Core" bileşeninin category değeri '.PaketOzellikleri::KATEGORI_RESMI_YAZISMA.' olmalıdır.');
        }

        if ($core->icerikTuru !== PaketOzellikleri::ICERIK_TURU) {
            $this->hata('K.73', '"Core" bileşeninin contentType değeri '.PaketOzellikleri::ICERIK_TURU.' olmalıdır.');
        }

        foreach (['created' => $core->olusturulma, 'creator' => $core->olusturan, 'version' => $core->surum] as $alan => $deger) {
            if ($deger === null || $deger === '') {
                $this->hata(null, "\"Core\" bileşeninde {$alan} alanı zorunludur.");
            }
        }
    }

    private function paketOzeti(?Ustveri $ustveri): void
    {
        $ad = $this->tekBilesen(Ad::ILISKI_PAKET_OZETI, 'Paket Özeti', 'K.26');

        if ($ad === null) {
            return;
        }

        if (strcasecmp($ad, Ad::PARCA_PAKET_OZETI) !== 0) {
            $this->hata('K.28', '"Paket Özeti" bileşeninin paket içindeki yeri '.Ad::PARCA_PAKET_OZETI.' olmalıdır.');
        }

        $parafOzeti = $this->paket->bilesenAdi(Ad::ILISKI_PARAF_OZETI);

        $this->ozet($ad, 'PaketOzeti', 'Paket Özeti', $ustveri, haricilerOlabilir: true, kurallar: ['yapi' => 'K.29', 'id' => 'K.34', 'icerik' => 'K.33'], zorunlular: array_filter([
            $this->paket->bilesenAdi(Ad::ILISKI_USTVERI),
            $this->paket->bilesenAdi(Ad::ILISKI_USTYAZI),
            $parafOzeti,
            $this->paket->bilesenAdi(Ad::ILISKI_PARAF_IMZA, $parafOzeti),
            ...$this->paket->imzaliEkBilesenleri(),
        ]));
    }

    private function imza(): bool
    {
        $paketOzeti = $this->paket->bilesenAdi(Ad::ILISKI_PAKET_OZETI);
        $imza = $this->paket->imza();

        if ($imza === null) {
            $this->hata(null, 'Pakette "Elektronik İmza" bileşeni yok; paket imzalanmamış.');

            return false;
        }

        if ($paketOzeti !== null && count($this->opc->relationships($paketOzeti, Ad::ILISKI_IMZA)) > 1) {
            $this->hata(null, 'Pakette birden fazla "Elektronik İmza" bileşeni var.');
        }

        $this->tumlesikImza($imza, $this->opc->get($paketOzeti), 'Elektronik İmza', 'Paket Özeti', 'K.81');

        return true;
    }

    private function nihaiUstveri(bool $imzali): void
    {
        $ad = $this->tekBilesen(Ad::ILISKI_NIHAI_USTVERI, 'Nihai Üstveri', 'K.82', zorunlu: $imzali);

        if ($ad === null) {
            return;
        }

        if (strcasecmp($ad, Ad::PARCA_NIHAI_USTVERI) !== 0) {
            $this->hata('K.84', '"Nihai Üstveri" bileşeninin paket içindeki yeri '.Ad::PARCA_NIHAI_USTVERI.' olmalıdır.');
        }

        try {
            $nihaiUstveri = Okuyucu::nihaiUstveri($this->opc->get($ad));
        } catch (EYazismaException $hata) {
            $this->hata('K.85', $hata->getMessage());

            return;
        }

        if ($nihaiUstveri->belgeNo === '') {
            $this->hata('K.85', '"Nihai Üstveri" bileşeninde belge numarası bulunmalıdır.');
        }

        if ($nihaiUstveri->imzalar === []) {
            $this->hata('K.85', '"Nihai Üstveri" bileşeninde en az bir imza bilgisi bulunmalıdır.');
        }
    }

    private function nihaiOzet(?Ustveri $ustveri, bool $imzali): void
    {
        $ad = $this->tekBilesen(Ad::ILISKI_NIHAI_OZET, 'Nihai Özet', 'K.98', zorunlu: $imzali);

        if ($ad === null) {
            if ($imzali) {
                $this->hata(null, 'Pakette "Elektronik Mühür" bileşeni yok; paket mühürlenmemiş.');
            }

            return;
        }

        if (strcasecmp($ad, Ad::PARCA_NIHAI_OZET) !== 0) {
            $this->hata('K.38', '"Nihai Özet" bileşeninin paket içindeki yeri '.Ad::PARCA_NIHAI_OZET.' olmalıdır.');
        }

        $paketOzeti = $this->paket->bilesenAdi(Ad::ILISKI_PAKET_OZETI);
        $parafOzeti = $this->paket->bilesenAdi(Ad::ILISKI_PARAF_OZETI);

        $this->ozet($ad, 'NihaiOzet', 'Nihai Özet', $ustveri, haricilerOlabilir: false, kurallar: ['yapi' => 'K.39', 'id' => 'K.44', 'icerik' => 'K.43'], zorunlular: array_filter([
            $this->paket->bilesenAdi(Ad::ILISKI_USTVERI),
            $this->paket->bilesenAdi(Ad::ILISKI_USTYAZI),
            $this->paket->bilesenAdi(Ad::ILISKI_NIHAI_USTVERI),
            $this->paket->bilesenAdi(Ad::ILISKI_CORE),
            $paketOzeti,
            $this->paket->bilesenAdi(Ad::ILISKI_IMZA, $paketOzeti),
            $parafOzeti,
            $this->paket->bilesenAdi(Ad::ILISKI_PARAF_IMZA, $parafOzeti),
            ...$this->paket->imzaliEkBilesenleri(),
        ]));

        $muhur = $this->paket->muhur();

        if ($muhur === null) {
            $this->hata(null, 'Pakette "Elektronik Mühür" bileşeni yok; paket mühürlenmemiş.');

            return;
        }

        if (count($this->opc->relationships($ad, Ad::ILISKI_MUHUR)) > 1) {
            $this->hata(null, 'Pakette birden fazla "Elektronik Mühür" bileşeni var.');
        }

        $this->tumlesikImza($muhur, $this->opc->get($ad), 'Elektronik Mühür', 'Nihai Özet', 'K.100');
    }

    /**
     * @param  array{yapi: string, id: string, icerik: string}  $kurallar
     * @param  array<string>  $zorunlular  özeti bulunması gereken bileşenlerin adları
     */
    private function ozet(string $ad, string $kok, string $etiket, ?Ustveri $ustveri, bool $haricilerOlabilir, array $kurallar, array $zorunlular): void
    {
        try {
            $ozet = Okuyucu::ozet($this->opc->get($ad), $kok);
        } catch (EYazismaException $hata) {
            $this->hata($kurallar['yapi'], $hata->getMessage());

            return;
        }

        if ($ozet['id'] === '' || ($ustveri !== null && strcasecmp($ozet['id'], $ustveri->belgeId) !== 0)) {
            $this->hata($kurallar['id'], "\"{$etiket}\" bileşeninde özeti alınan paketin Id değeri belirtilmelidir.");
        } else {
            $this->id("{$etiket} Id", $ozet['id']);
        }

        $imzasizlar = array_map('strtolower', array_map(
            fn ($iliski) => $this->opc->resolve($iliski),
            $this->opc->relationships('', Ad::ILISKI_IMZASIZ_EK)
        ));
        $bulunanlar = [];

        foreach ($ozet['referanslar'] as $referans) {
            if (! $referans->dahiliMi()) {
                if (! $haricilerOlabilir) {
                    $this->hata('K.45', "\"{$etiket}\" bileşeni harici dosya özeti içeremez: {$referans->uri}");
                }

                $this->algoritmalar($referans, $etiket);

                continue;
            }

            $bilesen = '/'.ltrim(rawurldecode($referans->uri), '/');
            $bulunanlar[] = strtolower($bilesen);

            if (in_array(strtolower($bilesen), $imzasizlar, true)) {
                $this->hata($kurallar['icerik'], "\"{$etiket}\" bileşeni imzasız ekin özetini içeremez: {$referans->uri}");
            }

            if (! $this->algoritmalar($referans, $etiket)) {
                continue;
            }

            $icerik = $this->opc->get($bilesen);

            // Dağıtıma göre çıkarılabilen bileşenler (konulmamış ek, paraf imzası) pakette olmayabilir.
            if ($icerik === null) {
                if (in_array(strtolower($bilesen), array_map('strtolower', $zorunlular), true)) {
                    $this->hata($kurallar['icerik'], "\"{$etiket}\" bileşeninde özeti bulunan bileşen pakette yok: {$referans->uri}");
                }

                continue;
            }

            foreach ($referans->ozetler as $kalem) {
                $algoritma = OzetAlgoritmasi::tryFrom($kalem->algoritma);

                if ($algoritma !== null && ! hash_equals($algoritma->ozetle($icerik), $kalem->deger)) {
                    $this->hata($kurallar['icerik'], "\"{$etiket}\" bileşenindeki özet değeri bileşenin içeriğiyle uyuşmuyor: {$referans->uri}");

                    break;
                }
            }
        }

        foreach ($zorunlular as $zorunlu) {
            if (! in_array(strtolower($zorunlu), $bulunanlar, true)) {
                $this->hata($kurallar['icerik'], "\"{$etiket}\" bileşeninde şu bileşenin özeti yok: {$zorunlu}");
            }
        }
    }

    private function algoritmalar(OzetReferansi $referans, string $etiket): bool
    {
        $algoritmalar = array_map(fn ($ozet) => $ozet->algoritma, $referans->ozetler);
        $taninmayanlar = array_filter($algoritmalar, fn (string $algoritma) => OzetAlgoritmasi::tryFrom($algoritma) === null);

        if (count($algoritmalar) !== 2 || count(array_unique($algoritmalar)) !== 2 || ! in_array(OzetAlgoritmasi::Sha512->value, $algoritmalar, true)) {
            $this->hata(null, "\"{$etiket}\" bileşeninde her bileşen için farklı algoritmalarla iki özet bulunmalı, biri SHA-512 olmalıdır: {$referans->uri}");

            return false;
        }

        if ($taninmayanlar !== []) {
            $this->hata(null, "\"{$etiket}\" bileşeninde izin verilmeyen ya da tanınmayan özet algoritması: ".implode(', ', $taninmayanlar));

            return false;
        }

        return true;
    }

    /**
     * CAdES tümleşik imza, imzaladığı bileşeni kendi içinde taşır; rehber bu içeriğin paketteki
     * bileşenle aynı olmasını ister. İmza çözümlenmez, yalnız bileşenin imza içinde geçtiğine bakılır.
     */
    private function tumlesikImza(string $imza, ?string $icerik, string $etiket, string $ozetEtiketi, string $kural): void
    {
        if ($imza === '' || $imza[0] !== "\x30") {
            $this->hata(null, "\"{$etiket}\" bileşeni CAdES (CMS) biçiminde değil.");

            return;
        }

        if ($icerik !== null && ! str_contains($imza, $icerik)) {
            $this->uyari($kural, "\"{$etiket}\" bileşeninin içinde \"{$ozetEtiketi}\" bileşeni bulunamadı; imza tümleşik olmayabilir ya da başka bir içeriğe atılmış olabilir.");
        }
    }

    private function tekBilesen(string $iliskiTuru, string $etiket, ?string $kural, bool $zorunlu = true): ?string
    {
        $iliskiler = $this->opc->relationships('', $iliskiTuru);

        if (count($iliskiler) > 1) {
            $this->hata($kural, "Pakette birden fazla \"{$etiket}\" bileşeni var.");
        }

        $ad = $this->paket->bilesenAdi($iliskiTuru);

        if ($ad === null && ($zorunlu || $iliskiler !== [])) {
            $this->hata($kural, "Pakette \"{$etiket}\" bileşeni yok.");
        }

        return $ad;
    }

    private function id(string $etiket, string $deger): void
    {
        if (! Guid::gecerliMi($deger)) {
            $this->hata(null, "{$etiket} bir GUID olmalıdır: {$deger}");
        } elseif ($deger !== strtoupper($deger)) {
            $this->hata('K.80', "{$etiket} büyük harfle yazılmalıdır: {$deger}");
        }
    }

    private function hata(?string $kural, string $mesaj): void
    {
        $this->bulgular[] = new Bulgu(Seviye::Hata, $mesaj, $kural);
    }

    private function uyari(?string $kural, string $mesaj): void
    {
        $this->bulgular[] = new Bulgu(Seviye::Uyari, $mesaj, $kural);
    }
}
