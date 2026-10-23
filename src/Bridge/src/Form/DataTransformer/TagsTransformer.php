<?php

namespace JoliCode\MediaBundle\Bridge\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Turns the list of tags into a comma-separated string, and back.
 *
 * The normalization of the tags (whitespace, empty values, duplicates) is the job of MediaMetadata::setTags().
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
readonly class TagsTransformer implements DataTransformerInterface
{
    #[\Override]
    public function transform(mixed $value): string
    {
        return implode(', ', $value ?? []);
    }

    #[\Override]
    public function reverseTransform(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        if (!\is_string($value)) { // @phpstan-ignore function.alreadyNarrowedType (the interface is not strictly typed)
            throw new TransformationFailedException('Expected a string or null.');
        }

        return explode(',', $value);
    }
}
