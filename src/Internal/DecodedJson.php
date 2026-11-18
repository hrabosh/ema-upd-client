<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Internal;

use SensitiveParameter;
use stdClass;

/** @internal */
final readonly class DecodedJson
{
    /** @param array<int|string, mixed> $payload */
    private function __construct(
        #[SensitiveParameter]
        private stdClass $object,
        #[SensitiveParameter]
        private array $payload,
    ) {}

    public static function fromString(#[SensitiveParameter] string $json): ?self
    {
        // The validation view keeps JSON object/list distinctions and numeric types.
        $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$object instanceof stdClass) {
            return null;
        }

        // The exposed payload preserves oversized integers as strings.
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) {
            return null;
        }

        return new self($object, $payload);
    }

    public function getObject(): stdClass
    {
        return $this->object;
    }

    /** @return array<int|string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
