<?php

declare(strict_types=1);

use GuzzleHttp\TransferStats;

use function Hrabosh\EmaUpd\Examples\createClient;
use function Hrabosh\EmaUpd\Examples\firstProductId;
use function Hrabosh\EmaUpd\Examples\requiredEnvironment;

use Hrabosh\EmaUpd\Exception\UpdException;

require_once __DIR__ . '/bootstrap.php';

try {
    $productName = requiredEnvironment('EMA_UPD_PRODUCT_NAME');
    $permanentIdentifier = requiredEnvironment('EMA_UPD_PERMANENT_IDENTIFIER');
    $operation = 'Search';
    $client = createClient(static function (TransferStats $stats) use (&$operation): void {
        $response = $stats->getResponse();
        if ($response === null) {
            return;
        }

        $label = $stats->getRequest()->getMethod() === 'POST' ? 'Token' : $operation;
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        $mediaType = in_array($mediaType, ['application/json', 'application/fhir+json'], true) ? $mediaType : 'other media type';

        printf("%s HTTP %d (%s)\n", $label, $response->getStatusCode(), $mediaType);
    });

    $first = $client->searchMedicinalProducts(['productName' => $productName, '_count' => '1']);
    if (count($first->getEntries()) !== 1) {
        throw new UnexpectedValueException('Expected one entry from the configured name search with _count=1.');
    }
    $id = firstProductId($first);

    $operation = 'Product lookup';
    $product = $client->getMedicinalProduct($id);
    if ($product->getId() !== $id) {
        throw new UnexpectedValueException('Product lookup did not return the resource ID from search.');
    }

    $names = $product->toArray()['name'] ?? null;
    $nameMatches = false;
    if (is_array($names)) {
        foreach ($names as $name) {
            $candidate = is_array($name) ? ($name['productName'] ?? null) : null;
            if (is_string($candidate) && preg_match('/' . preg_quote($productName, '/') . '/iu', $candidate) === 1) {
                $nameMatches = true;
                break;
            }
        }
    }
    if (!$nameMatches) {
        throw new UnexpectedValueException('The looked-up product name did not contain the configured search text.');
    }
    fwrite(STDOUT, "Product lookup: MedicinalProductDefinition with matching ID and name.\n");

    $operation = 'Related resources';
    $related = $client->getMedicinalProductEverything($id);
    $resourceTypes = [];
    $containsProduct = false;
    foreach ($related->getEntries() as $entry) {
        $resource = $entry['resource'] ?? null;
        if (!is_array($resource)) {
            continue;
        }

        $type = $resource['resourceType'] ?? null;
        if (is_string($type) && preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $type) === 1) {
            $resourceTypes[$type] = true;
        }
        if ($type === 'MedicinalProductDefinition' && ($resource['id'] ?? null) === $id) {
            $containsProduct = true;
        }
    }
    if (!$containsProduct) {
        throw new UnexpectedValueException('The related-resource Bundle did not include the requested product.');
    }
    $types = array_keys($resourceTypes);
    sort($types);
    printf("Related resources: Bundle with %d entries (%s).\n", count($related->getEntries()), implode(', ', $types));

    $operation = 'Next page';
    $second = $client->nextPage($first);
    if ($second === null) {
        throw new UnexpectedValueException('Pagination not verified. Choose a product name that returns multiple pages at _count=1.');
    }
    if (firstProductId($second) === $id) {
        throw new UnexpectedValueException('Pagination not verified. The next page repeated the initial product.');
    }
    printf("Next page: searchset Bundle with a different product. Entry count: %d.\n", count($second->getEntries()));

    $operation = 'Permanent identifier search';
    $identifierResult = $client->searchMedicinalProducts(['permanantIdentifier' => $permanentIdentifier, '_count' => '1']);
    if (firstProductId($identifierResult) !== $id) {
        throw new UnexpectedValueException('The configured permanent identifier did not return the first product from the name search.');
    }

    $operation = 'Unfiltered control';
    $control = $client->searchMedicinalProducts(['_count' => '1']);
    if (firstProductId($control) === $id) {
        throw new UnexpectedValueException('Identifier filtering not verified. Choose a known product other than the first unfiltered result.');
    }
    fwrite(STDOUT, "Permanent identifier search: matching resource ID, different from the unfiltered control.\n");
    fwrite(STDOUT, "Smoke check passed.\n");
} catch (InvalidArgumentException|UnexpectedValueException|UpdException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
