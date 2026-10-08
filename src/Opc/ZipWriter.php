<?php

namespace BahriCanli\EYazisma\Opc;

use BahriCanli\EYazisma\Exceptions\EYazismaException;

/**
 * Minimal ZIP writer (store/deflate, no ZIP64) so the package does not need ext-zip.
 */
final class ZipWriter
{
    private const LIMIT = 0xFFFFFFFF;

    private string $body = '';

    private string $directory = '';

    private int $count = 0;

    public function add(string $name, string $data, bool $compress = true): void
    {
        $size = strlen($data);
        $method = 0;
        $packed = $data;

        if ($compress && $size > 0) {
            $deflated = gzdeflate($data, 6);

            if ($deflated !== false && strlen($deflated) < $size) {
                $method = 8;
                $packed = $deflated;
            }
        }

        $offset = strlen($this->body);

        if ($size >= self::LIMIT || $offset + strlen($packed) >= self::LIMIT || $this->count >= 0xFFFF) {
            throw new EYazismaException('Paket ZIP64 gerektirecek kadar büyük; desteklenmiyor.');
        }

        // Bit 11: file names are UTF-8.
        $common = pack('vvvvVVVvv', 0x0800, $method, self::dosTime(), self::dosDate(), crc32($data), strlen($packed), $size, strlen($name), 0);

        $this->body .= pack('Vv', 0x04034b50, 20).$common.$name.$packed;
        $this->directory .= pack('Vvv', 0x02014b50, 20, 20).$common.pack('vvvVV', 0, 0, 0, 0, $offset).$name;
        $this->count++;
    }

    public function finish(): string
    {
        return $this->body
            .$this->directory
            .pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->directory), strlen($this->body), 0);
    }

    private static function dosTime(): int
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', date('H:i:s')));

        return ($hour << 11) | ($minute << 5) | intdiv($second, 2);
    }

    private static function dosDate(): int
    {
        [$year, $month, $day] = array_map('intval', explode('-', date('Y-m-d')));

        return (max($year, 1980) - 1980) << 9 | ($month << 5) | $day;
    }
}
