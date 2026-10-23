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
     * @var list<string>
     */
    private static array $calls = [];

    /**
     * @param list<MediaUsage> $usages
     */
    public static function setUsages(string $libraryName, string $path, array $usages): void
    {
        self::$usages[$libraryName . '|' . $path] = $usages;
    }

    /**
     * @return list<string> the "library|path" of each lookup since the last reset
     */
    public static function getCalls(): array
    {
        return self::$calls;
    }

    public static function reset(): void
    {
        self::$usages = [];
        self::$calls = [];
    }

    public function getUsages(string $libraryName, string $path): iterable
    {
        self::$calls[] = $libraryName . '|' . $path;

        return self::$usages[$libraryName . '|' . $path] ?? [];
    }
}
