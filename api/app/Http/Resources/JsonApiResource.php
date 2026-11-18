<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal JSON:API-style resource: { type, id, attributes, relationships?, links? }.
 * Collections return { data: [...], meta: { page }, links } via JsonApiCollection.
 */
abstract class JsonApiResource extends JsonResource
{
    abstract public function type(): string;

    abstract public function fields(Request $request): array;

    public function relationships(Request $request): array
    {
        return [];
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'type' => $this->type(),
            'id' => (string) $this->resource->getKey(),
            'attributes' => $this->fields($request),
            'relationships' => $this->relationships($request) ?: null,
        ], fn ($v) => $v !== null);
    }

    public static function identifier(string $type, mixed $id): ?array
    {
        return $id === null ? null : ['type' => $type, 'id' => (string) $id];
    }

    protected static function newCollection($resource)
    {
        return new JsonApiCollection($resource, static::class);
    }
}
