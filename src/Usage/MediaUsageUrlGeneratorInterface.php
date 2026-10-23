<?php

namespace JoliCode\MediaBundle\Usage;

interface MediaUsageUrlGeneratorInterface
{
    /**
     * Returns the URL of the page where the usage can be edited, or null when there is none.
     */
    public function generateEditUrl(MediaUsage $usage): ?string;
}
