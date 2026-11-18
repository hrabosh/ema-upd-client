<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd;

use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Internal\ApiRequestExecutor;
use Hrabosh\EmaUpd\Model\Bundle;
use Hrabosh\EmaUpd\Model\MedicinalProductDefinition;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use SensitiveParameter;

final readonly class UpdClient
{
    private const string PRODUCT_RESOURCE = 'MedicinalProductDefinition';

    private ApiRequestExecutor $requests;

    public function __construct(
        #[SensitiveParameter]
        ClientInterface $httpClient,
        #[SensitiveParameter]
        RequestFactoryInterface $requestFactory,
        #[SensitiveParameter]
        AccessTokenProviderInterface $tokenProvider,
        #[SensitiveParameter]
        string $baseUri = ApiRequestExecutor::DEFAULT_BASE_URI,
    ) {
        $this->requests = new ApiRequestExecutor($httpClient, $requestFactory, $tokenProvider, $baseUri);
    }

    public function getMedicinalProduct(#[SensitiveParameter] string $id): MedicinalProductDefinition
    {
        $result = $this->requests->get([self::PRODUCT_RESOURCE, $id]);

        return new MedicinalProductDefinition($result['payload']);
    }

    public function getMedicinalProductEverything(#[SensitiveParameter] string $id): Bundle
    {
        $result = $this->requests->get([self::PRODUCT_RESOURCE, $id, '$everything']);

        return new Bundle($result['payload'], $result['requestUri']);
    }
}
