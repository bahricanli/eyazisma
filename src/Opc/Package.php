<?php

namespace BahriCanli\EYazisma\Opc;

use BahriCanli\EYazisma\Exceptions\GecersizPaketException;
use DOMDocument;
use DOMElement;

/**
 * Open Packaging Conventions (ISO/IEC 29500-2) container: parts, content types and relationships.
 *
 * Part names carry a leading slash and are matched case-insensitively, as OPC requires.
 */
final class Package
{
    public const CONTENT_TYPES = '[Content_Types].xml';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const TYPE_RELATIONSHIPS = 'application/vnd.openxmlformats-package.relationships+xml';

    /** @var array<string, array{name: string, data: string}> keyed by lower-cased part name */
    private array $parts = [];

    /** @var array<string, string> extension => content type */
    private array $defaults = [];

    /** @var array<string, string> lower-cased part name => content type */
    private array $overrides = [];

    /** @var array<string, list<Relationship>> keyed by lower-cased source part name, '' for the package */
    private array $relationships = [];

    public static function read(string $zip, int $limit = ZipReader::VARSAYILAN_SINIR): self
    {
        $package = new self;
        $relationshipParts = [];

        foreach (ZipReader::read($zip, $limit) as $entry => $data) {
            if (strcasecmp($entry, self::CONTENT_TYPES) === 0) {
                $package->readContentTypes($data);
            } elseif (preg_match('~^(.*/)?_rels/([^/]*)\.rels$~i', '/'.$entry, $match)) {
                $relationshipParts[$match[2] === '' ? '' : $match[1].$match[2]] = $data;
            } else {
                $package->parts[strtolower('/'.$entry)] = ['name' => '/'.$entry, 'data' => $data];
            }
        }

        foreach ($relationshipParts as $source => $data) {
            $package->readRelationships($source, $data);
        }

        return $package;
    }

    public function write(): string
    {
        $zip = new ZipWriter;
        $zip->add(self::CONTENT_TYPES, $this->contentTypesXml());

        foreach ($this->relationships as $source => $relationships) {
            if ($relationships !== []) {
                $zip->add(ltrim(self::relationshipsPartName($this->sourceName($source)), '/'), $this->relationshipsXml($relationships));
            }
        }

        foreach ($this->parts as $part) {
            $zip->add(ltrim($part['name'], '/'), $part['data']);
        }

        return $zip->finish();
    }

    public function has(string $name): bool
    {
        return isset($this->parts[strtolower($name)]);
    }

    public function get(string $name): ?string
    {
        return $this->parts[strtolower($name)]['data'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->parts, 'name');
    }

    public function put(string $name, string $data, ?string $contentType = null): void
    {
        $key = strtolower($name);
        $this->parts[$key] = ['name' => $this->parts[$key]['name'] ?? $name, 'data' => $data];

        if ($contentType !== null) {
            $this->setContentType($name, $contentType);
        }
    }

    public function remove(string $name): void
    {
        $key = strtolower($name);
        unset($this->parts[$key], $this->overrides[$key], $this->relationships[$key]);
    }

    public function contentType(string $name): ?string
    {
        return $this->overrides[strtolower($name)]
            ?? $this->defaults[strtolower(pathinfo($name, PATHINFO_EXTENSION))]
            ?? null;
    }

    public function relate(string $source, string $type, string $target, string $id): void
    {
        $key = strtolower($source);
        $this->relationships[$key] = array_values(array_filter(
            $this->relationships[$key] ?? [],
            fn (Relationship $relationship) => $relationship->id !== $id
        ));
        $this->relationships[$key][] = new Relationship($id, $type, $target, $source);
    }

    /**
     * @return list<Relationship>
     */
    public function relationships(string $source = '', ?string $type = null): array
    {
        return array_values(array_filter(
            $this->relationships[strtolower($source)] ?? [],
            fn (Relationship $relationship) => $type === null || strcasecmp($relationship->type, $type) === 0
        ));
    }

    /**
     * Absolute part name a relationship points to.
     */
    public function resolve(Relationship $relationship): string
    {
        $target = rawurldecode($relationship->target);

        if (! str_starts_with($target, '/')) {
            $base = $relationship->source === '' ? '/' : substr($relationship->source, 0, strrpos($relationship->source, '/') + 1);
            $target = $base.$target;
        }

        $segments = [];

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/'.implode('/', $segments);
    }

    private function setContentType(string $name, string $contentType): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension !== '' && ($this->defaults[$extension] ?? $contentType) === $contentType) {
            $this->defaults[$extension] = $contentType;
            unset($this->overrides[strtolower($name)]);

            return;
        }

        $this->overrides[strtolower($name)] = $contentType;
    }

    private function sourceName(string $key): string
    {
        return $key === '' ? '' : ($this->parts[$key]['name'] ?? $key);
    }

    private static function relationshipsPartName(string $source): string
    {
        if ($source === '') {
            return '/_rels/.rels';
        }

        $slash = strrpos($source, '/');

        return substr($source, 0, $slash).'/_rels/'.substr($source, $slash + 1).'.rels';
    }

    private function readContentTypes(string $xml): void
    {
        foreach (self::load($xml, self::CONTENT_TYPES)->documentElement->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'Default') {
                $this->defaults[strtolower($node->getAttribute('Extension'))] = $node->getAttribute('ContentType');
            } elseif ($node->localName === 'Override') {
                $this->overrides[strtolower($node->getAttribute('PartName'))] = $node->getAttribute('ContentType');
            }
        }
    }

    private function readRelationships(string $source, string $xml): void
    {
        foreach (self::load($xml, self::relationshipsPartName($source))->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'Relationship' && $node->getAttribute('TargetMode') !== 'External') {
                $this->relationships[strtolower($source)][] = new Relationship(
                    $node->getAttribute('Id'),
                    $node->getAttribute('Type'),
                    $node->getAttribute('Target'),
                    $source,
                );
            }
        }
    }

    private function contentTypesXml(): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElementNS(self::NS_CONTENT_TYPES, 'Types'));

        foreach (['xml' => 'application/xml', 'rels' => self::TYPE_RELATIONSHIPS] + $this->defaults as $extension => $contentType) {
            $default = $root->appendChild($document->createElementNS(self::NS_CONTENT_TYPES, 'Default'));
            $default->setAttribute('Extension', $extension);
            $default->setAttribute('ContentType', $contentType);
        }

        foreach ($this->overrides as $key => $contentType) {
            if (isset($this->parts[$key])) {
                $override = $root->appendChild($document->createElementNS(self::NS_CONTENT_TYPES, 'Override'));
                $override->setAttribute('PartName', $this->parts[$key]['name']);
                $override->setAttribute('ContentType', $contentType);
            }
        }

        return $document->saveXML();
    }

    /**
     * @param  list<Relationship>  $relationships
     */
    private function relationshipsXml(array $relationships): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElementNS(self::NS_RELATIONSHIPS, 'Relationships'));

        foreach ($relationships as $relationship) {
            $element = $root->appendChild($document->createElementNS(self::NS_RELATIONSHIPS, 'Relationship'));
            $element->setAttribute('Type', $relationship->type);
            $element->setAttribute('Target', $relationship->target);
            $element->setAttribute('Id', $relationship->id);
        }

        return $document->saveXML();
    }

    private static function load(string $xml, string $part): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            throw new GecersizPaketException("Paket bileşeni okunamadı: {$part}");
        }

        return $document;
    }
}
