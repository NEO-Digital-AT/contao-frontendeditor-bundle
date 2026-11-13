<?php

declare(strict_types=1);

/*
 * Autoloader of the tests: Composer's of this package (composer install), or
 * the one of another installation given in NEO_TEST_AUTOLOAD (to run the tests
 * against an existing Contao installation without installing it again). The
 * classes of this package always come from this folder.
 */
$autoload = getenv('NEO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run \"composer install\" first (no {$autoload}).\n");
    exit(1);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Neo\\FrontendEditorBundle\\', dirname(__DIR__).'/src/', true);
$loader->addPsr4('Neo\\FrontendEditorBundle\\Tests\\', __DIR__.'/', true);
