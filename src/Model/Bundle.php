<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Model;

use Hrabosh\EmaUpd\Exception\UnexpectedResponseException;
use Hrabosh\EmaUpd\Internal\DecodedJson;
use Psr\Http\Message\UriInterface;
use SensitiveParameter;
use stdClass;

final readonly class Bundle
{
    /** @var array<int|string, mixed> */
    private array $payload;

    /** @var list<array<int|string, mixed>> */
    private array $entries;

    private ?int $total;
    private ?string $nextLink;

    /** @internal Created by the client from a decoded UPD response. */
    public function __construct(
        #[SensitiveParameter]
        DecodedJson $response,
        #[SensitiveParameter]
        private UriInterface $requestUri,
        private bool $searchResult = false,
    ) {
        $payload = $response->getObject();
        if (($payload->resourceType ?? null) !== 'Bundle') {
            throw new UnexpectedResponseException('The UPD response is not a Bundle.');
        }
        if ($searchResult && ($payload->type ?? null) !== 'searchset') {
            throw new UnexpectedResponseException('The UPD search response must be a searchset Bundle.');
        }

        if (property_exists($payload, 'entry')) {
            self::validateEntries($payload->entry);
        }

        $total = null;
        if (property_exists($payload, 'total')) {
            $total = $payload->total;
            if (!is_int($total) || $total < 0) {
                throw new UnexpectedResponseException('The Bundle total must be a nonnegative integer.');
            }
        }

        $this->total = $total;
        $this->nextLink = property_exists($payload, 'link') ? self::findNextLink($payload->link) : null;
        $this->payload = $response->toArray();

        // The object view of this response confirmed that every entry is an object.
        /** @var list<array<int|string, mixed>> $entries */
        $entries = $this->payload['entry'] ?? [];
        $this->entries = $entries;
    }

    /** @return list<array<int|string, mixed>> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function getNextLink(): ?string
    {
        return $this->nextLink;
    }

    /** @internal The effective request URI is used to resolve relative page links. */
    public function getRequestUri(): UriInterface
    {
        return $this->requestUri;
    }

    /** @internal Search continuations use the same envelope rules as the first page. */
    public function isSearchResult(): bool
    {
        return $this->searchResult;
    }

    /** @return array<int|string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    private static function validateEntries(#[SensitiveParameter] mixed $entries): void
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new UnexpectedResponseException('The Bundle entry field must be a list.');
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof stdClass) {
                throw new UnexpectedResponseException('Each Bundle entry must be an object.');
            }
        }
    }

    private static function findNextLink(#[SensitiveParameter] mixed $links): ?string
    {
        if (!is_array($links) || !array_is_list($links)) {
            throw new UnexpectedResponseException('The Bundle link field must be a list.');
        }

        $next = null;
        foreach ($links as $link) {
            if (!$link instanceof stdClass) {
                throw new UnexpectedResponseException('Each Bundle link must be an object.');
            }

            $relation = $link->relation ?? null;
            $url = $link->url ?? null;
            if (!is_string($relation) || trim($relation) === '' || !is_string($url) || trim($url) === '') {
                throw new UnexpectedResponseException('Each Bundle link must contain a relation and URL.');
            }

            if ($relation === 'next') {
                if ($next !== null && $next !== $url) {
                    throw new UnexpectedResponseException('The Bundle contains conflicting next links.');
                }

                $next = $url;
            }
        }

        return $next;
    }
}
