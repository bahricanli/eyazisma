<?php

namespace BahriCanli\EYazisma\Cades;

use BahriCanli\EYazisma\Exceptions\EYazismaException;
use DateTimeImmutable;

/**
 * İmza bloğundan çıkarılan sertifikanın özeti. Zincir ve iptal durumunu SertifikaDenetleyici
 * denetler; bu sınıf yalnız okur.
 */
final class Sertifika
{
    public function __construct(
        public readonly string $adSoyad,
        public readonly ?string $seriNo,
        public readonly string $yayinci,
        public readonly DateTimeImmutable $gecerlilikBaslangici,
        public readonly DateTimeImmutable $gecerlilikBitisi,
        public readonly string $pem,
    ) {
    }

    public static function pemden(string $pem): self
    {
        $bilgi = openssl_x509_parse($pem) ?: throw new EYazismaException('İmzacı sertifikası okunamadı.');
        $ad = fn (array $dn, string $alan) => is_array($dn[$alan] ?? null) ? implode(' ', $dn[$alan]) : ($dn[$alan] ?? null);

        return new self(
            adSoyad: (string) ($ad($bilgi['subject'], 'CN') ?? ''),
            seriNo: $ad($bilgi['subject'], 'serialNumber'),
            yayinci: (string) ($ad($bilgi['issuer'], 'CN') ?? $ad($bilgi['issuer'], 'O') ?? ''),
            gecerlilikBaslangici: (new DateTimeImmutable)->setTimestamp($bilgi['validFrom_time_t']),
            gecerlilikBitisi: (new DateTimeImmutable)->setTimestamp($bilgi['validTo_time_t']),
            pem: $pem,
        );
    }

    /**
     * Nitelikli sertifikalarda konu seri numarası T.C. kimlik numarasıdır; 11 hane değilse null.
     */
    public function tckn(): ?string
    {
        $rakamlar = preg_replace('/\D+/', '', (string) $this->seriNo);

        return strlen($rakamlar) === 11 ? $rakamlar : null;
    }

    public function der(): string
    {
        return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $this->pem));
    }

    /**
     * Seri numarasının ham (büyük-uçlu) baytları.
     */
    public function seriBaytlari(): string
    {
        return $this->tbs()[1]['icerik'];
    }

    public function seriNoHex(): string
    {
        return strtoupper(bin2hex(ltrim($this->seriBaytlari(), "\0")));
    }

    /**
     * Yayıncı adının DER kodlaması; OCSP isteği ve zincir kurmak için.
     */
    public function yayinciDer(): string
    {
        return $this->tbs()[3]['ham'];
    }

    public function konuDer(): string
    {
        return $this->tbs()[5]['ham'];
    }

    /**
     * Açık anahtarın bit dizisi (kullanılmayan bit baytı hariç).
     */
    public function acikAnahtarBitleri(): string
    {
        $bitler = Der::ogeler($this->tbs()[6]['icerik'])[1]['icerik'];

        return substr($bitler, 1);
    }

    public function kendindenImzali(): bool
    {
        return $this->yayinciDer() === $this->konuDer();
    }

    public function anahtarKimligi(): ?string
    {
        $hex = $this->uzantilar()['subjectKeyIdentifier'] ?? null;

        return $hex === null ? null : hex2bin(str_replace(':', '', trim($hex)));
    }

    /**
     * @return list<string>
     */
    public function ocspAdresleri(): array
    {
        return self::adresler($this->uzantilar()['authorityInfoAccess'] ?? '', 'OCSP');
    }

    /**
     * Yayıncı sertifikasının indirilebileceği adresler.
     *
     * @return list<string>
     */
    public function yayinciAdresleri(): array
    {
        return self::adresler($this->uzantilar()['authorityInfoAccess'] ?? '', 'CA Issuers');
    }

    /**
     * @return list<string>
     */
    public function crlAdresleri(): array
    {
        preg_match_all('#URI:(https?://\S+)#', $this->uzantilar()['crlDistributionPoints'] ?? '', $eslesmeler);

        return array_values(array_unique($eslesmeler[1]));
    }

    public function gecerli(\DateTimeInterface $zaman): bool
    {
        return $zaman >= $this->gecerlilikBaslangici && $zaman <= $this->gecerlilikBitisi;
    }

    /**
     * @return list<string>
     */
    private static function adresler(string $metin, string $tur): array
    {
        preg_match_all('#'.preg_quote($tur, '#').' - URI:(https?://\S+)#', $metin, $eslesmeler);

        return array_values(array_unique($eslesmeler[1]));
    }

    /**
     * @return array<string, string>
     */
    private function uzantilar(): array
    {
        return openssl_x509_parse($this->pem)['extensions'] ?? [];
    }

    /**
     * TBSCertificate alanları; sürüm alanı yoksa da aynı sırayla döner.
     *
     * @return list<array{etiket: int, ham: string, icerik: string}>
     */
    private function tbs(): array
    {
        $sertifika = Der::ogeler(Der::tek($this->der())['icerik']);
        $alanlar = Der::ogeler($sertifika[0]['icerik']);

        if ($alanlar[0]['etiket'] !== 0xA0) {
            array_unshift($alanlar, ['etiket' => 0xA0, 'ham' => '', 'icerik' => '']);
        }

        return $alanlar;
    }
}
