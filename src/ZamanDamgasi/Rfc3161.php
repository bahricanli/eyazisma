<?php

namespace BahriCanli\EYazisma\ZamanDamgasi;

use BahriCanli\EYazisma\Ag\AkisHttpIstemci;
use BahriCanli\EYazisma\Cades\Der;
use BahriCanli\EYazisma\Contracts\HttpIstemci;
use BahriCanli\EYazisma\Contracts\ZamanDamgasiSaglayici;
use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * RFC 3161 zaman damgası istemcisi (TÜRKTRUST, Kamu SM ve diğer ZDS'ler). Kimlik doğrulama
 * sunucuya göre değişir:
 *
 * - "basic": HTTP Basic başlığı (TÜRKTRUST ve çoğu ticari ZDS).
 * - "kamusm": Kamu SM'nin "identity" başlığı; müşteri numarası ve parolayla şifrelenmiş özet.
 * - "yok": kimlik doğrulamasız sunucu.
 *
 * Dönen damga, istenen özeti ve gönderilen tek kullanımlık sayıyı (nonce) taşıdığı
 * doğrulandıktan sonra verilir.
 */
final class Rfc3161 implements ZamanDamgasiSaglayici
{
    public const ALGORITMALAR = [
        'sha256' => '2.16.840.1.101.3.4.2.1',
        'sha384' => '2.16.840.1.101.3.4.2.2',
        'sha512' => '2.16.840.1.101.3.4.2.3',
    ];

    private readonly HttpIstemci $istemci;

    public function __construct(
        private readonly string $adres,
        private readonly ?string $kullanici = null,
        private readonly ?string $parola = null,
        private readonly string $kimlikDogrulama = 'basic',
        ?HttpIstemci $istemci = null,
    ) {
        $this->istemci = $istemci ?? new AkisHttpIstemci;

        if (! in_array($kimlikDogrulama, ['basic', 'kamusm', 'yok'], true)) {
            throw new EYazismaException("Tanınmayan zaman damgası kimlik doğrulaması: $kimlikDogrulama");
        }
    }

    public function damga(string $ozet, string $algoritma = 'sha256'): string
    {
        $oid = self::ALGORITMALAR[$algoritma] ?? throw new EYazismaException("Desteklenmeyen özet algoritması: $algoritma");
        $nonce = random_bytes(8);

        $istek = Der::kodla(0x30,
            Der::tamsayi("\x01")
            .self::mesajIzi($oid, $ozet)
            .Der::tamsayi($nonce)
            .Der::kodla(0x01, "\xFF"),
        );

        $yanit = $this->istemci->gonder($this->adres, $istek, [
            'Content-Type' => 'application/timestamp-query',
            'Accept' => 'application/timestamp-reply',
            ...$this->kimlikBasliklari($ozet),
        ]);

        $damga = self::yanittanDamga($yanit);
        $bilgi = DamgaBilgisi::coz($damga);

        if (! hash_equals($ozet, $bilgi->ozet) || $bilgi->algoritma !== $algoritma) {
            throw new EYazismaException('Zaman damgası istenen özeti taşımıyor.');
        }

        if ($bilgi->nonce !== null && ltrim($bilgi->nonce, "\0") !== ltrim($nonce, "\0")) {
            throw new EYazismaException('Zaman damgası bu isteğe ait değil (nonce uyuşmuyor).');
        }

        return $damga;
    }

    public static function mesajIzi(string $oid, string $ozet): string
    {
        return Der::kodla(0x30, Der::kodla(0x30, Der::oid($oid).Der::kodla(0x05, '')).Der::kodla(0x04, $ozet));
    }

    /**
     * TimeStampResp içinden damgayı çıkarır; sunucu reddettiyse nedenini bildirir.
     */
    public static function yanittanDamga(string $yanit): string
    {
        try {
            $ogeler = Der::ogeler(Der::tek($yanit)['icerik']);
        } catch (EYazismaException) {
            throw new EYazismaException('Zaman damgası sunucusu RFC 3161 yanıtı vermedi; adres ya da kimlik bilgisi hatalı olabilir.');
        }

        $durumBilgisi = Der::ogeler($ogeler[0]['icerik'] ?? '');
        $durum = ord(substr($durumBilgisi[0]['icerik'] ?? "\x02", -1));

        if ($durum > 1 || ! isset($ogeler[1])) {
            $metin = '';

            foreach (array_slice($durumBilgisi, 1) as $oge) {
                if ($oge['etiket'] === 0x30) {
                    $metin = implode(' ', array_column(Der::ogeler($oge['icerik']), 'icerik'));
                }
            }

            throw new EYazismaException('Zaman damgası sunucusu isteği reddetti'.($metin !== '' ? ": $metin" : " (durum $durum)").'.');
        }

        return $ogeler[1]['ham'];
    }

    /**
     * @return array<string, string>
     */
    private function kimlikBasliklari(string $ozet): array
    {
        return match ($this->kimlikDogrulama) {
            'yok' => [],
            'basic' => ['Authorization' => 'Basic '.base64_encode($this->kullanici.':'.$this->parola)],
            'kamusm' => [
                'User-Agent' => 'UEKAE TSS Client',
                'identity' => KamuSmKimlik::baslik($this->kullanici, (string) $this->parola, $ozet),
            ],
        };
    }
}
