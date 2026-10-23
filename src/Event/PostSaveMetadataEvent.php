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
     *                                never stored, or whose row is already gone, is ignored). Limit: an empty
     *                                detached instance whose row was moved since reports false, although the
     *                                Doctrine storage deletes the moved row (it finds it by id)
     */
    public function __construct(
        public readonly MediaMetadata $metadata,
        public readonly bool $deleted,
    ) {
    }
}
