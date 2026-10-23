<?php

namespace JoliCode\MediaBundle\Metadata\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JoliCode\MediaBundle\Resolver\Resolver;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'joli_media_metadata')]
#[ORM\UniqueConstraint(name: 'joli_media_metadata_library_path', columns: ['library', 'path'])]
class MediaMetadata
{
    public const ALT_MAX_LENGTH = 500;

    public const CAPTION_MAX_LENGTH = 2000;

    public const COPYRIGHT_MAX_LENGTH = 255;

    public const LIBRARY_MAX_LENGTH = 64;

    public const MAX_TAGS = 50;

    public const PATH_MAX_LENGTH = 255;

    public const TAG_MAX_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (set by Doctrine)

    #[ORM\Column(type: Types::STRING, length: self::LIBRARY_MAX_LENGTH)]
    private string $library;

    #[ORM\Column(type: Types::STRING, length: self::PATH_MAX_LENGTH)]
    private string $path;

    #[ORM\Column(type: Types::STRING, length: self::ALT_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ALT_MAX_LENGTH)]
    private ?string $alt = null;

    #[ORM\Column(type: Types::STRING, length: self::CAPTION_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::CAPTION_MAX_LENGTH)]
    private ?string $caption = null;

    #[ORM\Column(type: Types::STRING, length: self::COPYRIGHT_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::COPYRIGHT_MAX_LENGTH)]
    private ?string $copyright = null;

    /**
     * @var Collection<int, MediaMetadataTag>
     */
    #[ORM\OneToMany(targetEntity: MediaMetadataTag::class, mappedBy: 'metadata', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(max: self::MAX_TAGS)]
    #[Assert\Valid]
    private Collection $tags;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $library, string $path)
    {
        $path = Resolver::normalizePath($path);

        if (mb_strlen($library) > self::LIBRARY_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The library name must not exceed %d characters.', self::LIBRARY_MAX_LENGTH));
        }

        if (mb_strlen($path) > self::PATH_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The media path must not exceed %d characters.', self::PATH_MAX_LENGTH));
        }

        $this->library = $library;
        $this->path = $path;
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibrary(): string
    {
        return $this->library;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): void
    {
        $this->alt = $this->normalizeText($alt);
        $this->touch();
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): void
    {
        $this->caption = $this->normalizeText($caption);
        $this->touch();
    }

    public function getCopyright(): ?string
    {
        return $this->copyright;
    }

    public function setCopyright(?string $copyright): void
    {
        $this->copyright = $this->normalizeText($copyright);
        $this->touch();
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return array_values(array_map(static fn (MediaMetadataTag $tag): string => $tag->getName(), $this->tags->toArray()));
    }

    /**
     * Whitespace is collapsed, empty tags and case-insensitive duplicates are dropped (the first spelling wins), the input order is kept.
     *
     * @param iterable<string> $tags
     */
    public function setTags(iterable $tags): void
    {
        $names = [];

        foreach ($tags as $tag) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if ('' === $name) {
                continue;
            }

            $names[mb_strtolower($name)] ??= $name;
        }

        $names = array_values($names);

        // existing tags whose name is kept (ignoring the case) are reused, so the unchanged ones are neither deleted nor inserted again
        $existing = [];
        foreach ($this->tags as $tag) {
            $existing[mb_strtolower($tag->getName())] = $tag;
        }

        $this->tags->clear();

        foreach ($names as $position => $name) {
            if (isset($existing[mb_strtolower($name)])) {
                $tag = $existing[mb_strtolower($name)];
                $tag->setName($name);
                $tag->setPosition($position);
            } else {
                $tag = new MediaMetadataTag($this, $name, $position);
            }

            $this->tags->add($tag);
        }

        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isEmpty(): bool
    {
        return null === $this->alt && null === $this->caption && null === $this->copyright && $this->tags->isEmpty();
    }

    private function normalizeText(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        $text = trim($text);

        return '' === $text ? null : $text;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
