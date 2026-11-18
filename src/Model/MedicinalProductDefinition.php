<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Model;

use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use Hrabosh\EmaUpd\Internal\DecodedJson;
use SensitiveParameter;

final readonly class MedicinalProductDefinition
{
    private ?string $id;

    /** @var array<int|string, mixed> */
    private array $payload;

    /** @internal Created by the client from a decoded UPD response. */
    public function __construct(#[SensitiveParameter] DecodedJson $response)
    {
        $payload = $response->getObject();
        if (($payload->resourceType ?? null) !== 'MedicinalProductDefinition') {
            throw new UnexpectedResponseException('The UPD response is not a MedicinalProductDefinition.');
        }

        $id = null;
        if (property_exists($payload, 'id')) {
            $id = $payload->id;
            if (!is_string($id)) {
                throw new UnexpectedResponseException('The MedicinalProductDefinition ID must be a string.');
            }
        }

        $this->id = $id;
        $this->payload = $response->toArray();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /** @return array<int|string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
