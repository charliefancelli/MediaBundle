<?php

namespace JoliCode\MediaBundle\Metadata;

enum MetadataField: string
{
    case Alt = 'alt';
    case Caption = 'caption';
    case Copyright = 'copyright';
    case Tags = 'tags';
}
