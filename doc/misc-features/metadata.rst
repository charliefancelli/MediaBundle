Media Metadata
==============

JoliMedia media are files identified by their path: out of the box, there is nowhere to store an alternative text, a caption, a copyright or some tags. The optional metadata feature adds them, stored in your database, next to the files.

The feature is **disabled by default**. While it is disabled, the bundle behaves exactly as it did before: no service is registered, no Doctrine mapping is added, ``Media::getMetadata()`` returns ``null`` and the Twig filters return their fallback.

Enabling the Metadata
---------------------

The default ``doctrine`` storage requires the Doctrine ORM and the DoctrineBundle, which are suggested (``suggest`` section of ``composer.json``), not required, by the bundle:

.. code-block:: terminal

    $ composer require doctrine/orm doctrine/doctrine-bundle

Then enable the feature:

.. code-block:: yaml

    # filepath: config/packages/joli_media.yaml
    joli_media:
        metadata:
            enabled: true
            storage: doctrine # the default; or the id of your own service
            entity_manager: ~ # the default entity manager; or the name of another one
            fields: [alt, caption, copyright, tags] # the default: the fields offered for edition

- ``storage``: ``doctrine`` (the default), or the id of a service implementing ``MetadataStorageInterface`` - see `Custom storage`_.
- ``entity_manager``: the name of the Doctrine entity manager that stores the metadata. It defaults to the default entity manager. See `Transactions`_.
- ``fields``: the metadata fields that are available: the other ones are not offered for edition in the admin bridges.

Enabling the feature with the ``doctrine`` storage and without the Doctrine ORM stops the container build with an explicit error.

The feature adds two tables, ``joli_media_metadata`` and ``joli_media_metadata_tag``. Generate and run the migration, as for any other mapped entity:

.. code-block:: terminal

    $ php bin/console doctrine:migrations:diff
    $ php bin/console doctrine:migrations:migrate

Without Doctrine Migrations, ``php bin/console doctrine:schema:update --force`` does the job.

The Model
---------

The metadata of a media are held by a ``JoliCode\MediaBundle\Metadata\Model\MediaMetadata`` entity, unique for a given library and path:

================ ===================================================================== ==============
Field            Role                                                                  Maximum size
================ ===================================================================== ==============
``library``      the name of the library of the media                                  64
``path``         the path of the media                                                 255
``alt``          the alternative text, plain text                                      500
``caption``      the caption, plain text                                               2000
``copyright``    the copyright, or credit, plain text                                  255
``tags``         a list of strings                                                     50 tags of 100
``createdAt``    when the row was created
``updatedAt``    when the row was last saved
================ ===================================================================== ==============

A row only exists when at least one field is filled in: saving a ``MediaMetadata`` whose fields are all empty deletes its row. An empty ``alt``, ``caption`` or ``copyright`` is stored as ``null``.

The tags are normalized: the whitespace is collapsed, the empty tags are dropped, and the duplicates are dropped too, regardless of their case (the first spelling is kept, and the order is preserved). The tags are stored in a table of their own, so that they can be searched and suggested with a plain SQL query.

.. note::

    A path longer than 255 characters cannot have metadata: reading them returns ``null``, and the EasyAdmin bridge displays a message instead of the form.

.. note::

    The paths and the tags are compared by the database: the comparison follows its collation, so it is case and accent insensitive with the default ``*_ci`` collations of MySQL.

    With such a collation, two tags of a media which only differ by their accents (``resume`` and ``résumé``) collide on the unique key of the tags table: the save fails (the EasyAdmin bridge displays its generic failure message, and logs the error). The tags differing by their case are already merged by the normalization.

Reading the Metadata
--------------------

From a ``Media``
~~~~~~~~~~~~~~~~

``Media::getMetadata()`` returns the ``MediaMetadata`` of the media, or ``null`` when it has none (or when the feature is disabled). Nothing is queried until you call it::

    $metadata = $media->getMetadata();

    $metadata?->getAlt();
    $metadata?->getCaption();
    $metadata?->getCopyright();
    $metadata?->getTags(); // a list of strings

From the ``MetadataProvider`` service
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The ``JoliCode\MediaBundle\Metadata\MetadataProvider`` service reads the metadata of a ``Media``, a ``MediaVariation`` or a path (of the default library, unless a library name is given). It memoizes what it reads, absences included, until the end of the request.

