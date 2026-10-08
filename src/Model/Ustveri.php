<?php

namespace BahriCanli\EYazisma\Model;

use BahriCanli\EYazisma\Enums\GuvenlikKodu;
use DateTimeImmutable;

/**
 * "Üstveri" bileşeni: belgenin kimlik bilgileri. Tarih ve sayı NihaiUstveri'dedir.
 */
final class Ustveri
{
    /**
     * @param  string  $mimeTuru  üst yazı dosyasının türü
     * @param  string  $dosyaAdi  üst yazı dosyasının adı
     * @param  list<Dagitim>  $dagitimlar
     * @param  list<Ek>  $ekler
     * @param  list<Ilgi>  $ilgiler
     * @param  list<Taraf>  $ilgililer  belgeyle ilgili iletişim kurulacak taraflar
     * @param  list<Heysk>  $heyskler
     * @param  string|null  $dil  ISO 639-3 dil kodu (ör. "tur")
     */
    public function __construct(
        public readonly string $belgeId,
        public readonly string $konu,
        public readonly string $mimeTuru,
        public readonly string $dosyaAdi,
        public readonly Taraf $olusturan,
        public readonly array $dagitimlar,
        public readonly string $dogrulamaAdresi,
        public readonly GuvenlikKodu $guvenlikKodu = GuvenlikKodu::Yok,
        public readonly ?DateTimeImmutable $guvenlikKoduGecerlilikTarihi = null,
        public readonly ?Tanimlayici $ozId = null,
        public readonly array $ekler = [],
        public readonly array $ilgiler = [],
        public readonly ?string $dil = null,
        public readonly array $ilgililer = [],
        public readonly ?SdpBilgisi $sdpBilgisi = null,
        public readonly array $heyskler = [],
    ) {
    }

    public function ek(string $id): ?Ek
    {
        foreach ($this->ekler as $ek) {
            if (strcasecmp($ek->id, $id) === 0) {
                return $ek;
            }
        }

        return null;
    }
}
