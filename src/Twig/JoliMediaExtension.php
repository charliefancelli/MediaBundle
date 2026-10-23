<?php

namespace JoliCode\MediaBundle\Twig;

use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Model\Media;
use JoliCode\MediaBundle\Model\MediaVariation;
use JoliCode\MediaBundle\Model\Srcset;
use JoliCode\MediaBundle\Resolver\Resolver;
use JoliCode\MediaBundle\Srcset\SrcsetBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class JoliMediaExtension extends AbstractExtension
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly SrcsetBuilder $srcsetBuilder,
        private readonly ?MetadataProvider $metadataProvider = null,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('joli_media', $this->getMediaVariation(...)),
            new TwigFilter('joli_media_url', $this->getUrl(...)),
            new TwigFilter('joli_media_absolute_url', $this->getAbsoluteUrl(...)),
            new TwigFilter('joli_media_srcset', $this->getSrcset(...)),
            new TwigFilter('joli_media_alt', $this->getAlt(...)),
            new TwigFilter('joli_media_caption', $this->getCaption(...)),
            new TwigFilter('joli_media_copyright', $this->getCopyright(...)),
            new TwigFilter('joli_media_tags', $this->getTags(...)),
            new TwigFilter('joli_media_metadata', $this->getMetadata(...)),
        ];
    }

    private function getAlt(
        string|Media|MediaVariation $media,
        ?string $fallback = null,
        ?string $libraryName = null,
    ): ?string {
        return $this->getMetadata($media, $libraryName)?->getAlt() ?? $fallback;
    }

    private function getCaption(
        string|Media|MediaVariation $media,
        ?string $fallback = null,
        ?string $libraryName = null,
    ): ?string {
        return $this->getMetadata($media, $libraryName)?->getCaption() ?? $fallback;
    }

    private function getCopyright(
        string|Media|MediaVariation $media,
        ?string $libraryName = null,
    ): ?string {
        return $this->getMetadata($media, $libraryName)?->getCopyright();
    }

    /**
     * @return list<string>
     */
    private function getTags(
        string|Media|MediaVariation $media,
        ?string $libraryName = null,
    ): array {
        return $this->getMetadata($media, $libraryName)?->getTags() ?? [];
    }

    private function getMetadata(
        string|Media|MediaVariation $media,
        ?string $libraryName = null,
    ): ?MediaMetadata {
        return $this->metadataProvider?->get($media, $libraryName);
    }

    /**
     * @param string[]|array<string, string> $variations a list of variation names, or a map of descriptors to variation names
     */
    private function getSrcset(
        string|Media|MediaVariation $path,
        array $variations,
        ?string $libraryName = null,
    ): Srcset {
        if ($path instanceof MediaVariation) {
            $path = $path->getMedia();
        }

        if ($path instanceof Media) {
            $libraryName ??= $path->getLibrary()->getName();
        }

        return array_is_list($variations)
            ? $this->srcsetBuilder->build($path, $variations, $libraryName)
            : $this->srcsetBuilder->buildWithDescriptors($path, $variations, $libraryName);
    }

    private function getAbsoluteUrl(
        string|Media|MediaVariation $path,
        ?string $variationName = null,
        ?string $libraryName = null,
    ): ?string {
        return $this->getUrl($path, $variationName, $libraryName, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function getUrl(
        string|Media|MediaVariation $path,
        ?string $variationName = null,
        ?string $libraryName = null,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): ?string {
        return $this->getMediaVariation($path, $variationName, $libraryName)?->getUrl($referenceType);
    }

    private function getMediaVariation(
        string|Media|MediaVariation $path,
        ?string $variationName = null,
        ?string $libraryName = null,
    ): MediaVariation|Media|null {
        if (null !== $variationName) {
            if (\is_string($path)) {
                $mediaVariation = $this->resolver->resolve($path, $libraryName, $variationName);
            } elseif ($path instanceof Media) {
                $mediaVariation = $this->resolver->resolveMediaVariation($path->getPath(), $variationName, $libraryName ?? $path->getLibrary()->getName());
            } else {
                $mediaVariation = $this->resolver->resolveMediaVariation($path->getMedia()->getPath(), $variationName, $libraryName ?? $path->getMedia()->getLibrary()->getName());
            }

            if (null !== $mediaVariation) {
                return $mediaVariation;
            }
        }

        if ($path instanceof Media) {
            return $path;
        }

        if ($path instanceof MediaVariation) {
            return $path->getMedia();
        }

        // either no variation name was passed or the variation could not be
        // resolved (it maybe the variation is not processable for this media),
        // let's try to resolve the original media
        return $this->resolver->resolve($path, $libraryName);
    }
}
