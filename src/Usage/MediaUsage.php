<?php

namespace JoliCode\MediaBundle\Usage;

/**
 * A place where a media is used, e.g. a Doctrine entity column or a provider-defined reference.
 */
readonly class MediaUsage
{
    /**
     * @param class-string|null $entityClass
     */
    public function __construct(
        public string $label,
        public ?string $entityClass = null,
        public ?string $field = null,
        public int|string|null $identifier = null,
        public ?string $editUrl = null,
        public bool $blocksDeletion = false,
    ) {
    }

    public function withEditUrl(?string $editUrl): self
    {
        return new self(
            $this->label,
            $this->entityClass,
            $this->field,
            $this->identifier,
            $editUrl,
            $this->blocksDeletion,
        );
    }
}
