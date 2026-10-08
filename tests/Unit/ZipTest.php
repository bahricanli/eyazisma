<?php

namespace BahriCanli\EYazisma\Tests\Unit;

use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use BahriCanli\EYazisma\Opc\ZipReader;
use BahriCanli\EYazisma\Opc\ZipWriter;
use PHPUnit\Framework\TestCase;

class ZipTest extends TestCase
{
    public function test_written_entries_are_read_back(): void
    {
        $entries = [
            '[Content_Types].xml' => '<Types/>',
            'Ekler/metin.txt' => str_repeat('e-Yazışma ', 500),
            'Ekler/rastgele.bin' => random_bytes(2048),
            'bos.txt' => '',
        ];

        $zip = new ZipWriter;

        foreach ($entries as $name => $data) {
            $zip->add($name, $data);
        }

        $this->assertSame($entries, ZipReader::read($zip->finish()));
    }

    public function test_corrupted_entry_is_rejected(): void
    {
        $zip = new ZipWriter;
        $zip->add('a.txt', 'icerik', compress: false);
        $bytes = str_replace('icerik', 'icerix', $zip->finish());

        $this->expectException(GecersizPaketException::class);

        ZipReader::read($bytes);
    }

    public function test_size_limit_is_enforced(): void
    {
        $zip = new ZipWriter;
        $zip->add('buyuk.txt', str_repeat('a', 10_000));

        $this->expectException(GecersizPaketException::class);

        ZipReader::read($zip->finish(), 1_000);
    }

    public function test_non_zip_content_is_rejected(): void
    {
        $this->expectException(GecersizPaketException::class);

        ZipReader::read('%PDF-1.7 bu bir paket değil');
    }
}
