<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd;

use Hrabosh\EmaUpd\Auth\AccessTokenProviderInterface;
use Hrabosh\EmaUpd\Internal\ApiRequestExecutor;
use Hrabosh\EmaUpd\Model\Bundle;
use Hrabosh\EmaUpd\Model\MedicinalProductDefinition;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use SensitiveParameter;

final readonly class UpdClient
{
    private const string PRODUCT_RESOURCE = 'MedicinalProductDefinition';
    private const array SEARCH_PARAMETERS = [
        'productName',
        'permanantIdentifier',
        'productIdentifier',
        'productOwner',
        'authorizationNumber',
        'atcVetCode',
        'authorizationStatus',
        'procedureType',
        'authorizationCountry',
        'targetSpecies',
        '_ct',
        '_count',
        '_summary',
        '_lastUpdated',
    ];

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

    /** @param array<string, string> $parameters */
    public function searchMedicinalProducts(#[SensitiveParameter] array $parameters = []): Bundle
    {
        self::validateSearchParameters($parameters);
        $result = $this->requests->get([self::PRODUCT_RESOURCE], $parameters);

        return new Bundle($result['payload'], $result['requestUri'], searchResult: true);
    }

    public function nextPage(#[SensitiveParameter] Bundle $page): ?Bundle
    {
        $link = $page->getNextLink();
        if ($link === null) {
            return null;
        }

        $result = $this->requests->getFollowing($page->getRequestUri(), $link);

        return new Bundle($result['payload'], $result['requestUri'], searchResult: $page->isSearchResult());
    }

    /** @param array<int|string, mixed> $parameters */
    private static function validateSearchParameters(#[SensitiveParameter] array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            if (!in_array($name, self::SEARCH_PARAMETERS, true)) {
                throw new InvalidArgumentException('The UPD search contains an unknown parameter.');
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('UPD search parameter values must be strings.');
            }
        }
    }
}
