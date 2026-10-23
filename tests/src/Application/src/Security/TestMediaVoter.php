<?php

namespace JoliCode\MediaBundle\Tests\Application\Security;

use JoliCode\MediaBundle\Bridge\Security\Voter\MediaVoter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Replaces the bridge voter in the test application, so that tests can deny the metadata edition.
 */
class TestMediaVoter extends MediaVoter
{
    public static bool $denyEditMetadata = false;

    #[\Override]
    protected function canEditMetadata(?UserInterface $user, string $libraryName, string $path): bool
    {
        return !self::$denyEditMetadata;
    }
}
