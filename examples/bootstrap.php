<?php

declare(strict_types=1);

namespace Hrabosh\EmaUpd\Examples;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\TransferStats;
use Hrabosh\EmaUpd\Auth\ClientCredentials;
use Hrabosh\EmaUpd\Auth\ClientCredentialsTokenProvider;
use Hrabosh\EmaUpd\Model\Bundle;
use Hrabosh\EmaUpd\UpdClient;
use InvalidArgumentException;
use UnexpectedValueException;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    // In a consumer install, this file lives at vendor/hrabosh/ema-upd-client/examples.
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
if (!is_file($autoload)) {
    fwrite(STDERR, "Install the Composer dependencies before running this example.\n");
    exit(1);
}
require_once $autoload;

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new InvalidArgumentException("Set {$name} in the environment.");
    }

    return $value;
}

/** @param (Closure(TransferStats): void)|null $onStats */
function createClient(?Closure $onStats = null): UpdClient
{
    $credentials = new ClientCredentials(
        clientId: requiredEnvironment('EMA_UPD_CLIENT_ID'),
        clientSecret: requiredEnvironment('EMA_UPD_CLIENT_SECRET'),
        tokenEndpoint: requiredEnvironment('EMA_UPD_TOKEN_ENDPOINT'),
        scope: requiredEnvironment('EMA_UPD_SCOPE'),
    );

    if (!class_exists(Client::class) || !class_exists(HttpFactory::class)) {
        throw new InvalidArgumentException('Install guzzlehttp/guzzle and guzzlehttp/psr7 to run this example.');
    }

    $factory = new HttpFactory();
    $http = new Client([
        'connect_timeout' => 5.0,
        'timeout' => 30.0,
        'verify' => true,
        'allow_redirects' => false,
        'on_stats' => $onStats,
    ]);
    $tokens = new ClientCredentialsTokenProvider($credentials, $http, $factory, $factory);
    $baseUri = getenv('EMA_UPD_BASE_URI');

    return $baseUri === false || $baseUri === ''
        ? new UpdClient($http, $factory, $tokens)
        : new UpdClient($http, $factory, $tokens, $baseUri);
}

function firstProductId(Bundle $page): string
{
    foreach ($page->getEntries() as $entry) {
        $resource = $entry['resource'] ?? null;
        if (is_array($resource) && ($resource['resourceType'] ?? null) === 'MedicinalProductDefinition') {
            $id = $resource['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }
    }

    throw new UnexpectedValueException('The search page contains no product with a resource ID.');
}
