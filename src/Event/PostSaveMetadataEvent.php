<?php

namespace JoliCode\MediaBundle\Event;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;

final class PostSaveMetadataEvent extends MediaEvent
{
    public function __construct(
        public readonly MediaMetadata $metadata,
        public readonly bool $deleted,
    ) {
    }
}
