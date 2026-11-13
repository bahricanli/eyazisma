<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Cades\Cades;
use BahriCanli\EYazisma\Cades\Sertifika;
use BahriCanli\EYazisma\Denetim\ImzaDenetleyici;
use BahriCanli\EYazisma\Denetim\IptalDurumu;
use BahriCanli\EYazisma\Denetim\SertifikaDenetleyici;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Tests\SahteHttpIstemci;
use BahriCanli\EYazisma\Tests\TestPki;
use BahriCanli\EYazisma\ZamanDamgasi\DamgaBilgisi;
use BahriCanli\EYazisma\ZamanDamgasi\KamuSmKimlik;
use BahriCanli\EYazisma\ZamanDamgasi\Rfc3161;
use PHPUnit\Framework\TestCase;

class DenetimTest extends TestCase
{
    private const ICERIK = '<PaketOzeti Id="1">özet</PaketOzeti>';

    private static ?TestPki $pki = null;

    protected function setUp(): void
    {
        self::$pki ??= new TestPki;
    }

    private function http(?\DateTimeImmutable $iptal = null, bool $ocspYok = false, array $crlIptal = []): SahteHttpIstemci
    {
        $pki = self::$pki;

        return new SahteHttpIstemci(array_filter([
            'http://ocsp.test/' => $ocspYok ? null : fn (string $istek) => $pki->ocspYaniti($istek, $iptal),
            'http://crl.test/' => $pki->crl($crlIptal),
            'http://depo.test/ara.crt' => $pki->ara,
            'http://tsa.test/' => fn (string $istek) => $pki->damgaYaniti($istek),
        ]));
    }

    public function test_valid_certificate_chains_to_root_and_passes_ocsp(): void
    {
        [, $pem] = self::$pki->imzaci(['commonName' => 'AYŞE YILMAZ', 'serialNumber' => '12345678950'], 1001);
        $http = $this->http();

        $sonuc = (new SertifikaDenetleyici([self::$pki->kok], $http))->denetle(Sertifika::pemden($pem));

        $this->assertTrue($sonuc->gecerli(), implode(' ', $sonuc->hatalar));
        $this->assertSame('ocsp', $sonuc->iptalKaynagi);
        $this->assertSame(['AYŞE YILMAZ', 'Test Ara', 'Test Kök'], $sonuc->dizi()['zincir']);
        $this->assertContains('http://depo.test/ara.crt', array_column($http->istekler, 'adres'), 'Ara sertifika AIA adresinden indirilmeli.');
    }

    public function test_revoked_certificate_is_reported(): void
    {
        [, $pem] = self::$pki->imzaci(['commonName' => 'MEHMET KAYA'], 1002);

        $sonuc = (new SertifikaDenetleyici([self::$pki->kok], $this->http(new \DateTimeImmutable('-1 day'))))->denetle(Sertifika::pemden($pem));

        $this->assertSame(IptalDurumu::IptalEdildi, $sonuc->iptal);
        $this->assertTrue($sonuc->reddedilmeli());
    }

    public function test_revocation_after_signing_time_keeps_signature_valid(): void
    {
        [, $pem] = self::$pki->imzaci(['commonName' => 'MEHMET KAYA'], 1003);

        $sonuc = (new SertifikaDenetleyici([self::$pki->kok], $this->http(new \DateTimeImmutable('-1 hour'))))
            ->denetle(Sertifika::pemden($pem), [], new \DateTimeImmutable('-2 hours'));

        $this->assertSame(IptalDurumu::Gecerli, $sonuc->iptal);
    }

    public function test_crl_is_used_when_ocsp_is_unreachable(): void
    {
        [, $gecerli] = self::$pki->imzaci(['commonName' => 'A'], 1004);
        [, $iptalli] = self::$pki->imzaci(['commonName' => 'B'], 1005);
        $denetleyici = new SertifikaDenetleyici([self::$pki->kok], $this->http(ocspYok: true, crlIptal: [1005]));

        $birinci = $denetleyici->denetle(Sertifika::pemden($gecerli));
        $ikinci = $denetleyici->denetle(Sertifika::pemden($iptalli));

        $this->assertSame([IptalDurumu::Gecerli, 'crl'], [$birinci->iptal, $birinci->iptalKaynagi]);
        $this->assertSame(IptalDurumu::IptalEdildi, $ikinci->iptal);
    }

    public function test_unknown_root_is_not_trusted(): void
    {
        [, $pem] = self::$pki->imzaci(['commonName' => 'A'], 1006);
        $baska = new TestPki;

        $sonuc = (new SertifikaDenetleyici([$baska->kok], $this->http()))->denetle(Sertifika::pemden($pem));

        $this->assertFalse($sonuc->zincirGecerli);
        $this->assertTrue($sonuc->reddedilmeli());
    }

