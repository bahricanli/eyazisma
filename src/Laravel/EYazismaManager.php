<?php

namespace BahriCanli\EYazisma\Laravel;

use BahriCanli\EYazisma\Enums\OzetAlgoritmasi;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Paket;
use BahriCanli\EYazisma\PaketOlusturucu;

/**
 * Yapılandırmadaki varsayılanlarla paket başlatır.
 */
final class EYazismaManager
{
    /**
     * @param  array<string, mixed>  $config  config/eyazisma.php içeriği
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Oluşturan, doğrulama adresi, dil, sürüm ve özet algoritmaları yapılandırmadan doldurulmuş paket oluşturucu.
     */
    public function yeni(): PaketOlusturucu
    {
        $olusturucu = Paket::yeni()->dil($this->config['dil'] ?? 'tur');

        if ($olusturan = $this->olusturan()) {
            $olusturucu->olusturan($olusturan);
        }

        if (($this->config['dogrulama_adresi'] ?? '') !== '') {
            $olusturucu->dogrulamaAdresi($this->config['dogrulama_adresi']);
        }

        if (($this->config['surum'] ?? '') !== '') {
            $olusturucu->surum($this->config['surum']);
        }

        if (is_array($this->config['ozet_algoritmalari'] ?? null)) {
            $olusturucu->ozetAlgoritmalari(...array_map($this->algoritma(...), array_values($this->config['ozet_algoritmalari'])));
        }

        return $olusturucu;
    }

    public function ac(string $yol): Paket
    {
        return Paket::ac($yol);
    }

    public function icerikten(string $icerik): Paket
    {
        return Paket::icerikten($icerik);
    }

    /**
     * Yapılandırmadaki oluşturan; kimlik tanımlı değilse null.
     */
    public function olusturan(): ?Taraf
    {
        $olusturan = $this->config['olusturan'] ?? [];

        if (($olusturan['kimlik'] ?? '') === '') {
            return null;
        }

        $iletisim = array_filter($olusturan['iletisim'] ?? [], fn ($deger) => $deger !== null && $deger !== '');
        $iletisimBilgisi = $iletisim === [] ? null : new IletisimBilgisi(
            telefon: $iletisim['telefon'] ?? null,
            ePosta: $iletisim['e_posta'] ?? null,
            kepAdresi: $iletisim['kep_adresi'] ?? null,
            faks: $iletisim['faks'] ?? null,
            webAdresi: $iletisim['web_adresi'] ?? null,
            adres: $iletisim['adres'] ?? null,
            il: $iletisim['il'] ?? null,
            ilce: $iletisim['ilce'] ?? null,
            ulke: $iletisim['ulke'] ?? null,
        );

        return match ($olusturan['tur'] ?? 'tuzel') {
            'tuzel' => new TuzelSahis(
                new Tanimlayici((string) $olusturan['kimlik'], $olusturan['kimlik_semasi'] ?? 'MERSIS'),
                $olusturan['ad'] ?? null,
                $iletisimBilgisi,
            ),
            'kurum' => new KurumKurulus((string) $olusturan['kimlik'], $olusturan['ad'] ?? null, $iletisimBilgisi),
            default => throw new EYazismaException("eyazisma.olusturan.tur \"tuzel\" ya da \"kurum\" olmalıdır: {$olusturan['tur']}"),
        };
    }

    private function algoritma(string $ad): OzetAlgoritmasi
    {
        return match (strtolower($ad)) {
            'sha256' => OzetAlgoritmasi::Sha256,
            'sha384' => OzetAlgoritmasi::Sha384,
            'sha512' => OzetAlgoritmasi::Sha512,
            default => throw new EYazismaException("Bilinmeyen özet algoritması: {$ad}"),
        };
    }
}
