<?php

namespace BahriCanli\EYazisma\Denetim;

use BahriCanli\EYazisma\Ag\AkisHttpIstemci;
use BahriCanli\EYazisma\Cades\Cades;
use BahriCanli\EYazisma\Cades\Der;
use BahriCanli\EYazisma\Cades\Sertifika;
use BahriCanli\EYazisma\Contracts\HttpIstemci;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use DateTimeImmutable;
use Throwable;

/**
 * Sertifikayı güvenilir köklere kadar zincirler, geçerlilik süresine bakar ve iptal durumunu
 * önce OCSP, ulaşılamazsa CRL ile denetler. Ara sertifikalar imza bloğundan, verilen ek
 * sertifikalardan ya da sertifikadaki "CA Issuers" adresinden alınır.
 *
 * Güvenilir kökler uygulamanın verdiği listedir (örneğin Kamu SM, TÜRKTRUST, E-Tuğra kök
 * sertifikaları); kütüphane kök sertifika taşımaz. İptal denetimi yalnız yaprak sertifika
 * içindir; ara sertifikaların iptali denetlenmez.
 */
final class SertifikaDenetleyici
{
    private const OCSP_BASIC = '1.3.6.1.5.5.7.48.1.1';

    private const SHA1 = '1.3.14.3.2.26';

    /** @var list<Sertifika> */
    private readonly array $kokler;

    /** @var array<string, string> */
    private array $onbellek = [];

    private readonly HttpIstemci $istemci;

    /**
     * @param  list<string>  $kokler  güvenilir kök (ve isteğe bağlı ara) sertifikalar, PEM
     */
    public function __construct(array $kokler, ?HttpIstemci $istemci = null)
    {
        $this->kokler = array_map(fn (string $pem) => Sertifika::pemden($pem), $kokler);
        $this->istemci = $istemci ?? new AkisHttpIstemci;
    }

