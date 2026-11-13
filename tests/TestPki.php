<?php

namespace BahriCanli\EYazisma\Tests;

use BahriCanli\EYazisma\Cades\Der;
use RuntimeException;

/**
 * Sınamalar için küçük bir sertifika altyapısı: kök, ara sertifika, imzacı ve zaman damgası
 * sunucusu sertifikaları; OCSP yanıtı, CRL ve zaman damgası üretir. Nitelikli değildir.
 */
final class TestPki
{
    public mixed $kokAnahtar;

    public string $kok;

    public mixed $araAnahtar;

    public string $ara;

    public mixed $tsaAnahtar;

    public string $tsa;

    private string $ayar;

    public function __construct()
    {
        $this->ayar = tempnam(sys_get_temp_dir(), 'pki');
        file_put_contents($this->ayar, <<<'CNF'
            [req]
            distinguished_name = dn
            [dn]
            [ca]
            basicConstraints = critical, CA:true
            keyUsage = critical, keyCertSign, cRLSign
            subjectKeyIdentifier = hash
            [yaprak]
            basicConstraints = CA:false
            keyUsage = critical, digitalSignature, nonRepudiation
            subjectKeyIdentifier = hash
            authorityInfoAccess = OCSP;URI:http://ocsp.test/, caIssuers;URI:http://depo.test/ara.crt
            crlDistributionPoints = URI:http://crl.test/ara.crl
            [tsa]
            basicConstraints = CA:false
            keyUsage = critical, digitalSignature
            extendedKeyUsage = critical, timeStamping
            subjectKeyIdentifier = hash
            CNF);

        [$this->kokAnahtar, $this->kok] = $this->sertifika(['commonName' => 'Test Kök'], null, null, 'ca', 1);
        [$this->araAnahtar, $this->ara] = $this->sertifika(['commonName' => 'Test Ara'], $this->kok, $this->kokAnahtar, 'ca', 2);
        [$this->tsaAnahtar, $this->tsa] = $this->sertifika(['commonName' => 'Test Zaman Damgası'], $this->kok, $this->kokAnahtar, 'tsa', 3);
    }

    /**
     * @param  array<string, string>  $konu
     * @return array{0: mixed, 1: string}
     */
    public function imzaci(array $konu, int $seri = 1000): array
    {
        return $this->sertifika($konu, $this->ara, $this->araAnahtar, 'yaprak', $seri);
    }

