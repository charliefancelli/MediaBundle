<?php

namespace JoliCode\MediaBundle\Exception;

class MediaInUseException extends \RuntimeException
{
    public function __construct(
        private readonly string $path,
        private readonly string $entityName,
        private readonly string $propertyName,
    ) {
        parent::__construct($this->format());
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * The entity class, or the label of the usage when a provider reported one without entity class.
     */
    public function getEntityName(): string
    {
        return $this->entityName;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    private function format(): string
    {
        if ('' === $this->propertyName) {
            return \sprintf(
                'The media "%s" is used by "%s". It cannot be deleted.',
                $this->path,
                $this->entityName,
            );
        }

        return \sprintf(
            'The media "%s" is used in the "%s" field of the "%s" entity. It cannot be deleted.',
            $this->path,
            $this->propertyName,
            $this->entityName,
        );
    }
}
