<?php
/**
 * Plugin Name:     Training Object Cache
 * Plugin URI:      https://github.com/dlh01/wp-training-object-cache
 * Description:     Learn about and debug the data in the WordPress cache API.
 * Author:          David Herrera
 * Version:         1.0.0
 * License:         GPLv2 or later
 */

/*
 * Copyright (C) 2021
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

class WP_Object_Cache {
	/**
	 * Holds the cached objects.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Whether records exist in the cache.
	 *
	 * @var array
	 */
	private $not_cached = array();

	/**
	 * The amount of times the cache data was already stored in the cache.
	 *
	 * @var int[]
	 */
	private $cache_hits = [];

	/**
	 * Amount of times the cache did not have the request in cache.
	 *
	 * @var int[]
	 */
	private $cache_misses = [];

	/**
	 * List of global cache groups.
	 *
	 * @var string[]
	 */
	private $global_groups = array();

	/**
	 * List of nonpersistent cache groups.
	 *
	 * @var string[]
	 */
	private $nonpersistent_groups = array();

	/**
	 * The blog prefix to prepend to keys in non-global groups.
	 *
	 * @var string
	 */
	private $blog_prefix;

	/**
	 * Holds the value of is_multisite().
	 *
	 * @var bool
	 */
	private $multisite;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private $dbh;

	/**
	 * Time at which the instance was created.
	 *
	 * @var DateTimeInterface
	 */
	private $start;

	/**
	 * Whether the database is ready to accept queries.
	 *
	 * @var bool
	 */
	private $ready = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb              $dbh       Database connection.
	 * @param bool              $multisite Whether this is multisite.
	 * @param int               $blog_id   Blog ID.
	 * @param DateTimeInterface $start     Current time.
	 */
	public function __construct( $dbh, $multisite, $blog_id, $start ) {
		$this->dbh                        = $dbh;
		$this->dbh->training_object_cache = $this->dbh->base_prefix . 'training_object_cache';

		$this->multisite = $multisite;
		$this->start     = $start;

		$this->switch_to_blog( $blog_id );
	}

	/**
	 * Makes private properties readable for backward compatibility.
	 *
	 * @param string $name Property to get.
	 * @return mixed Property.
	 */
	public function __get( $name ) {
		return $this->$name;
	}

	/**
	 * Makes private properties settable for backward compatibility.
	 *
	 * @param string $name  Property to set.
	 * @param mixed  $value Property value.
	 * @return mixed Newly set property.
	 */
	public function __set( $name, $value ) {
		return $this->$name = $value;
	}

	/**
	 * Makes private properties checkable for backward compatibility.
	 *
	 * @param string $name Property to check if set.
	 * @return bool Whether the property is set.
	 */
	public function __isset( $name ) {
		return isset( $this->$name );
	}

	/**
	 * Makes private properties un-settable for backward compatibility.
	 *
	 * @param string $name Property to unset.
	 */
	public function __unset( $name ) {
		unset( $this->$name );
	}

	/**
	 * Adds data to the cache if it doesn't already exist.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents. Default 0 (no expiration).
	 * @return bool True on success, false if cache key and group already exist.
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->ready ) {
			return false;
		}

		if ( wp_suspend_cache_addition() ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( $this->exists( $id, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the list of global cache groups.
	 *
	 * @param bool[] $groups List of groups that are global.
	 */
	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	/**
	 * Adds non-persistent groups.
	 *
	 * @param string|string[] $groups List of groups that are global.
	 */
	public function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;

		$this->nonpersistent_groups = array_merge( $this->nonpersistent_groups, $groups );
		$this->nonpersistent_groups = array_unique( $this->nonpersistent_groups );
	}

	/**
	 * Decrements numeric cache item's value.
	 *
	 * @param int|string $key    The cache key to decrement.
	 * @param int        $offset Optional. The amount by which to decrement the item's value. Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( ! $this->exists( $id, $group ) ) {
			return false;
		}

		$current = $this->get( $key, $group );

		if ( ! is_numeric( $current ) ) {
			$current = 0;
		}

		$value = $current - (int) $offset;

		if ( $value < 0 ) {
			$value = 0;
		}

		$this->update_numeric( $id, $group, $value );

		return $value;
	}

	/**
	 * Removes the contents of the cache key in the group.
	 *
	 * If the cache key does not exist in the group, then nothing will happen.
	 *
	 * @param int|string $key        What the contents in the cache are called.
	 * @param string     $group      Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $deprecated Unused.
	 * @return bool False if the contents weren't deleted and true on success.
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( ! $this->exists( $id, $group ) ) {
			return false;
		}

		$this->dbh->delete(
			$this->dbh->training_object_cache,
			array(
				'cache_key'   => $id,
				'cache_group' => $group,
			)
		);

		unset( $this->cache[ $group ][ $id ] );

		return true;
	}

	/**
	 * Delete all expired cache values.
	 */
	public function expire() {
		if ( ! $this->ready ) {
			return;
		}

		$this->dbh->query(
			$this->dbh->prepare(
				"DELETE FROM {$this->dbh->training_object_cache} WHERE expires != 0 AND expires < %d", $this->start->getTimestamp()
			)
		);
	}

	/**
	 * Clears the object cache of all data.
	 *
	 * @return true Always returns true.
	 */
	public function flush() {
		if ( ! $this->ready ) {
			return true;
		}

		$this->dbh->query( "TRUNCATE {$this->dbh->training_object_cache}" );

		wp_cache_init();

		return true;
	}

	/**
	 * Retrieves the cache contents, if it exists.
	 *
	 * @param int|string $key   The key under which the cache contents are stored.
	 * @param string     $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $force Unused.
	 * @param bool       $found Optional. Whether the key was found in the cache (passed by reference).
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( ! $this->ready ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( $this->exists( $id, $group ) ) {
			$found = true;

			if ( empty( $this->cache_hits[ $group ] ) ) {
				$this->cache_hits[ $group ] = 0;
			}

			$this->cache_hits[ $group ] += 1;

			if ( ! isset( $this->cache[ $group ] ) || ! array_key_exists( $id, $this->cache[ $group ] ) ) {
				$this->cache[ $group ][ $id ] = maybe_unserialize(
					$this->dbh->get_var(
						$this->select_data( $id, $group )
					)
				);
			}

			$value = $this->cache[ $group ][ $id ];

			if ( is_object( $value ) ) {
				$value = clone $value;
			}

			return $value;
		}

		$found = false;

		if ( empty( $this->cache_misses[ $group ] ) ) {
			$this->cache_misses[ $group ] = 0;
		}

		$this->cache_misses[ $group ] += 1;

		return false;
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @param array  $keys  Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool   $force Unused.
	 * @return array Array of values organized into groups.
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}

		return $values;
	}

	/**
	 * Caching stats.
	 *
	 * @return int[]
	 */
	public function getStats() {
		return array(
			'cache_hits'   => array_sum( $this->cache_hits ),
			'cache_misses' => array_sum( $this->cache_misses ),
		);
	}

	/**
	 * Increments numeric cache item's value.
	 *
	 * @param int|string $key    The cache key to increment
	 * @param int        $offset Optional. The amount by which to increment the item's value. Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( ! $this->exists( $id, $group ) ) {
			return false;
		}

		$current = $this->get( $key, $group );

		if ( ! is_numeric( $current ) ) {
			$current = 0;
		}

		$value = $current + (int) $offset;

		if ( $value < 0 ) {
			$value = 0;
		}

		$this->update_numeric( $id, $group, $value );

		return $value;
	}

	/**
	 * Replaces the contents in the cache, if contents already exist.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents. Default 0 (no expiration).
	 * @return bool False if not exists, true if contents were replaced.
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		if ( ! $this->exists( $id, $group ) ) {
			return false;
		}

		$this->delete( $key, $group );

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Resets cache keys.
	 *
	 * @deprecated Use switch_to_blog()
	 */
	public function reset() {
		_deprecated_function( __FUNCTION__, '3.5.0', 'switch_to_blog()' );
	}

	/**
	 * Sets the data contents into the cache.
	 *
	 * The cache contents are grouped by the $group parameter followed by the
	 * $key. This allows for duplicate IDs in unique groups. Therefore, naming of
	 * the group should be used with care and should follow normal function
	 * naming guidelines outside of core WordPress usage.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents. Default 0 (no expiration).
	 * @return true Always returns true.
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->ready ) {
			return true;
		}

		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return true;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $this->prefixed( $key, $group );

		// Reduce chance of duplicate insert from another process by forcing re-check.
		unset( $this->not_cached[ $group ][ $id ] );

		if ( $this->exists( $id, $group ) ) {
			$this->replace( $key, $data, $group, $expire );
		} elseif ( empty( $this->nonpersistent_groups[ $group ] ) ) {
			$data = maybe_serialize( $data );

			$expires = $expire ? time() + (int) $expire : 0;

			$this->dbh->insert(
				$this->dbh->training_object_cache,
				array(
					'cache_key'   => $id,
					'cache_group' => $group,
					'data'        => $data,
					'TTL'         => $expires ? human_time_diff( $expires ) : '-',
					'expires'     => $expires,
					'size'        => size_format( mb_strlen( $data, '8bit' ) ),
				)
			);

			unset( $this->not_cached[ $group ][ $id ] );

			// For fidelity with retrieval of serialized string from database.
			$this->cache[ $group ][ $id ] = maybe_unserialize( $data );
		}

		return true;
	}

	/**
	 * Echoes the cache hits and cache misses. Also prints every cached group and the size of its data.
	 */
	public function stats() {
		$stats = $this->getStats();

		echo '<p>';
		echo "<strong>Cache Hits:</strong> {$stats['cache_hits']}<br />";
		echo "<strong>Cache Misses:</strong> {$stats['cache_misses']}<br />";
		echo '</p>';

		echo "<p><strong>Groups:</strong></p>";
		echo '<ul>';

		$groups = $this->cache;
		ksort( $groups );
		foreach ( $groups as $group => $cache ) {
			printf(
				"<li><code>%s</code> (%s&ndash;%s, %sk)</li>",
				esc_html( $group ),
				isset( $this->cache_hits[ $group ] ) ? $this->cache_hits[ $group ] : 0,
				isset( $this->cache_misses[ $group ] ) ? $this->cache_misses[ $group ] : 0,
				number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 )
			);
		}
		echo '</ul>';
	}

	/**
	 * Switches the internal blog ID used to create keys in blog-specific groups.
	 *
	 * @param int $blog_id Blog ID.
	 */
	public function switch_to_blog( $blog_id ) {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Insert or update the database tables.
	 */
	public function upsert() {
		if ( defined( 'WP_INSTALLING' ) ) {
			return;
		}

		if ( $this->multisite && ! function_exists( 'get_network' ) ) {
			add_action( 'init', array( $this, 'upsert' ) );

			return;
		}

		$latest  = 1;
		$version = (int) get_site_option( 'training_object_cache_version', 0 );

		if ( $latest !== $version ) {
			if ( ! did_action( 'init' ) ) {
				add_action( 'init', array( $this, 'upsert' ) );

				return;
			}

			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . '/wp-admin/includes/upgrade.php';
			}

			if ( 0 === $version ) {
				\dbDelta( "CREATE TABLE {$this->dbh->training_object_cache} (
		cache_group varchar(255) NOT NULL,
		cache_key varchar(255) NOT NULL,
		data longtext NOT NULL,
	    TTL varchar(32) NOT NULL,
	    size varchar(32) NOT NULL,
	    expires varchar(10) NOT NULL
	) {$this->dbh->get_charset_collate()};" );

				$version = 1;
				update_site_option( 'training_object_cache_version', $version );
			}
		}

		$this->ready = true;
		$this->expire();
	}

	/**
	 * Serves as a utility function to determine whether a key exists in the cache.
	 *
	 * @param int|string $key   Cache key to check for existence.
	 * @param string     $group Cache group for the key existence check.
	 * @return bool Whether the key exists in the cache for the given group.
	 */
	private function exists( $key, $group ) {
		if ( ! $this->ready ) {
			return false;
		}

		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return false;
		}

		if ( isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			return true;
		}

		if ( isset( $this->not_cached[ $group ][ $key ] ) && true === $this->not_cached[ $group ][ $key ] ) {
			return false;
		}

		$results = $this->dbh->get_results(
			$this->select_data( $key, $group )
		);

		if ( 0 === count( $results ) ) {
			$this->not_cached[ $group ][ $key ] = true;

			return false;
		}

		$data = $results[0]->data;
		$data = maybe_unserialize( $data );

		$this->cache[ $group ][ $key ] = $data;

		return true;
	}

	/**
	 * Update a numeric cache item's value.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param int        $value Value.
	 */
	private function update_numeric( $key, $group, $value ) {
		$this->dbh->update(
			$this->dbh->training_object_cache,
			array( 'data' => $value ),
			array(
				'cache_key'   => $key,
				'cache_group' => $group,
			)
		);

		$this->cache[ $group ][ $key ] = $value;
	}

	/**
	 * Blog-specific cache key in multisites.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return int|string
	 */
	private function prefixed( $key, $group ) {
		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		return $key;
	}

	/**
	 * Prepared SELECT statement for data for a given group and key.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return string
	 */
	private function select_data( $key, $group ) {
		return (string) $this->dbh->prepare(
			"SELECT data FROM {$this->dbh->training_object_cache} WHERE cache_group = %s AND cache_key = %s LIMIT 1",
			$group,
			$key
		);
	}
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_close() {
	return true;
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;

	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;

	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;

	$wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_reset() {
	_deprecated_function( __FUNCTION__, '3.5.0', 'WP_Object_Cache::reset()' );

	global $wp_object_cache;

	$wp_object_cache->reset();
}

function wp_cache_init() {
	global $wpdb, $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache( $wpdb, (bool) is_multisite(), (int) get_current_blog_id(), new \DateTimeImmutable( 'now' ) );

	$wp_object_cache->upsert();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'training-object-cache reset',
		function () {
			delete_site_option( 'training_object_cache_version' );
			wp_cache_flush();
			WP_CLI::success( 'Process complete!' );
		}
	);

	WP_CLI::add_command(
		'training-object-cache destroy',
		function () {
			global $wpdb;
			delete_site_option( 'training_object_cache_version' );
			$wpdb->query( "DROP TABLE {$wpdb->training_object_cache}" );
			WP_CLI::success( 'Process complete!' );
		}
	);
}
