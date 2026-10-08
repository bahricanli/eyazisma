<?php

namespace BahriCanli\EYazisma\Opc;

final class Relationship
{
    /**
     * @param  string  $source  part name the relationship belongs to, '' for the package itself
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $target,
        public readonly string $source = '',
    ) {
    }
}
