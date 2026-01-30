<?php

declare(strict_types=1);

// Always resolve from this file, not from CWD
$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use App\App\Bootstrap;

Bootstrap::run();
