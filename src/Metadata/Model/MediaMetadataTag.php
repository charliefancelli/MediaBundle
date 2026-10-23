<?php

namespace JoliCode\MediaBundle\Metadata\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'joli_media_metadata_tag')]
#[ORM\UniqueConstraint(name: 'joli_media_metadata_tag_name', columns: ['metadata_id', 'name'])]
class MediaMetadataTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (set by Doctrine)

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MediaMetadata::class, inversedBy: 'tags')]
        #[ORM\JoinColumn(name: 'metadata_id', nullable: false, onDelete: 'CASCADE')]
        private MediaMetadata $metadata,
        #[ORM\Column(type: Types::STRING, length: MediaMetadata::TAG_MAX_LENGTH)]
        #[Assert\Length(max: MediaMetadata::TAG_MAX_LENGTH)]
        private string $name,
        /**
         * Position of the tag in the list, to keep the order in which the tags were entered.
         */
        #[ORM\Column(type: Types::INTEGER)]
        private int $position,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetadata(): MediaMetadata
    {
        return $this->metadata;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
