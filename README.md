# Mongo-PHP-ORM (M2)

    Mongo wrapper and ORM extension for PHP
    M2 is a part of propitiatory Homebase framework
    M2 is licensed under MIT license (permissive free software licence)

    Product is in "gamma" stabilisation stage. Scheduled for production deployment in June 2012.

# FEATURES (most important)
* **ORM** with relations, calculated fields, field aliases
* **Type support** (**custom** type support), alternative type-based field representation
* Enum(Hash) field type
* Bells and whistles


# FEATURES
* simple **compact** clutter-less **syntax**
* low level and **ORM** level extensions
  * maps mongo records to objects
  * you can extend this objects : add business logic, getters, setters, calculated fields
* **relation** support (has_one, has_many)
* field **aliases** ( long field names is unnessecary burden for bson based storage)
* **type support** 
  * basic and **complex types** (name, ip, phone, email, url, ...)
  * you can change existing types and your types
  * types are supported on ORM and non ORM levels
* lots of useful functions and shortcuts
  * group by: mix/max/sum
  * index enforcement, mysql migration
* declarative and easy to support config
  * configure autoload fields, in-memory entity caching
* almost no overhead, written with performance in mind
* magic fields - alternative fields(type) representation, for read/write
* protection against mongo injections

# REQUIREMENTS
* php 5.4, APC, Mongo

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