    public function test_unreachable_revocation_is_unknown_not_rejected(): void
    {
        [, $pem] = self::$pki->imzaci(['commonName' => 'A'], 1007);
        $http = new SahteHttpIstemci(['http://depo.test/ara.crt' => self::$pki->ara]);

        $sonuc = (new SertifikaDenetleyici([self::$pki->kok], $http))->denetle(Sertifika::pemden($pem));

        $this->assertSame(IptalDurumu::Bilinmiyor, $sonuc->iptal);
        $this->assertFalse($sonuc->gecerli());
        $this->assertFalse($sonuc->reddedilmeli());
    }

    public function test_rfc3161_client_sends_basic_auth_and_checks_the_token(): void
    {
        $this->opensslGerekli();
        $http = $this->http();
        $ozet = hash('sha256', 'imza', true);

        $damga = (new Rfc3161('http://tsa.test/', 'kullanici', 'parola', 'basic', $http))->damga($ozet);
        $bilgi = DamgaBilgisi::coz($damga);

        $this->assertSame($ozet, $bilgi->ozet);
        $this->assertSame('Test Zaman Damgası', $bilgi->sunucu?->adSoyad);
        $this->assertEqualsWithDelta(time(), $bilgi->zaman->getTimestamp(), 5);
        $this->assertSame('Basic '.base64_encode('kullanici:parola'), $http->istekler[0]['basliklar']['Authorization']);
        $this->assertSame('application/timestamp-query', $http->istekler[0]['basliklar']['Content-Type']);
    }

    public function test_rejected_timestamp_request_reports_the_reason(): void
    {
        $red = "\x30\x0C\x30\x0A\x02\x01\x02\x30\x05\x0C\x03yok";
        $http = new SahteHttpIstemci(['http://tsa.test/' => $red]);

        $this->expectExceptionMessage('reddetti: yok');
        (new Rfc3161('http://tsa.test/', 'a', 'b', 'basic', $http))->damga(hash('sha256', 'x', true));
    }

    public function test_kamu_sm_identity_header_decrypts_to_the_digest(): void
    {
        $ozet = hash('sha256', 'imza', true);
        $der = hex2bin(KamuSmKimlik::baslik('12345', 'gizli', $ozet));
        $alanlar = \BahriCanli\EYazisma\Cades\Der::ogeler(\BahriCanli\EYazisma\Cades\Der::tek($der)['icerik']);

        $this->assertSame(12345, hexdec(bin2hex($alanlar[0]['icerik'])));
        $anahtar = hash_pbkdf2('sha256', 'gizli', $alanlar[1]['icerik'], 100, 32, true);
        $this->assertSame($ozet, openssl_decrypt($alanlar[4]['icerik'], 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $alanlar[3]['icerik']));

        $this->expectException(EYazismaException::class);
        KamuSmKimlik::baslik('kullanici', 'gizli', $ozet);
    }

    public function test_timestamped_signature_is_verified_end_to_end(): void
    {
        $this->opensslGerekli();
        $http = $this->http();
        [$anahtar1, $pem1] = self::$pki->imzaci(['commonName' => 'AYŞE YILMAZ', 'serialNumber' => '12345678950'], 1010);
        [$anahtar2, $pem2] = self::$pki->imzaci(['commonName' => 'MEHMET KAYA', 'serialNumber' => '10000000146'], 1011);
        $tsa = new Rfc3161('http://tsa.test/', istemci: $http, kimlikDogrulama: 'yok');

        $birinci = Cades::zamanDamgasiEkle(self::$pki->imzala(self::ICERIK, $pem1, $anahtar1), $tsa);
        $ikinci = self::$pki->imzala(self::ICERIK, $pem2, $anahtar2);
        $birlesik = Cades::zamanDamgasiEkle(Cades::birlestir($birinci, $ikinci), $tsa);

        $this->assertSame($birlesik, Cades::zamanDamgasiEkle($birlesik, $tsa), 'Damgası olan imzaya yeniden damga alınmamalı.');
        $this->assertCount(2, Cades::imzacilar($birlesik, self::ICERIK), 'Damga imzayı bozmamalı.');

        $sonuclar = (new ImzaDenetleyici(new SertifikaDenetleyici([self::$pki->kok], $http)))->denetle($birlesik, self::ICERIK);

        $this->assertCount(2, $sonuclar);

        foreach ($sonuclar as $sonuc) {
            $this->assertTrue($sonuc->sertifikaDenetimi->gecerli(), implode(' ', $sonuc->sertifikaDenetimi->hatalar));
            $this->assertTrue($sonuc->damgaGecerli(), implode(' ', $sonuc->hatalar));
            $this->assertNotNull($sonuc->dizi()['zaman_damgasi']);
        }

        $this->assertEqualsCanonicalizing(['12345678950', '10000000146'], array_map(fn ($sonuc) => $sonuc->sertifika->tckn(), $sonuclar));
    }

    private function opensslGerekli(): void
    {
        if (! TestPki::opensslVar()) {
            $this->markTestSkipped('openssl komutu yok.');
        }
    }
}
