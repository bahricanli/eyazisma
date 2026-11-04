<?php

namespace BahriCanli\EYazisma\Cades;

use BahriCanli\EYazisma\Exceptions\EYazismaException;
use DateTimeImmutable;

/**
 * İmza bloğundan çıkarılan imzacı sertifikasının özeti. Zincir ve iptal durumu denetlenmemiştir.
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
}
