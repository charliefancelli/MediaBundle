<?php

namespace JoliCode\MediaBundle\Usage;

/**
 * Declares where a media is used outside of the Doctrine columns of type "media" (a CMS block, a JSON field...).
 *
 * Implementations are autoconfigured with the "joli_media.media_usage_provider" tag.
 */
interface MediaUsageProviderInterface
{
    /**
     * @return iterable<MediaUsage>
     */
    public function getUsages(string $libraryName, string $path): iterable;
}
