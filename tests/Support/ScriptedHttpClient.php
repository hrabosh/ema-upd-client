<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Tests\Support;

use Closure;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ScriptedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|Closure(RequestInterface): ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface|Closure ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        if ($response === null) {
            throw new LogicException('Unexpected HTTP request.');
        }

        return $response instanceof Closure ? $response($request) : $response;
    }
}
