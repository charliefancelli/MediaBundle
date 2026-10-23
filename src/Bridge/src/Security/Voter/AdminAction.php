<?php

namespace JoliCode\MediaBundle\Bridge\Security\Voter;

readonly class AdminAction
{
    public const LIST = 'joli_media_list';

    public const SHOW = 'joli_media_show';

    public const CREATE_DIRECTORY = 'joli_media_create_directory';

    public const DELETE = 'joli_media_delete';

    public const DELETE_DIRECTORY = 'joli_media_delete_directory';

    public const MOVE = 'joli_media_move';

    public const RENAME_DIRECTORY = 'joli_media_rename_directory';

    public const UPLOAD = 'joli_media_upload';

    public const REGENERATE_VARIATION = 'joli_media_regenerate_variation';

    public const EDIT_METADATA = 'joli_media_edit_metadata';

    public const ALL = [
        self::LIST,
        self::SHOW,
        self::CREATE_DIRECTORY,
        self::DELETE,
        self::DELETE_DIRECTORY,
        self::MOVE,
        self::RENAME_DIRECTORY,
        self::UPLOAD,
        self::REGENERATE_VARIATION,
        self::EDIT_METADATA,
    ];

    public function __construct(
        public ?string $libraryName = null,
        public ?string $path = null,
        public ?string $to = null,
        public ?string $variation = null,
    ) {
    }
}
