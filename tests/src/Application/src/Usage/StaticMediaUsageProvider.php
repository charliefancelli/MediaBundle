<?php

namespace JoliCode\MediaBundle\Tests\Application\Usage;

use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageProviderInterface;

/**
 * Test provider: holds no usage by default, each test sets the ones it needs (and must reset them).
 */
class StaticMediaUsageProvider implements MediaUsageProviderInterface
{
    /**
     * @var array<string, list<MediaUsage>>
     */
    private static array $usages = [];

    /**
     * @param list<MediaUsage> $usages
     */
    public static function setUsages(string $libraryName, string $path, array $usages): void
    {
        self::$usages[$libraryName . '|' . $path] = $usages;
    }

    public static function reset(): void
    {
        self::$usages = [];
    }

    public function getUsages(string $libraryName, string $path): iterable
    {
        return self::$usages[$libraryName . '|' . $path] ?? [];
    }
}
