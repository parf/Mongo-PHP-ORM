# Mongo-PHP-ORM (M2)

    This is incarnation 2 of Homebase framework mongo ORM.
    Product is in beta/stabilisation stage. Not used in production yet.
    It still depends on some Homebase framework classes and functions
    By Jun 7 2012 we'll provide standalone version

# FEATURES (most important)
* **ORM** with relations, calc fields, ...
* **Type support** (complex/custom type support)
* Bells and whistles


# FEATURES
* simple **compact** clutter-less **syntax**
* low level and **ORM** level extensions
  * maps mongo records to objects
  * you can extend this objects : add business logic, getters, setters, calculated fields
* **relation** support (has_one, has_many)
* field **aliases** ( long keys be unnessecary burden for bson based storage)
* **type support** 
  * basic and complex types (name, ip, phone, email, url, ...)
  * you can extend/change/add your types
  * types are used for queries and updates / inserts
  * types are supported on ORM and non ORM levels
* lots of useful functions and shortcuts
  * group by, mix/max/avg/sum/...
  * index enforcement, mysql migration
* declarative and easy to support config
  * configure autoload fields, in-memory entity caching
* almost no overhead, written with performance in mind
* magic fields - alternative fields(type) representation, for read/write

# [MORE DETAILS >>](https://github.com/parf/Mongo-PHP-ORM/wiki)

AUTHOR
------
  Sergey Porfiriev <parf@comfi.com>

COPYRIGHT
---------
  (C) 2010-2012 Comfi.com

LICENSE
-------
  The MIT License (MIT) - http://www.opensource.org/licenses/mit-license.php
