<?php

namespace BahriCanli\EYazisma\Denetim;

use BahriCanli\EYazisma\Cades\Sertifika;
use DateTimeImmutable;

/**
 * Bir sertifikanın verilen andaki durumu: güvenilir köke zinciri, geçerlilik süresi ve iptal
 * durumu. "kanit", iptal denetiminde kullanılan ham OCSP yanıtı ya da CRL'dir; saklanırsa
 * denetim sonradan yeniden yapılabilir.
 *
 * @phpstan-type Dizi array{zincir: list<string>, zincir_gecerli: bool, sure_gecerli: bool, iptal: string, iptal_kaynagi: ?string, iptal_tarihi: ?string, zaman: string, hatalar: list<string>}
 */
final class SertifikaDenetimi
{
    /**
     * @param  list<Sertifika>  $zincir  yapraktan köke
     * @param  list<string>  $hatalar
     */
    public function __construct(
        public readonly Sertifika $sertifika,
        public readonly array $zincir,
        public readonly bool $zincirGecerli,
        public readonly bool $sureGecerli,
        public readonly IptalDurumu $iptal,
        public readonly ?string $iptalKaynagi,
        public readonly ?DateTimeImmutable $iptalTarihi,
        public readonly DateTimeImmutable $zaman,
        public readonly array $hatalar = [],
        public readonly ?string $kanit = null,
    ) {
    }

    /**
     * Zincir güvenilir köke bağlanıyor, sertifika süresi içinde ve iptal edilmemiş.
     */
    public function gecerli(): bool
    {
        return $this->zincirGecerli && $this->sureGecerli && $this->iptal === IptalDurumu::Gecerli;
    }

    /**
     * Kesin olumsuz sonuç: iptal edilmiş, süresi dışında ya da tanınmayan köke bağlı.
     * İptal sunucusuna ulaşılamaması kesin olumsuz sayılmaz.
     */
    public function reddedilmeli(): bool
    {
        return $this->iptal === IptalDurumu::IptalEdildi || ! $this->sureGecerli || ! $this->zincirGecerli;
    }

    public function ozet(): string
    {
        return match (true) {
            $this->iptal === IptalDurumu::IptalEdildi => 'Sertifika iptal edilmiş'.($this->iptalTarihi ? ' ('.$this->iptalTarihi->format('d.m.Y H:i').')' : '').'.',
            ! $this->sureGecerli => 'Sertifika imza anında geçerlilik süresi içinde değil.',
            ! $this->zincirGecerli => 'Sertifika güvenilir bir elektronik sertifika hizmet sağlayıcısına bağlanamadı.',
            $this->iptal === IptalDurumu::Bilinmiyor => 'Sertifikanın iptal durumu öğrenilemedi.',
            default => 'Sertifika geçerli; '.strtoupper((string) $this->iptalKaynagi).' ile iptal edilmediği doğrulandı.',
        };
    }

    /**
     * @return Dizi
     */
    public function dizi(): array
    {
        return [
            'zincir' => array_map(fn (Sertifika $sertifika) => $sertifika->adSoyad, $this->zincir),
            'zincir_gecerli' => $this->zincirGecerli,
            'sure_gecerli' => $this->sureGecerli,
            'iptal' => $this->iptal->value,
            'iptal_kaynagi' => $this->iptalKaynagi,
            'iptal_tarihi' => $this->iptalTarihi?->format(DATE_ATOM),
            'zaman' => $this->zaman->format(DATE_ATOM),
            'hatalar' => $this->hatalar,
        ];
    }
}
