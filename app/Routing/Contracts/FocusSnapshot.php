<?php

namespace App\Routing\Contracts;

final class FocusSnapshot
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function __construct(
        public readonly string $objectType = '',
        public readonly string $objectId = '',
        public readonly string $summary = '',
        public readonly float $confidence = 0.0,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trim((string) ($data['object_type'] ?? '')),
            trim((string) ($data['object_id'] ?? '')),
            trim((string) ($data['summary'] ?? '')),
            (float) ($data['confidence'] ?? 0.0),
            is_array($data['attributes'] ?? null) ? $data['attributes'] : []
        );
    }
}