    /**
     * PEM dosyalarının bulunduğu dizinden (".pem", ".crt", ".cer"; DER de olur) kökleri okur.
     */
    public static function dizinden(string $dizin, ?HttpIstemci $istemci = null): self
    {
        $kokler = [];

        foreach (glob(rtrim($dizin, '/').'/*.{pem,crt,cer}', GLOB_BRACE) ?: [] as $dosya) {
            $icerik = (string) file_get_contents($dosya);

            if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $icerik, $eslesmeler)) {
                array_push($kokler, ...$eslesmeler[0]);
            } elseif ($icerik !== '') {
                $kokler[] = Cades::pem($icerik);
            }
        }

        return new self($kokler, $istemci);
    }

    public function kokSayisi(): int
    {
        return count($this->kokler);
    }

    /**
     * @param  list<Sertifika>  $araSertifikalar
     */
    public function denetle(Sertifika $sertifika, array $araSertifikalar = [], ?DateTimeImmutable $zaman = null): SertifikaDenetimi
    {
        $zaman ??= new DateTimeImmutable;
        $hatalar = [];

        [$zincir, $zincirGecerli, $zincirHatasi] = $this->zincirle($sertifika, $araSertifikalar);

        if ($zincirHatasi !== null) {
            $hatalar[] = $zincirHatasi;
        }

        $sureGecerli = array_reduce($zincir, fn (bool $sonuc, Sertifika $halka) => $sonuc && $halka->gecerli($zaman), true);
        $yayinci = $zincir[1] ?? null;
        $iptal = [IptalDurumu::Bilinmiyor, null, null, null];

        if ($yayinci !== null) {
            try {
                $iptal = $this->ocsp($sertifika, $yayinci);
            } catch (Throwable $hata) {
                $hatalar[] = 'OCSP: '.$hata->getMessage();

                try {
                    $iptal = $this->crl($sertifika, $yayinci, $zaman);
                } catch (Throwable $hata) {
                    $hatalar[] = 'CRL: '.$hata->getMessage();
                }
            }
        }

        [$durum, $kaynak, $iptalTarihi, $kanit] = $iptal;

        if ($durum === IptalDurumu::IptalEdildi && $iptalTarihi !== null && $iptalTarihi > $zaman) {
            // İmzadan sonra iptal edilen sertifikayla atılmış imza geçerliliğini korur.
            $durum = IptalDurumu::Gecerli;
        }

        return new SertifikaDenetimi($sertifika, $zincir, $zincirGecerli, $sureGecerli, $durum, $kaynak, $iptalTarihi, $zaman, $hatalar, $kanit);
    }

    /**
     * @param  list<Sertifika>  $araSertifikalar
     * @return array{0: list<Sertifika>, 1: bool, 2: ?string}
     */
    private function zincirle(Sertifika $sertifika, array $araSertifikalar): array
    {
        $zincir = [$sertifika];
        $halka = $sertifika;

        for ($derinlik = 0; $derinlik < 8; $derinlik++) {
            foreach ($this->kokler as $kok) {
                if ($kok->konuDer() === $halka->yayinciDer() && $this->imzaladi($kok, $halka)) {
                    if ($kok->der() !== $halka->der()) {
                        $zincir[] = $kok;
                    }

                    return [$zincir, true, null];
                }
            }

            if ($halka->kendindenImzali()) {
                return [$zincir, false, $halka->adSoyad.' güvenilir kökler arasında değil.'];
            }

            $yayinci = $this->yayinciBul($halka, $araSertifikalar);

            if ($yayinci === null) {
                return [$zincir, false, $halka->adSoyad.' sertifikasının yayıncısı bulunamadı.'];
            }

            $zincir[] = $halka = $yayinci;
        }

        return [$zincir, false, 'Sertifika zinciri çok uzun.'];
    }

    /**
     * @param  list<Sertifika>  $araSertifikalar
     */
    private function yayinciBul(Sertifika $halka, array $araSertifikalar): ?Sertifika
    {
        foreach ($araSertifikalar as $aday) {
            if ($aday->konuDer() === $halka->yayinciDer() && $this->imzaladi($aday, $halka)) {
                return $aday;
            }
        }

        foreach ($halka->yayinciAdresleri() as $adres) {
            try {
                $icerik = $this->getir($adres);
                $pemler = preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $icerik, $eslesmeler) ? $eslesmeler[0] : [Cades::pem($icerik)];

                foreach ($pemler as $pem) {
                    $aday = Sertifika::pemden($pem);

                    if ($aday->konuDer() === $halka->yayinciDer() && $this->imzaladi($aday, $halka)) {
                        return $aday;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function imzaladi(Sertifika $yayinci, Sertifika $sertifika): bool
    {
        $sonuc = openssl_x509_verify($sertifika->pem, $yayinci->pem);

        while (openssl_error_string() !== false);

        return $sonuc === 1;
    }

    /**
     * @return array{0: IptalDurumu, 1: string, 2: ?DateTimeImmutable, 3: string}
     */
    private function ocsp(Sertifika $sertifika, Sertifika $yayinci): array
    {
        $adres = $sertifika->ocspAdresleri()[0] ?? throw new EYazismaException('sertifikada OCSP adresi yok.');

        $sertifikaKimligi = Der::kodla(0x30,
            Der::kodla(0x30, Der::oid(self::SHA1).Der::kodla(0x05, ''))
            .Der::kodla(0x04, sha1($sertifika->yayinciDer(), true))
            .Der::kodla(0x04, sha1($yayinci->acikAnahtarBitleri(), true))
            .Der::kodla(0x02, $sertifika->seriBaytlari()),
        );

        $istek = Der::kodla(0x30, Der::kodla(0x30, Der::kodla(0x30, Der::kodla(0x30, $sertifikaKimligi))));
        $yanit = $this->istemci->gonder($adres, $istek, ['Content-Type' => 'application/ocsp-request', 'Accept' => 'application/ocsp-response']);

        $ogeler = Der::ogeler(Der::tek($yanit)['icerik']);

        if (ord($ogeler[0]['icerik'] ?? "\x01") !== 0 || ! isset($ogeler[1])) {
            throw new EYazismaException('OCSP sunucusu isteği reddetti.');
        }

        $baytlar = Der::ogeler(Der::tek($ogeler[1]['icerik'])['icerik']);

        if (Der::oidCoz($baytlar[0]['ham']) !== self::OCSP_BASIC) {
            throw new EYazismaException('tanınmayan OCSP yanıt türü.');
        }

        $temel = Der::ogeler(Der::tek($baytlar[1]['icerik'])['icerik']);
        [$tbs, $algoritma, $imza] = $temel;
        $imzalayanlar = isset($temel[3]) ? array_map(fn (array $oge) => Sertifika::pemden(Cades::pem($oge['ham'])), Der::ogeler(Der::tek($temel[3]['icerik'])['icerik'])) : [];

        $this->ocspImzasiniDenetle($tbs['ham'], $algoritma['ham'], substr($imza['icerik'], 1), $yayinci, $imzalayanlar);

        $veri = Der::ogeler($tbs['icerik']);

        if ($veri[0]['etiket'] === 0xA0) {
            array_shift($veri);
        }

        foreach (Der::ogeler($veri[2]['icerik']) as $tek) {
            $alanlar = Der::ogeler($tek['icerik']);
            $kimlik = Der::ogeler($alanlar[0]['icerik']);

            if (ltrim($kimlik[3]['icerik'], "\0") !== ltrim($sertifika->seriBaytlari(), "\0")) {
                continue;
            }

            if ($alanlar[2]['etiket'] === 0x18) {
                $sonraki = null;

                foreach (array_slice($alanlar, 3) as $alan) {
                    if ($alan['etiket'] === 0xA0) {
                        $sonraki = Der::zaman(Der::tek($alan['icerik']));
                    }
                }

                if ($sonraki !== null && $sonraki < new DateTimeImmutable('-1 hour')) {
                    throw new EYazismaException('OCSP yanıtı eskimiş.');
                }
            }

            return match ($alanlar[1]['etiket']) {
                0x80 => [IptalDurumu::Gecerli, 'ocsp', null, $yanit],
                0xA1 => [IptalDurumu::IptalEdildi, 'ocsp', Der::zaman(Der::ogeler($alanlar[1]['icerik'])[0]), $yanit],
                default => [IptalDurumu::Bilinmiyor, 'ocsp', null, $yanit],
            };
        }

        throw new EYazismaException('OCSP yanıtı bu sertifikayı içermiyor.');
    }

    /**
     * Yanıtı ya yayıncı ya da yayıncının OCSP için yetkilendirdiği sertifika imzalamış olmalıdır.
     *
     * @param  list<Sertifika>  $imzalayanlar
     */
    private function ocspImzasiniDenetle(string $tbs, string $algoritma, string $imza, Sertifika $yayinci, array $imzalayanlar): void
    {
        $adaylar = [$yayinci];

        foreach ($imzalayanlar as $aday) {
            if ($this->imzaladi($yayinci, $aday) && str_contains((string) (openssl_x509_parse($aday->pem)['extensions']['extendedKeyUsage'] ?? ''), 'OCSP Signing')) {
                $adaylar[] = $aday;
            }
        }

        $ozet = self::ozetAlgoritmasi($algoritma);

        foreach ($adaylar as $aday) {
            $sonuc = openssl_verify($tbs, $imza, $aday->pem, $ozet);

            while (openssl_error_string() !== false);

            if ($sonuc === 1) {
                return;
            }
        }

        throw new EYazismaException('OCSP yanıtının imzası doğrulanamadı.');
    }

    /**
     * @return array{0: IptalDurumu, 1: string, 2: ?DateTimeImmutable, 3: string}
     */
    private function crl(Sertifika $sertifika, Sertifika $yayinci, DateTimeImmutable $zaman): array
    {
        $adres = $sertifika->crlAdresleri()[0] ?? throw new EYazismaException('sertifikada CRL adresi yok.');
        $crl = $this->getir($adres);

        if (str_contains($crl, '-----BEGIN X509 CRL-----')) {
            $crl = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $crl));
        }

        [$tbs, $algoritma, $imza] = Der::ogeler(Der::tek($crl)['icerik']);
        $sonuc = openssl_verify($tbs['ham'], substr($imza['icerik'], 1), $yayinci->pem, self::ozetAlgoritmasi($algoritma['ham']));

        while (openssl_error_string() !== false);

        if ($sonuc !== 1) {
            throw new EYazismaException('CRL imzası doğrulanamadı.');
        }

        $alanlar = Der::ogeler($tbs['icerik']);

        if ($alanlar[0]['etiket'] === 0x02) {
            array_shift($alanlar);
        }

        // signature, issuer, thisUpdate, nextUpdate?, revokedCertificates?
        $seri = ltrim($sertifika->seriBaytlari(), "\0");

        foreach (array_slice($alanlar, 3) as $alan) {
            if ($alan['etiket'] !== 0x30) {
                continue;
            }

            foreach (Der::ogeler($alan['icerik']) as $kayit) {
                $parcalar = Der::ogeler($kayit['icerik']);

                if (ltrim($parcalar[0]['icerik'], "\0") === $seri) {
                    return [IptalDurumu::IptalEdildi, 'crl', Der::zaman($parcalar[1]), $crl];
                }
            }
        }

        return [IptalDurumu::Gecerli, 'crl', null, $crl];
    }

    private static function ozetAlgoritmasi(string $algoritmaKimligi): int|string
    {
        $oid = Der::oidCoz(Der::ogeler(Der::tek($algoritmaKimligi)['icerik'])[0]['ham']);

        return match ($oid) {
            '1.2.840.113549.1.1.5', '1.2.840.10045.4.1' => OPENSSL_ALGO_SHA1,
            '1.2.840.113549.1.1.11', '1.2.840.10045.4.3.2' => OPENSSL_ALGO_SHA256,
            '1.2.840.113549.1.1.12', '1.2.840.10045.4.3.3' => OPENSSL_ALGO_SHA384,
            '1.2.840.113549.1.1.13', '1.2.840.10045.4.3.4' => OPENSSL_ALGO_SHA512,
            default => throw new EYazismaException("desteklenmeyen imza algoritması ($oid)."),
        };
    }

    private function getir(string $adres): string
    {
        return $this->onbellek[$adres] ??= $this->istemci->getir($adres);
    }
}
