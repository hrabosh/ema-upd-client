<?php

declare(strict_types=1);

use function Hrabosh\EmaUpd\Examples\createClient;
use function Hrabosh\EmaUpd\Examples\firstProductId;
use function Hrabosh\EmaUpd\Examples\requiredEnvironment;

use Hrabosh\EmaUpd\Exception\UpdException;

require_once __DIR__ . '/bootstrap.php';

try {
    $productName = requiredEnvironment('EMA_UPD_PRODUCT_NAME');
    $client = createClient();

    $page = $client->searchMedicinalProducts(['productName' => $productName, '_count' => '1']);
    $id = firstProductId($page);
    $product = $client->getMedicinalProduct($id);
    if ($product->getId() !== $id) {
        throw new UnexpectedValueException('Product lookup did not return the resource ID from search.');
    }
    $related = $client->getMedicinalProductEverything($id);

    printf("Search entry count: %d.\n", count($page->getEntries()));
    fwrite(STDOUT, "Product lookup returned the requested ID.\n");
    printf("The related-resource Bundle contains %d entries.\n", count($related->getEntries()));
} catch (InvalidArgumentException|UnexpectedValueException|UpdException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
