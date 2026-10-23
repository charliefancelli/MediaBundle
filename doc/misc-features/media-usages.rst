Finding Where a Media Is Used
=============================

JoliMedia can tell where a media is used in your application. The ``MediaUsageFinder`` service lists the Doctrine entities whose `media columns <using-in-doctrine-entities.rst>`_ hold the media, along with the places declared by your own `usage providers`_. The list is used by the admin bridges to show the usages of a media, and to explain why a deletion has been refused.

This feature does not depend on anything else: it works with or without the media metadata feature, and with any admin bridge (or none at all).

The usage finder
----------------

The ``JoliCode\MediaBundle\Usage\MediaUsageFinder`` service (``joli_media.usage_finder``) is public: fetch it from the container, or inject it in your services::

    use JoliCode\MediaBundle\Usage\MediaUsageFinder;

    class MediaReport
    {
        public function __construct(private MediaUsageFinder $usageFinder)
        {
        }

        public function describe(string $path): void
        {
            foreach ($this->usageFinder->find($path) as $usage) {
                echo $usage->label, ' (', $usage->entityClass ?? 'custom', ')', PHP_EOL;
            }
        }
    }

The ``find()`` method takes the path of the media, the name of its library and a limit::

    $usages = $this->usageFinder->find('articles/cover.jpg', 'default', 50);

- ``$path`` is the path of the media in its library.
- ``$libraryName`` is the name of the library. When it is ``null``, the default library is used.
- ``$limit`` is the maximum number of usages returned (50 by default). It applies to the whole list: the Doctrine columns are listed first, then the providers.

The finder returns a list of ``MediaUsage`` objects, built from two sources:

- **Doctrine columns**: every entity field of type ``media`` or ``media_long`` (see `Using in Doctrine Entities <using-in-doctrine-entities.rst>`_) holding the path is reported. The fields are known from the same cached metadata as the `deletion behavior <media-deletion-behavior.rst>`_, and each of them costs one query. The columns do not carry the library name, so their usages are returned for any library. A ``media`` column cannot hold a path longer than 255 characters: such a path is simply not looked up in these columns (use ``media_long`` for longer ones). Without DoctrineBundle, no column is looked up.
- **Providers**: the services implementing ``MediaUsageProviderInterface``, see below. They receive the name of the library.

For a Doctrine column, the ``MediaUsage`` carries the entity class, the field name and the identifier of the entity (the identifiers of a composite key are joined with ``-``). Its label is the entity cast to a string when it implements ``\Stringable``, else something like ``Article #12``.

Each ``MediaUsage`` has the following properties:

``label``
    The text displayed for the usage.

``entityClass``
    The class of the entity holding the media, if any.

``field``
    The name of the field holding the media, if any.

``identifier``
    The identifier of the entity, if any.

``editUrl``
    The URL of the page where the usage can be edited, if any. It is filled by the `MediaUsageUrlGeneratorInterface`_ when an admin bridge provides one.

``blocksDeletion``
    Whether the usage prevents the media from being deleted, see `Preventing the deletion of a used media`_. It is ``true`` for the Doctrine columns configured with the ``RESTRICT`` `deletion behavior <media-deletion-behavior.rst>`_.

Usage providers
---------------

Not every reference to a media is a Doctrine column of type ``media``: the path may be stored in a JSON field, in the content of a CMS block, in an external system... Declare these usages by implementing ``JoliCode\MediaBundle\Usage\MediaUsageProviderInterface``::

    namespace App\Media;

    use App\Repository\BlockRepository;
    use JoliCode\MediaBundle\Usage\MediaUsage;
    use JoliCode\MediaBundle\Usage\MediaUsageProviderInterface;

    class BlockUsageProvider implements MediaUsageProviderInterface
    {
        public function __construct(private BlockRepository $blocks)
        {
        }

        public function getUsages(string $libraryName, string $path): iterable
        {
            // the path is stored in the "settings" JSON column of the blocks
            foreach ($this->blocks->findByMediaPath($path) as $block) {
                yield new MediaUsage(
                    label: \sprintf('Block "%s"', $block->getTitle()),
                    entityClass: $block::class,
                    field: 'settings',
                    identifier: $block->getId(),
                );
            }
        }
    }

With the default autoconfiguration, nothing more is needed: the services implementing the interface are tagged with ``joli_media.media_usage_provider``. Without autoconfiguration, add the tag yourself::

    services:
        App\Media\BlockUsageProvider:
            tags: ['joli_media.media_usage_provider']

Only the ``label`` is required. The ``entityClass`` and the ``identifier`` allow the admin bridges to link the usage to the edit page of the entity (see `MediaUsageUrlGeneratorInterface`_), and ``field`` names the place where the media is stored. A provider is called every time the usages of a media are looked up, including when a media is deleted: keep it fast, and query only what the path needs.

Preventing the deletion of a used media
---------------------------------------

A usage returned by a provider with ``blocksDeletion: true`` prevents the deletion of the media::

    yield new MediaUsage(
        label: \sprintf('Block "%s"', $block->getTitle()),
        entityClass: $block::class,
        field: 'settings',
        identifier: $block->getId(),
        blocksDeletion: true,
    );

The ``UsageDeleteGuardListener`` listens to the ``PreDeleteMediaEvent`` and to the ``PreDeleteFolderEvent`` (see `Events <events.rst>`_). When a provider reports a blocking usage, it throws a ``MediaInUseException``, exactly like a column configured with ``RESTRICT``:

- It runs after the Doctrine delete listeners (priority ``-16``), so the ``RESTRICT`` columns are reported first.
- It works without DoctrineBundle.
- Deleting a folder checks every media it contains (subfolders included), and refuses the deletion of the whole folder when one of them is blocked.
- When no ``PostDeleteMediaEvent`` listener is registered, each file of a folder is checked twice: once for the folder, then for its own deletion.

The message of the exception tells where the media is used. For a usage without ``field``, it reads ``The media "a.jpg" is used by "App\Entity\Block". It cannot be deleted.``, the label being used when the usage has no ``entityClass`` either. Catch the exception as described in `the deletion behavior chapter <media-deletion-behavior.rst>`_.

MediaUsageUrlGeneratorInterface
-------------------------------

``JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface`` is an extension point: its ``generateEditUrl(MediaUsage $usage): ?string`` method returns the URL of the page where a usage can be edited, or ``null`` when there is none. When a service is aliased to this interface, the finder fills the ``editUrl`` of the usages which have none. Admin bridges provide one: the `EasyAdmin bridge <../bridges/easy-admin.rst>`_ does, nothing is registered by default.

A provider can also set the ``editUrl`` of its usages itself, it is then left untouched.

Usages in the admin bridges
---------------------------

The `EasyAdmin bridge <../bridges/easy-admin.rst#usages-of-a-media>`_ displays the usages of a media and the reasons of a refused deletion. The Sonata and Sylius bridges do not use the usage finder yet: a deletion refused because of a provider shows a generic message there.
