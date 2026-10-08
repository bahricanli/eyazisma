<?php

namespace BahriCanli\EYazisma\Opc;

use BahriCanli\EYazisma\Exceptions\GecersizPaketException;

/**
 * Minimal ZIP reader (store/deflate, no ZIP64, no encryption) working on an in-memory archive.
 */
final class ZipReader
{
    /** Upper bound for the total uncompressed size, against decompression bombs. */
    public const VARSAYILAN_SINIR = 256 * 1024 * 1024;

    /**
     * @return array<string, string> entry name => contents, directories skipped
     */
    public static function read(string $zip, int $limit = self::VARSAYILAN_SINIR): array
    {
        $end = strrpos($zip, "PK\x05\x06");

        if ($end === false || strlen($zip) < $end + 22) {
            throw new GecersizPaketException('Dosya bir ZIP arşivi değil.');
        }

        $eocd = unpack('vdisk/vcdDisk/vdiskEntries/ventries/Vsize/Voffset', substr($zip, $end + 4, 16));

        if ($eocd['entries'] === 0xFFFF || $eocd['offset'] === 0xFFFFFFFF) {
            throw new GecersizPaketException('ZIP64 arşivleri desteklenmiyor.');
        }

        $files = [];
        $total = 0;
        $position = $eocd['offset'];

        for ($i = 0; $i < $eocd['entries']; $i++) {
            if (substr($zip, $position, 4) !== "PK\x01\x02" || strlen($zip) < $position + 46) {
                throw new GecersizPaketException('ZIP dizini bozuk.');
            }

            $entry = unpack(
                'vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
                substr($zip, $position + 4, 42)
            );
            $name = substr($zip, $position + 46, $entry['nameLength']);
            $position += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            if ($entry['flags'] & 0x1) {
                throw new GecersizPaketException("Şifreli ZIP girdisi: {$name}");
            }

            if ($entry['packed'] === 0xFFFFFFFF || $entry['size'] === 0xFFFFFFFF || $entry['offset'] === 0xFFFFFFFF) {
                throw new GecersizPaketException('ZIP64 arşivleri desteklenmiyor.');
            }

            $total += $entry['size'];

            if ($total > $limit) {
                throw new GecersizPaketException('Paket içeriği izin verilen boyutu aşıyor.');
            }

            $files[$name] = self::extract($zip, $name, $entry);
        }

        return $files;
    }

    /**
     * @param  array<string, int>  $entry
     */
    private static function extract(string $zip, string $name, array $entry): string
    {
        $offset = $entry['offset'];

        if (substr($zip, $offset, 4) !== "PK\x03\x04" || strlen($zip) < $offset + 30) {
            throw new GecersizPaketException("ZIP girdisi bozuk: {$name}");
        }

        // Sizes come from the central directory; the local header may defer them to a data descriptor.
        $local = unpack('vnameLength/vextraLength', substr($zip, $offset + 26, 4));
        $packed = substr($zip, $offset + 30 + $local['nameLength'] + $local['extraLength'], $entry['packed']);

        if (strlen($packed) !== $entry['packed']) {
            throw new GecersizPaketException("ZIP girdisi eksik: {$name}");
        }

        $data = match ($entry['method']) {
            0 => $packed,
            8 => $entry['size'] === 0 ? '' : @gzinflate($packed, $entry['size']),
            default => throw new GecersizPaketException("Desteklenmeyen sıkıştırma yöntemi ({$entry['method']}): {$name}"),
        };

        if ($data === false || strlen($data) !== $entry['size'] || crc32($data) !== $entry['crc']) {
            throw new GecersizPaketException("ZIP girdisi bozuk: {$name}");
        }

        return $data;
    }
}
