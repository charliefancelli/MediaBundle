<?php

namespace JoliCode\MediaBundle\Event;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;

final class PostSaveMetadataEvent extends MediaEvent
{
    /**
     * @param bool $deleted true when the saved metadata were empty and a stored row was deleted, false when an empty
     *                      metadata which was never stored was ignored
     */
    public function __construct(
        public readonly MediaMetadata $metadata,
        public readonly bool $deleted,
    ) {
    }
}
