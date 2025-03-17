Dynamic Item Sets (module for Omeka S)
======================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

Voir le [Lisez-moi] en français.

[Dynamic Item Sets] is a module for [Omeka S] that allows to attach
automatically items to item sets via a standard query..

For example, it is possible to create an item set bringing together all the
items with blue color, or all items with type Audio or all items of the 14th
century, or any other query adapted to your item sets.

![Automatic attachment of items to item sets](data/images/auto-attach_items_to_item_sets.png)

Installation
------------

See general end user documentation for [installing a module].

The module [Common] should be installed first.

* From the zip

Download the last release [DynamicItemSets.zip] from the list of releases and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `DynamicItemSets`.

The module [Easy Admin] can be installed to run a reindexation of all item sets.


Usage
-----

To make an item set dynamic, simply define the query that will define its items
via the field in the Advanced tab of item set edition.

When a query is set in the tab Advanced of the item set form, all existing and
new items will be automatically attached to this item set, according to the
request.

Warning: items that are manually attached to the item set will be automatically
detached if they are not in the results of the request.

There is no difference between dynamic item sets and others. So, it may be
useful to use a specific resource model or to add a property with a boolean
(module [Data Type Rdf]) to identify and to use them more easily.

Dynamic item sets can be identified via the advanced search with the "Is dynamic"
radio button or by adding `is_dynamic=1` in the url (https://example.org/admin/item-set?is_dynamic=1)
or exclude them with `is_dynamic=0`.

When the request is removed, the item set become a static one and existing items
remain attached.

When it looks like item sets are not indexed, you can reindex them all via a
task in module [Easy Admin]. Most of the time, the process without events is
enough. The events are useful only to reindex items in an external search
engine, and even in that case, it's better to reindex it separately.


TODO
----

- [x] Add a column in item sets browse.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2023-2025 (see [Daniel-KM] on GitLab)

This feature was initially present in the module [Advanced Resource Template],
before being extracted as an independent module for the [collections de la Maison de Salins].
Improvements were made for the [Curiothèque] of the [Institut Curie].


[Dynamic Item Sets]: https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets
[Lisez-moi]: https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets/-/blob/master/LISEZMOI.md
[Omeka S]: https://omeka.org/s
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Data Type Rdf]: https://gitlab.com/Daniel-KM/Omeka-S-module-DataTypeRdf
[DynamicItemSets.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets/-/releases
[Data Type Rdf]: https://gitlab.com/Daniel-KM/Omeka-S-module-DataTypeRdf/-/releases
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-DynamicItemSets/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: http://opensource.org/licenses/MIT
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[collections de la Maison de Salins]: https://collections.maison-salins.fr/
[Curiothèque]: https://curiotheque.musee.curie.fr/
[Institut Curie]: https://curie.fr/
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
