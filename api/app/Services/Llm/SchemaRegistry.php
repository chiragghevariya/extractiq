<?php

namespace App\Services\Llm;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Versioned document schemas live in resources/schemas/{type}.v{n}.json and describe the
 * clean data model (formats, patterns, descriptions). From each we derive the
 * *extraction* schema sent to the model: every field becomes {value, confidence, source}
 * (line items carry confidence + source per line) using only keywords that strict
 * structured-output modes accept.
 */
class SchemaRegistry
{
    public const TYPES = ['invoice', 'purchase_order', 'goods_receipt'];

    private const STRUCTURAL = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'description'];

    /** @var array<string, array> */
    private array $cache = [];

    public function version(string $type): string
    {
        return config("extractiq.extraction.schema_versions.$type", 'v1');
    }

    public function id(string $type): string
    {
        return "$type.".$this->version($type);
    }

    public function plain(string $type): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("No extraction schema for type [$type].");
        }

        return $this->cache[$this->id($type)] ??= json_decode(
            file_get_contents(resource_path('schemas/'.$this->id($type).'.json')), true, flags: JSON_THROW_ON_ERROR,
        );
    }

    /** The schema the model must answer in. */
    public function extraction(string $type): array
    {
        $plain = $this->plain($type);
        $props = [];
        foreach ($plain['properties'] as $name => $field) {
            $props[$name] = $this->wrapField($field);
        }

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $props, 'required' => array_keys($props)];
    }

    private function wrapField(array $field): array
    {
        $types = (array) $field['type'];
        if (in_array('object', $types, true)) {
            $props = [];
            foreach ($field['properties'] as $k => $f) {
                $props[$k] = $this->wrapField($f);
            }

            return ['type' => 'object', 'description' => $field['description'] ?? '', 'additionalProperties' => false, 'properties' => $props, 'required' => array_keys($props)];
        }
        if (in_array('array', $types, true)) {
            $item = $this->strip($field['items']);
            $item['properties']['confidence'] = ['type' => 'number', 'description' => 'Your 0..1 confidence in this line.'];
            $item['properties']['source'] = $this->sourceSchema();
            $item['required'] = array_keys($item['properties']);

            return ['type' => 'array', 'description' => $field['description'] ?? '', 'items' => $item];
        }

        return [
            'type' => 'object',
            'description' => $field['description'] ?? '',
            'additionalProperties' => false,
            'properties' => [
                'value' => $this->strip($field),
                'confidence' => ['type' => 'number', 'description' => '0..1: how sure you are about the value. Lower it for illegible, ambiguous or inferred values.'],
                'source' => $this->sourceSchema(),
            ],
            'required' => ['value', 'confidence', 'source'],
        ];
    }

    private function sourceSchema(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the OCR lines the value was read from, e.g. ["p1.l7"]. Empty if not found on the page.'];
    }

    /** Remove keywords strict structured-output modes reject (format, pattern, $schema…). */
    private function strip(array $schema): array
    {
        $out = array_intersect_key($schema, array_flip(self::STRUCTURAL));
        if (isset($out['properties'])) {
            $out['properties'] = array_map(fn ($p) => $this->strip($p), $out['properties']);
        }
        if (isset($out['items'])) {
            $out['items'] = $this->strip($out['items']);
        }

        return $out;
    }

    /** Leaf field paths (vendor.name, invoice_number, …) excluding line items. @return list<string> */
    public function fieldPaths(string $type): array
    {
        $paths = [];
        foreach ($this->plain($type)['properties'] as $name => $f) {
            $t = (array) $f['type'];
            if (in_array('object', $t, true)) {
                foreach (array_keys($f['properties']) as $k) {
                    $paths[] = "$name.$k";
                }
            } elseif (! in_array('array', $t, true)) {
                $paths[] = $name;
            }
        }

        return $paths;
    }

    /** @return list<string> human-readable validation errors (empty = valid) */
    public function validate(array $data, array $schema): array
    {
        $result = (new Validator)->validate(json_decode(json_encode($data)), json_decode(json_encode($schema)));
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $m) {
                $errors[] = ($path ?: '/').': '.$m;
            }
        }

        return $errors;
    }

    /**
     * Wrapped model reply → [plain values, field meta].
     *
     * @return array{0: array, 1: array<string, array{confidence: float, source: list<string>}>}
     */
    public function unwrap(string $type, array $reply): array
    {
        $plain = [];
        $meta = [];
        foreach ($this->plain($type)['properties'] as $name => $f) {
            $t = (array) $f['type'];
            $node = $reply[$name] ?? null;
            if (in_array('object', $t, true)) {
                foreach (array_keys($f['properties']) as $k) {
                    $plain[$name][$k] = $node[$k]['value'] ?? null;
                    $meta["$name.$k"] = ['confidence' => (float) ($node[$k]['confidence'] ?? 0), 'source' => array_values((array) ($node[$k]['source'] ?? []))];
                }
            } elseif (in_array('array', $t, true)) {
                $plain[$name] = [];
                foreach ((array) $node as $i => $item) {
                    $meta["$name.$i"] = ['confidence' => (float) ($item['confidence'] ?? 0), 'source' => array_values((array) ($item['source'] ?? []))];
                    unset($item['confidence'], $item['source']);
                    $plain[$name][] = $item;
                }
            } else {
                $plain[$name] = $node['value'] ?? null;
                $meta[$name] = ['confidence' => (float) ($node['confidence'] ?? 0), 'source' => array_values((array) ($node['source'] ?? []))];
            }
        }

        return [$plain, $meta];
    }
}
