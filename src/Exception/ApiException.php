<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Exception;

use SensitiveParameter;
use SensitiveParameterValue;

final class ApiException extends UpdException
{
    private readonly SensitiveParameterValue $details;

    /** @param array<int|string, mixed>|null $operationOutcome */
    public function __construct(
        private readonly int $statusCode,
        #[SensitiveParameter]
        ?string $retryAfter = null,
        #[SensitiveParameter]
        ?array $operationOutcome = null,
    ) {
        parent::__construct("The UPD API returned HTTP {$statusCode}.");
        $this->details = new SensitiveParameterValue([$retryAfter, $operationOutcome]);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getRetryAfter(): ?string
    {
        return $this->details()[0];
    }

    /** @return array<int|string, mixed>|null */
    public function getOperationOutcome(): ?array
    {
        return $this->details()[1];
    }

    /** @return array{?string, array<int|string, mixed>|null} */
    private function details(): array
    {
        /** @var array{?string, array<int|string, mixed>|null} $details */
        $details = $this->details->getValue();

        return $details;
    }
}
