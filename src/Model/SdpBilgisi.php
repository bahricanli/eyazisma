<?php

namespace BahriCanli\EYazisma\Model;

final class SdpBilgisi
{
    /**
     * @param  list<Sdp>  $digerSdpler
     */
    public function __construct(
        public readonly Sdp $anaSdp,
        public readonly array $digerSdpler = [],
    ) {
    }
}
