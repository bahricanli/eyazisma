<?php

namespace BahriCanli\EYazisma\Tests;

use BahriCanli\EYazisma\Contracts\HttpIstemci;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use Closure;

final class SahteHttpIstemci implements HttpIstemci
{
    /** @var list<array{adres: string, govde: ?string, basliklar: array<string, string>}> */
    public array $istekler = [];

    /**
     * @param  array<string, string|Closure(string): string>  $yanitlar  adres önekine göre
     */
    public function __construct(private array $yanitlar = [])
    {
    }

    public function getir(string $adres): string
    {
        $this->istekler[] = ['adres' => $adres, 'govde' => null, 'basliklar' => []];

        return $this->yanit($adres, '');
    }

    public function gonder(string $adres, string $govde, array $basliklar = []): string
    {
        $this->istekler[] = ['adres' => $adres, 'govde' => $govde, 'basliklar' => $basliklar];

        return $this->yanit($adres, $govde);
    }

    private function yanit(string $adres, string $govde): string
    {
        foreach ($this->yanitlar as $onek => $yanit) {
            if (str_starts_with($adres, $onek)) {
                return $yanit instanceof Closure ? $yanit($govde) : $yanit;
            }
        }

        throw new EYazismaException("$adres yanıt vermedi.");
    }
}