``getMany()`` reads the metadata of a whole list with one query, which avoids the "N+1" queries when displaying a gallery::

    use JoliCode\MediaBundle\Metadata\MetadataProvider;
    use JoliCode\MediaBundle\Storage\OriginalStorage;
    use Symfony\Component\HttpFoundation\Response;

    class GalleryController
    {
        public function __construct(
            private readonly MetadataProvider $metadataProvider,
            private readonly OriginalStorage $originalStorage,
        ) {
        }

        public function __invoke(): Response
        {
            $medias = $this->originalStorage->listMedias('gallery');

            // the metadata, indexed by path: the media without metadata are absent
            $metadata = $this->metadataProvider->getMany($medias);

            // a single media, or a path
            $one = $this->metadataProvider->get('gallery/sunset.jpg');
            $other = $this->metadataProvider->get('sunset.jpg', 'another_library');

            // ...
        }
    }

The results of ``getMany()`` are indexed by path only: when the media belong to several libraries, two media with the same path collide. Call it once per library in that case.

In Twig
~~~~~~~

The Twig extension of the bundle provides one filter per field, and a filter returning the whole ``MediaMetadata``. They accept a ``Media``, a ``MediaVariation`` or a path, and they never fail: without metadata, they return their fallback.

.. code-block:: html+twig

    {{ media|joli_media_alt }}
    {{ media|joli_media_alt('A picture of the sunset') }}
    {{ media|joli_media_caption('No caption') }}
    {{ media|joli_media_copyright }}

    {% for tag in media|joli_media_tags %}
        <li>{{ tag }}</li>
    {% endfor %}

    {% set metadata = media|joli_media_metadata %}

``joli_media_alt`` and ``joli_media_caption`` take the value to return when there is nothing stored as their first argument (``null`` by default), ``joli_media_copyright`` returns ``null`` and ``joli_media_tags`` an empty list. Every filter takes the name of the library as its last argument, for the media given by their path in a library which is not the default one.

Automatic alternative text
~~~~~~~~~~~~~~~~~~~~~~~~~~

The ``joli:Img`` and ``joli:Picture`` components use the stored alternative text of the media when you do not provide an ``alt`` attribute - see the `Twig components documentation <twig-components.rst>`_:

- ``alt`` not given: the stored alternative text is used (or nothing, if there is none);
- ``alt="Some text"``: the text you gave is used;
- ``alt=""``: the image is decorative, and the empty ``alt`` is kept as is.

An ``alt`` given as an attribute of the ``img`` tag (``img:alt`` on ``joli:Picture`` or ``joli:Img``) wins over the stored one too. When the feature is enabled, a ``joli:Picture`` without ``alt`` no longer outputs a bare ``alt`` attribute (without value) on its ``img`` tag: it outputs the stored alternative text, or no ``alt`` attribute at all. When the feature is disabled, the markup of both components is unchanged.

Each component reads the metadata of its media when it is rendered: displaying N components queries the storage N times, unless their metadata were read beforehand. Read them with ``MetadataProvider::getMany()`` in the controller: the provider memoizes them, and the components then query nothing::

    public function __invoke(): Response
    {
        $medias = $this->originalStorage->listMedias('gallery');

        // one query, the <twig:joli:Img> of the template then reuse its results
        $this->metadataProvider->getMany($medias);

        return $this->render('gallery.html.twig', ['medias' => $medias]);
    }

Writing the Metadata
--------------------

The ``JoliCode\MediaBundle\Metadata\MetadataManager`` service saves a ``MediaMetadata``. Start from the stored one, and create a new ``MediaMetadata`` when there is none. A new instance for a library and a path which already have a row is merged onto it: its fields replace the stored ones (an empty field empties the stored one)::

    use JoliCode\MediaBundle\Metadata\MetadataManager;
    use JoliCode\MediaBundle\Metadata\MetadataProvider;
    use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
    use JoliCode\MediaBundle\Model\Media;

    class MetadataUpdater
    {
        public function __construct(
            private readonly MetadataProvider $metadataProvider,
            private readonly MetadataManager $metadataManager,
        ) {
        }

        public function update(Media $media): void
        {
            $metadata = $this->metadataProvider->get($media)
                ?? new MediaMetadata($media->getLibrary()->getName(), $media->getPath());

            $metadata->setAlt('A picture of the sunset');
            $metadata->setCopyright('Jane Doe');
            $metadata->setTags(['sunset', 'beach']);

            $this->metadataManager->save($metadata);
        }
    }

Saving a ``MediaMetadata`` with every field empty deletes its row (saving an empty ``MediaMetadata`` which was never stored does nothing).

.. warning::

    With the Doctrine storage, ``MetadataManager::save()`` flushes the whole entity manager, not only the metadata: everything that is pending in it is written too.

