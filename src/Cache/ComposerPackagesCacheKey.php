<?php

declare(strict_types=1);

namespace Efabrica\PHPStanLatte\Cache;

use Composer\InstalledVersions;
use Nette\Utils\Json;
use function class_exists;

final class ComposerPackagesCacheKey
{
    /** @var list<string> */
    private const PACKAGE_NAMES = [
        'efabrica/phpstan-latte',
        'latte/latte',
        'nette/application',
        'nette/bootstrap',
        'nette/caching',
        'nette/component-model',
        'nette/di',
        'nette/finder',
        'nette/forms',
        'nette/http',
        'nette/mail',
        'nette/neon',
        'nette/php-generator',
        'nette/robot-loader',
        'nette/routing',
        'nette/schema',
        'nette/utils',
        'nikic/php-parser',
        'phpstan/phpdoc-parser',
        'phpstan/phpstan',
        'phpstan/phpstan-nette',
    ];

    public static function get(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return '';
        }

        $packages = [];
        foreach (self::PACKAGE_NAMES as $packageName) {
            if (!InstalledVersions::isInstalled($packageName)) {
                continue;
            }

            $packages[$packageName] = [
                'version' => InstalledVersions::getVersion($packageName),
                'reference' => InstalledVersions::getReference($packageName),
            ];
        }

        return Json::encode($packages);
    }
}
