# The Training Object Cache for WordPress

The Training Object Cache is a tool for learning about and debugging the data stored using the WordPress cache API.

## Introduction

The WordPress cache API, made of up `wp_cache_*` functions like `wp_cache_set()` and `wp_cache_get()`, isn't always easy to learn about. What data are WordPress and your plugins caching, and for how long? When is the cache accessed instead of core database tables?

By default, data put into the cache is stored in a PHP array that lasts only for the life of the request, so the cache will be empty at the start of the next request no matter for how long the data was supposed to be stored.

If a persistent object cache plugin is in use, such as [Memcached](https://wordpress.org/plugins/memcached/), the data will be stored in the persistent cache for quick retrieval by WordPress on subsequent requests, but the data [might not be easy to look at within the object cache itself](https://stackoverflow.com/questions/8420776/how-do-i-view-the-data-in-memcache).

The goal of the Training Object Cache is to make it easier to learn about the data stored in the cache by storing it in a dedicated table in your site's existing database.

For developers who are new to the cache API, having the data stored in the existing database means it can be easily reviewed alongside the default WordPress tables using a database viewer like phpMyAdmin or Sequel Pro, and it means that cache activity can be reviewed using a debugging plugin that monitors database queries like [Query Monitor](https://wordpress.org/plugins/query-monitor/) or [Debug Bar](https://wordpress.org/plugins/debug-bar/).

Even experienced developers browsing the cached data might find data being duplicated across keys, entries taking up more space than expected, or data being cached that isn't supposed to be anymore.

## Installation

1. Clone this repository, or [download the latest version](https://github.com/dlh01/wp-training-object-cache/archive/main.zip).

2. Move `object-cache.php` into your `wp-content` directory.

You should see Training Object Cache listed under `Plugins > Drop-ins` in the Dashboard: `/wp-admin/plugins.php?plugin_status=dropins`.

## Usage

Browse your site, then look for the `wp_training_object_cache` table in your preferred database viewer.

The table contains the following columns:

* `cache_group`: The group given for the cached data. Groups allow keys to be reused across groups.
* `cache_key`: The cache key. In multisite installations, the key is given a site-specific prefix to allow keys to be reused across sites.
* `data`: The cached data. Non-scalar data will be serialized as it is in other database tables.
* `TTL`: The [time to live](https://en.wikipedia.org/wiki/Time_to_live) given for the cached data, if any, made readable via `human_time_diff()`.
* `size`: The approximate size of the cached data, made readable via `size_format()`.
* `expires`: Unix timestamp of when the cached data expires, if ever.

This table is a global table; in a multisite installation, there will not be site-specific instances of it.

## Limitations as a teaching tool

Each object caching drop-in plugin is free to implement caching however it likes. For example, [running `wp_cache_flush()` with the Memcached plugin does not actually flush Memcached](https://plugins.trac.wordpress.org/browser/memcached/tags/3.2.2/object-cache.php#L270). Other caching plugins could choose to modify or ignore data in a manner optimized for their storage mechanisms. Therefore, the behavior of the object cache using this plugin isn't necessarily representative of whether or how data will be stored using other object caching plugins.

## Caution

This plugin is NOT RECOMMENDED for use on a live site!

Apart from increasing load on the database, it has little protection against race conditions, and because [storing scalar values in the database can be lossy](https://core.trac.wordpress.org/ticket/22192), unexpected behavior can occur relative to other persistent caching backends.

## WP-CLI commands

The following WP-CLI commands are available for interacting with the training cache:

* `wp training-object-cache reset`: Delete all cached data, and recreate the database table.
* `wp training-object-cache destroy`: Delete all cached data, and remove the database table.
