<?php

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefixes = [
            'Greenwood\\DeploymentController\\'
                => is_dir(__DIR__ . '/deployment-controller-src')
                    ? __DIR__ . '/deployment-controller-src/'
                    : dirname(__DIR__) . '/deployment-controller/src/',
            'Greenwood\\SystemServices\\RegistryAuth\\'
                => __DIR__ . '/src/',
            'Greenwood\\'
                => is_dir(__DIR__ . '/greenwood-src')
                    ? __DIR__ . '/greenwood-src/' : dirname(__DIR__, 2) . '/src/',
        ];

        foreach ($prefixes as $prefix => $base) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative =
                substr(
                    $class,
                    strlen($prefix)
                );

            $path =
                $base
                . str_replace(
                    '\\',
                    '/',
                    $relative
                )
                . '.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
);
