<?php

use App\Kitchen\app\Services\Production\ApiSalesProvider;
use App\Kitchen\app\Services\Production\PosSalesProviderInterface;
use App\Kitchen\core\Http\ApiClient;
use DI\ContainerBuilder;
use function DI\autowire;
use function DI\get;

require_once __DIR__ . '/../../../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    ApiClient::class => autowire()->constructorParameter('baseUrl', API_BASE_URL),

    // Les ventes qui nourrissent la prévision viennent du back-office, jamais
    // de la caisse en direct. L'interface reste le point de bascule si le
    // réseau change de POS — et c'est elle qui permet de substituer un jeu de
    // données figé dans bin/forecast-test.php.
    PosSalesProviderInterface::class => get(ApiSalesProvider::class),
]);

return $containerBuilder->build();
