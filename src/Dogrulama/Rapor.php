<?php

namespace BahriCanli\EYazisma\Dogrulama;

use BahriCanli\EYazisma\Enums\Seviye;

final class Rapor
{
    /**
     * @param  list<Bulgu>  $bulgular
     */
    public function __construct(public readonly array $bulgular = [])
    {
    }

    /** Hata yoksa paket rehberin denetlenen kurallarına uygundur. */
    public function gecerli(): bool
    {
        return $this->hatalar() === [];
    }

    /**
     * @return list<Bulgu>
     */
    public function hatalar(): array
    {
        return $this->seviyedekiler(Seviye::Hata);
    }

    /**
     * @return list<Bulgu>
     */
    public function uyarilar(): array
    {
        return $this->seviyedekiler(Seviye::Uyari);
    }

    /**
     * @return list<Bulgu>
     */
    private function seviyedekiler(Seviye $seviye): array
    {
        return array_values(array_filter($this->bulgular, fn (Bulgu $bulgu) => $bulgu->seviye === $seviye));
    }
}
