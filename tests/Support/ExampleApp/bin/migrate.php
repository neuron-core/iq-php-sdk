<?php

declare(strict_types=1);

/*
 * The migrations of the shop, the first step of a deploy: php bin/migrate.php
 */

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

use function dirname;
use function getenv;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

(new Home((string) getenv('SHOP_HOME')))->install();
