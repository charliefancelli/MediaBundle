<?php

namespace JoliCode\MediaBundle\Bridge\Form\Type;

use JoliCode\MediaBundle\Bridge\Form\DataTransformer\TagsTransformer;
use JoliCode\MediaBundle\Metadata\MetadataField;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MetadataType extends AbstractType
{
    public const TAGS_DATALIST_ID = 'joli-media-tags';

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = $options['fields'];

        if (\in_array(MetadataField::Alt->value, $fields, true)) {
            $builder->add('alt', TextType::class, [
                'required' => false,
                'label' => 'media.metadata.alt',
                'help' => 'media.metadata.alt_help',
                'attr' => ['class' => 'form-control'],
            ]);
        }

        if (\in_array(MetadataField::Caption->value, $fields, true)) {
            $builder->add('caption', TextareaType::class, [
                'required' => false,
                'label' => 'media.metadata.caption',
                'attr' => ['class' => 'form-control', 'rows' => 3],
            ]);
        }

        if (\in_array(MetadataField::Copyright->value, $fields, true)) {
            $builder->add('copyright', TextType::class, [
                'required' => false,
                'label' => 'media.metadata.copyright',
                'attr' => ['class' => 'form-control'],
            ]);
        }

        if (\in_array(MetadataField::Tags->value, $fields, true)) {
            $builder->add(
                $builder->create('tags', TextType::class, [
                    'required' => false,
                    'label' => 'media.metadata.tags',
                    'help' => 'media.metadata.tags_help',
                    'attr' => ['class' => 'form-control', 'list' => self::TAGS_DATALIST_ID],
                ])->addModelTransformer(new TagsTransformer())
            );
        }

        $builder->add('submit', SubmitType::class, [
            'attr' => ['class' => 'btn btn-primary'],
            'label' => 'action.save_metadata',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => MediaMetadata::class])
            ->setRequired('fields')
            ->setAllowedTypes('fields', 'string[]')
        ;
    }
}