Once the metadata are saved, the ``MediaEvents::POST_SAVE_METADATA`` event (``joli.media.post_save_metadata``) is dispatched with a ``PostSaveMetadataEvent``, giving the ``metadata`` passed to ``save()`` and whether a stored row was ``deleted`` (``false`` for an empty ``MediaMetadata`` which was never stored, or whose row is already gone). The ``metadata`` is the given instance, not always the stored one: the EasyAdmin bridge saves an unmanaged copy, whose ``getId()`` is ``null``. Use it, for example, to purge an HTTP cache - see the `events documentation <events.rst>`_. It is only dispatched by ``MetadataManager::save()``: a move or a deletion of a media dispatches the ``POST_MOVE_*`` and ``POST_DELETE_*`` events, as always.

Moves and Deletions
-------------------

The metadata follow their media: when a media or a folder is moved or renamed, the metadata move along with it, and when a media or a folder is deleted, its metadata are deleted. There is nothing to do on your side, and nothing to listen to.

- With the DoctrineBundle, the listeners that already keep the ``MediaType`` columns up to date also move and delete the metadata, while their transaction is open (the metadata join it when they use the same connection, see `Transactions`_).
- Without the DoctrineBundle, those listeners do not exist: a ``MetadataFollowListener`` does the same after the ``POST_MOVE_*`` and ``POST_DELETE_*`` events.

This holds for any storage, your own included: the listener that runs depends on the DoctrineBundle being installed, not on the storage.

If the target of a move already carries metadata (the metadata of a file that was deleted outside of JoliMedia, for instance), they are replaced by the ones of the moved media. Metadata are attached to a library and a path: moving ``a.png`` in a library does not touch the ``a.png`` of another one. Moving or deleting a folder never touches the metadata of its sibling folders, even when their names start with the same characters.

Renaming a media or a folder by changing only the case or the accents of its name (``A.png`` to ``a.png``) keeps its metadata, even with a case or accent insensitive collation. A media moved to a path longer than 255 characters loses its metadata, as such a path cannot have any (see `The Model`_); so do the media of a moved folder whose new path gets too long.

Transactions
~~~~~~~~~~~~

The metadata move or deletion shares the transaction of the ``MediaType`` columns updates when the entity manager of the metadata (the ``entity_manager`` option) uses the same connection as the entities holding ``MediaType`` columns. Otherwise, the metadata are committed on their own. A custom storage joins this transaction only if it writes through that same connection; any other storage (a file, an external service) commits on its own, and is not rolled back when the move fails.

As for the ``MediaType`` columns, an exception thrown by a listener that runs **after** the ones of the bundle rolls the file back, but not the database.

Custom Storage
--------------

The metadata are read and written through ``JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface``. The Doctrine implementation is the only one provided, but you can replace it, for example to keep the metadata in a companion file or in an external service. Implement the interface::

    use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
    use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;

    class AppMetadataStorage implements MetadataStorageInterface
    {
        public function get(string $library, string $path): ?MediaMetadata
        {
            // ...
        }

        // getMany(), save(), delete(), deleteFolder(), move(), moveFolder(), listTags() and findPathsByTag()
    }

Then reference the service:

.. code-block:: yaml

    # filepath: config/packages/joli_media.yaml
    joli_media:
        metadata:
            enabled: true
            storage: app.my_metadata_storage

The bundle then registers neither the Doctrine mapping nor the Doctrine storage, so the ORM is not required. The metadata still follow the moves and deletions of the media, through the bundle's move and delete listeners, or through the ``MetadataFollowListener`` when the DoctrineBundle is absent (see `Moves and Deletions`_): they call your storage. Its writes share the transaction of the listeners only if it writes through the same Doctrine connection (see `Transactions`_).

Your ``save()`` must merge an instance which is not the stored one (a copy, or a new instance for a library and a path which already have a row) onto the stored row, as the EasyAdmin bridge saves a copy of the metadata - see the docblocks of ``MetadataStorageInterface``.

EasyAdmin
---------

The `EasyAdmin bridge <../bridges/easy-admin.rst>`_ edits the metadata: it adds a "Metadata" tab to the page of a media, a marker of the images without alternative text in the list, and a filter by tag. See its documentation for the details.

The tags are entered in a text field, separated by commas. The existing tags of the library are suggested by the browser (an HTML ``datalist``, without JavaScript), but only for the first tag of the list: browsers suggest values for the whole field, not for each comma-separated item.

The filter by tag checks that each tagged media still exists, once per request for each media carrying the tag below the current folder (and matching the search): its cost grows with the number of media carrying the tag, not with the size of the library.

.. note::

    The Sonata and Sylius bridges do not edit the metadata yet. The metadata services, the Twig filters and the components do not depend on any bridge, so these bridges can use them as they are.
