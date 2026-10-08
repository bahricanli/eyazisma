<?php

namespace BahriCanli\EYazisma\Xml;

/**
 * XML ad alanları ve paket ilişki türleri (e-Yazışma Teknik Rehberi 2.x).
 */
final class Ad
{
    public const USTVERI = 'urn:dpt:eyazisma:schema:xsd:Ustveri-2';

    public const NIHAI_USTVERI = 'urn:dpt:eyazisma:schema:xsd:NihaiUstveri-2';

    public const TIPLER = 'urn:dpt:eyazisma:schema:xsd:Tipler-2';

    public const PAKET_OZETI = 'urn:dpt:eyazisma:schema:xsd:PaketOzeti-2';

    public const NIHAI_OZET = 'urn:dpt:eyazisma:schema:xsd:NihaiOzet-2';

    public const PARAF_OZETI = 'urn:dpt:eyazisma:schema:xsd:ParafOzeti-2';

    /** Ad alanlarının 2.0 öncesi (1.x) karşılıkları. */
    public const USTVERI_1 = 'urn:dpt:eyazisma:schema:xsd:Ustveri-1';

    public const PAKET_OZETI_1 = 'urn:dpt:eyazisma:schema:xsd:PaketOzeti-1';

    public const NIHAI_OZET_1 = 'urn:dpt:eyazisma:schema:xsd:NihaiOzet-1';

    public const CORE = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

    public const DC = 'http://purl.org/dc/elements/1.1/';

    public const DCTERMS = 'http://purl.org/dc/terms/';

    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    public const ILISKI_USTYAZI = 'http://eyazisma.dpt/iliskiler/ustyazi';

    public const ILISKI_USTVERI = 'http://eyazisma.dpt/iliskiler/ustveri';

    public const ILISKI_NIHAI_USTVERI = 'http://eyazisma.dpt/iliskiler/nihaiustveri';

    public const ILISKI_EK = 'http://eyazisma.dpt/iliskiler/ek';

    public const ILISKI_IMZASIZ_EK = 'http://eyazisma.dpt/iliskiler/imzasizEk';

    public const ILISKI_PAKET_OZETI = 'http://eyazisma.dpt/iliskiler/paketozeti';

    public const ILISKI_NIHAI_OZET = 'http://eyazisma.dpt/iliskiler/nihaiozet';

    public const ILISKI_PARAF_OZETI = 'http://eyazisma.dpt/iliskiler/parafozeti';

    public const ILISKI_IMZA = 'http://eyazisma.dpt/iliskiler/imzacades';

    public const ILISKI_PARAF_IMZA = 'http://eyazisma.dpt/iliskiler/parafimzacades';

    public const ILISKI_MUHUR = 'http://eyazisma.dpt/iliskiler/muhurcades';

    /** 1.x paketlerde ve şifreli paketlerde: paketin elektronik olarak iletileceği alıcılar. */
    public const ILISKI_BELGE_HEDEF = 'http://eyazisma.dpt/iliskiler/belgehedef';

    /** Yalnız 1.x paketlerde: belgedeki imzalara ilişkin bilgi. */
    public const ILISKI_BELGE_IMZA = 'http://eyazisma.dpt/iliskiler/belgeimza';

    public const ILISKI_CORE = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';

    public const ILISKI_SIFRELI_ICERIK = 'http://eyazisma.dpt/iliskiler/sifreliicerik';

    public const ILISKI_ORIJINAL_PAKET = 'http://eyazisma.dpt/iliskiler/orijinalpaket';

    public const PARCA_USTVERI = '/Ustveri/Ustveri.xml';

    public const PARCA_NIHAI_USTVERI = '/NihaiUstveri/NihaiUstveri.xml';

    public const PARCA_PAKET_OZETI = '/PaketOzeti/PaketOzeti.xml';

    public const PARCA_NIHAI_OZET = '/NihaiOzet/NihaiOzet.xml';

    public const PARCA_PARAF_OZETI = '/ParafOzeti/ParafOzeti.xml';

    public const PARCA_IMZA = '/Imzalar/ImzaCades.imz';

    public const PARCA_PARAF_IMZA = '/Paraflar/ParafImzaCades.imz';

    public const PARCA_MUHUR = '/Muhur/MuhurCades.imz';

    public const TUR_CORE = 'application/vnd.openxmlformats-package.core-properties+xml';

    public const TUR_IMZA = 'application/octet-stream';

    /** Özet bileşeninin kök elemanına göre ad alanı. */
    public static function ozet(string $kok): string
    {
        return match ($kok) {
            'PaketOzeti' => self::PAKET_OZETI,
            'NihaiOzet' => self::NIHAI_OZET,
            'ParafOzeti' => self::PARAF_OZETI,
        };
    }

    /**
     * Özet bileşeninin 1.x paketlerdeki ad alanı; 1.x'te karşılığı yoksa null.
     */
    public static function eskiOzet(string $kok): ?string
    {
        return match ($kok) {
            'PaketOzeti' => self::PAKET_OZETI_1,
            'NihaiOzet' => self::NIHAI_OZET_1,
            default => null,
        };
    }
}