    public function imzala(string $icerik, string $sertifika, mixed $anahtar): string
    {
        $girdi = tempnam(sys_get_temp_dir(), 'pki');
        $cikti = tempnam(sys_get_temp_dir(), 'pki');
        file_put_contents($girdi, $icerik);

        try {
            if (! openssl_cms_sign($girdi, $cikti, $sertifika, $anahtar, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                throw new RuntimeException(openssl_error_string());
            }

            return file_get_contents($cikti);
        } finally {
            @unlink($girdi);
            @unlink($cikti);
        }
    }

    /**
     * OCSP isteğine ara sertifika adına yanıt verir; $iptal verilirse sertifika iptal edilmiş görünür.
     */
    public function ocspYaniti(string $istek, ?\DateTimeImmutable $iptal = null): string
    {
        $kimlik = Der::tek(Der::tek(Der::tek(Der::tek($istek)["icerik"])["icerik"])["icerik"])["icerik"];
        $simdi = gmdate('YmdHis').'Z';
        $durum = $iptal === null ? "\x80\x00" : Der::kodla(0xA1, Der::kodla(0x18, $iptal->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis').'Z'));

        $tbs = Der::kodla(0x30,
            Der::kodla(0xA1, $this->konu($this->ara))
            .Der::kodla(0x18, $simdi)
            .Der::kodla(0x30, Der::kodla(0x30, $kimlik.$durum.Der::kodla(0x18, $simdi))),
        );

        openssl_sign($tbs, $imza, $this->araAnahtar, OPENSSL_ALGO_SHA256);
        $temel = Der::kodla(0x30, $tbs.self::rsaSha256().Der::kodla(0x03, "\0".$imza));

        return Der::kodla(0x30, "\x0A\x01\x00".Der::kodla(0xA0, Der::kodla(0x30, Der::oid('1.3.6.1.5.5.7.48.1.1').Der::kodla(0x04, $temel))));
    }

    /**
     * @param  list<int>  $iptalEdilenler  seri numaraları
     */
    public function crl(array $iptalEdilenler): string
    {
        $kayitlar = implode('', array_map(fn (int $seri) => Der::kodla(0x30, Der::tamsayi(self::baytlar($seri)).Der::kodla(0x17, gmdate('ymdHis', time() - 3600).'Z')), $iptalEdilenler));

        $tbs = Der::kodla(0x30,
            Der::tamsayi("\x01")
            .self::rsaSha256()
            .$this->konu($this->ara)
            .Der::kodla(0x17, gmdate('ymdHis', time() - 60).'Z')
            .Der::kodla(0x17, gmdate('ymdHis', time() + 86400).'Z')
            .($kayitlar !== '' ? Der::kodla(0x30, $kayitlar) : ''),
        );

        openssl_sign($tbs, $imza, $this->araAnahtar, OPENSSL_ALGO_SHA256);

        return Der::kodla(0x30, $tbs.self::rsaSha256().Der::kodla(0x03, "\0".$imza));
    }

    /**
     * Verilen RFC 3161 isteğine test damga sunucusu adına yanıt verir.
     */
    public function damgaYaniti(string $istek): string
    {
        $alanlar = Der::ogeler(Der::tek($istek)['icerik']);
        $nonce = '';

        foreach ($alanlar as $alan) {
            if ($alan['etiket'] === 0x02 && $alan !== $alanlar[0]) {
                $nonce = $alan['ham'];
            }
        }

        $tstInfo = Der::kodla(0x30,
            Der::tamsayi("\x01")
            .Der::oid('1.3.6.1.4.1.99999.1')
            .$alanlar[1]['ham']
            .Der::tamsayi(random_bytes(8))
            .Der::kodla(0x18, gmdate('YmdHis').'Z')
            .$nonce,
        );

        $dizin = sys_get_temp_dir();
        $girdi = tempnam($dizin, 'pki');
        $cikti = tempnam($dizin, 'pki');
        $sertifika = tempnam($dizin, 'pki');
        $anahtar = tempnam($dizin, 'pki');
        file_put_contents($girdi, $tstInfo);
        file_put_contents($sertifika, $this->tsa);
        openssl_pkey_export_to_file($this->tsaAnahtar, $anahtar);

        try {
            exec(sprintf(
                'openssl cms -sign -binary -nodetach -econtent_type 1.2.840.113549.1.9.16.1.4 -md sha256 -in %s -signer %s -inkey %s -outform DER -out %s 2>&1',
                escapeshellarg($girdi), escapeshellarg($sertifika), escapeshellarg($anahtar), escapeshellarg($cikti),
            ), $cikis, $kod);

            if ($kod !== 0) {
                throw new RuntimeException('openssl cms: '.implode("\n", $cikis));
            }

            return Der::kodla(0x30, Der::kodla(0x30, Der::tamsayi("\x00")).file_get_contents($cikti));
        } finally {
            array_map('unlink', [$girdi, $cikti, $sertifika, $anahtar]);
        }
    }

    public static function opensslVar(): bool
    {
        exec('openssl version 2>&1', $cikis, $kod);

        return $kod === 0;
    }

    /**
     * @param  array<string, string>  $konu
     * @return array{0: mixed, 1: string}
     */
    private function sertifika(array $konu, ?string $yayinci, mixed $yayinciAnahtar, string $bolum, int $seri): array
    {
        $anahtar = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $secenekler = ['config' => $this->ayar, 'digest_alg' => 'sha256', 'x509_extensions' => $bolum];
        $istek = openssl_csr_new($konu, $anahtar, $secenekler);
        $sertifika = openssl_csr_sign($istek, $yayinci, $yayinciAnahtar ?? $anahtar, 365, $secenekler, $seri);
        openssl_x509_export($sertifika, $pem);

        return [$anahtar, $pem];
    }

    private function konu(string $pem): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        $tbs = Der::ogeler(Der::ogeler(Der::tek($der)['icerik'])[0]['icerik']);

        return $tbs[5]['ham'];
    }

    private static function rsaSha256(): string
    {
        return Der::kodla(0x30, Der::oid('1.2.840.113549.1.1.11').Der::kodla(0x05, ''));
    }

    private static function baytlar(int $sayi): string
    {
        $hex = dechex($sayi);

        return hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
    }
}
