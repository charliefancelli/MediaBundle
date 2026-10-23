<?php

namespace JoliCode\MediaBundle\Event;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;

final class PostSaveMetadataEvent extends MediaEvent
{
    /**
     * @param MediaMetadata $metadata the instance given to MetadataManager::save(), which may not be the stored one:
     *                                the EasyAdmin bridge saves an unmanaged copy (its getId() is null)
     * @param bool          $deleted  true when the metadata were empty and a row was stored at their library and path
     *                                before the save (so it was deleted), false otherwise (an empty metadata which was
     *                                never stored, or whose row is already gone, is ignored)
     */
    public function __construct(
        public readonly MediaMetadata $metadata,
        public readonly bool $deleted,
    ) {
    }
}
