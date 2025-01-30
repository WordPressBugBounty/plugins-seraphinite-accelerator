<?php

if( !defined( 'ABSPATH' ) )
	exit;

require_once( __DIR__ . '/common.php' );

function wp_cache_add_global_groups( $groups )
{
	global $wp_object_cache;
	$wp_object_cache -> add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups )
{
	global $wp_object_cache;
	$wp_object_cache -> add_non_persistent_groups( $groups );
}

function wp_cache_supports( $feature )
{
	static $g_aFeature = array( 'add_multiple' => 1, 'set_multiple' => 1, 'get_multiple' => 1, 'delete_multiple' => 1, 'flush_runtime' => 1, 'flush_group' => 1 );
	return( isset( $g_aFeature[ $feature ] ) );
}

function wp_cache_init()
{
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 )
{
	global $wp_object_cache;
	return( $wp_object_cache -> add( $key, $data, $group, ( int )$expire ) );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 )
{
	global $wp_object_cache;
	return( $wp_object_cache -> add_multiple( $data, $group, $expire ) );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 )
{
	global $wp_object_cache;
	return( $wp_object_cache -> replace( $key, $data, $group, ( int )$expire ) );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 )
{
	global $wp_object_cache;
	return( $wp_object_cache -> set( $key, $data, $group, ( int )$expire ) );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 )
{
	global $wp_object_cache;
	return( $wp_object_cache -> set_multiple( $data, $group, $expire ) );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null )
{
	global $wp_object_cache;
	return( $wp_object_cache -> get( $key, $group, $force, $found ) );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false )
{
	global $wp_object_cache;
	return( $wp_object_cache -> get_multiple( $keys, $group, $force ) );
}

function wp_cache_delete( $key, $group = '' )
{
	global $wp_object_cache;
	return( $wp_object_cache -> delete( $key, $group ) );
}

function wp_cache_delete_multiple( array $keys, $group = '' )
{
	global $wp_object_cache;
	return( $wp_object_cache -> delete_multiple( $keys, $group ) );
}

function wp_cache_incr( $key, $offset = 1, $group = '' )
{
	global $wp_object_cache;
	return( $wp_object_cache -> incr( $key, $offset, $group ) );
}

function wp_cache_decr( $key, $offset = 1, $group = '' )
{
	global $wp_object_cache;
	return( $wp_object_cache -> decr( $key, $offset, $group ) );
}

function wp_cache_flush()
{
	global $wp_object_cache;
	return( $wp_object_cache -> flush() );
}

function wp_cache_flush_runtime()
{
	return( wp_cache_flush() );
}

function wp_cache_flush_group( $group )
{
	global $wp_object_cache;
	return( $wp_object_cache -> flush_group( $group ) );
}

function wp_cache_close()
{
	return( true );
}

function wp_cache_switch_to_blog( $blog_id )
{
	global $wp_object_cache;
	$wp_object_cache -> switch_to_blog( $blog_id );
}

function wp_cache_reset()
{
	global $wp_object_cache;
	$wp_object_cache -> reset();
}

class WP_Object_Cache
{

	private $cache = array();

	public $cache_hits = 0;

	public $cache_misses = 0;

	protected $aGlobalGroup = array();
	protected $aNonPersistentGroup = array();

	private $blog_prefix;

	private $multisite;

	private $inited;
	private $curSite;
	private $curSiteId;
	private $dataDir;
	private $lock;

	public function __construct()
	{
		$this -> multisite   = is_multisite();
		$this -> blog_prefix = $this -> multisite ? get_current_blog_id() . ':' : '';
	}

	public function add_global_groups( $groups )
	{
		$this -> aGlobalGroup = array_merge( $this -> aGlobalGroup, array_fill_keys( ( array )$groups, true ) );
	}

	public function add_non_persistent_groups( $groups )
	{
		$this -> aNonPersistentGroup = array_merge( $this -> aNonPersistentGroup, array_fill_keys( ( array )$groups, true ) );
	}

	protected function _init()
	{
		if( $this -> inited )
			return;

		$this -> inited = true;

		if( is_multisite() )
		{
			$this -> curSite = get_current_site();
			$this -> curSite = new \seraph_accel\AnyObj( array( 'blog_id' => get_current_blog_id(), 'site_id' => $this -> curSite -> site_id ) );
			$this -> curSite -> blog_id_orig = $this -> curSite -> blog_id;
		}
		else
			$this -> curSite = null;
		$this -> curSiteId = \seraph_accel\GetSiteId( $this -> curSite );

		$dataDir = \seraph_accel\GetCacheDir() . '/oc/';
		$this -> lock = new \seraph_accel\Lock( $dataDir . 'l', false, true );
		$this -> dataDir = $dataDir . 'g/';
	}

	protected function _getPath( $group )
	{
		$path = ( empty( $group ) ? '@' : rawurlencode( $group ) ) . '/';
		$path .= isset( $this -> aGlobalGroup[ $group ] ) ? 'g' : 's/' . $this -> curSiteId;
		return( $path );
	}

	public function __get( $name )
	{
		return $this -> $name;
	}

	public function __set( $name, $value )
	{
		$this -> $name = $value;
	}

	public function __isset( $name )
	{
		return isset( $this -> $name );
	}

	public function __unset( $name )
	{
		unset( $this -> $name );
	}

	static protected function _is_valid_key( $key )
	{
		if( is_int( $key ) )
			return( true );

		if( is_string( $key ) && trim( $key ) !== '' )
			return( true );

		return( false );
	}

	protected function _exists( $key, $group )
	{
		return isset( $this -> cache[ $group ] ) && ( isset( $this -> cache[ $group ][ $key ] ) || array_key_exists( $key, $this -> cache[ $group ] ) );
	}

	public function add( $key, $data, $group = '', $expire = 0 )
	{
		if( wp_suspend_cache_addition() )
			return( false );

		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		$id = $key;
		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$id = $this -> blog_prefix . $key;
		}

		if ( $this -> _exists( $id, $group ) )
		{
			return false;
		}

		return $this -> set( $key, $data, $group, ( int )$expire );
	}

	public function add_multiple( array $data, $group = '', $expire = 0 )
	{
		if( wp_suspend_cache_addition() )
			return( array_fill( 0, count( $data ), false ) );

		$this -> _init();

		$values = array();

		foreach ( $data as $key => $value )
		{
			$values[ $key ] = $this -> add( $key, $value, $group, $expire );
		}

		return $values;
	}

	public function replace( $key, $data, $group = '', $expire = 0 )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		$id = $key;
		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$id = $this -> blog_prefix . $key;
		}

		if ( ! $this -> _exists( $id, $group ) )
		{
			return false;
		}

		return $this -> set( $key, $data, $group, ( int )$expire );
	}

	public function set( $key, $data, $group = '', $expire = 0 )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$key = $this -> blog_prefix . $key;
		}

		if ( is_object( $data ) )
		{
			$data = clone $data;
		}

		$this -> cache[ $group ][ $key ] = $data;
		return true;
	}

	public function set_multiple( array $data, $group = '', $expire = 0 )
	{
		$this -> _init();

		$values = array();

		foreach ( $data as $key => $value )
		{
			$values[ $key ] = $this -> set( $key, $value, $group, $expire );
		}

		return $values;
	}

	public function get( $key, $group = '', $force = false, &$found = null )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$key = $this -> blog_prefix . $key;
		}

		if ( $this -> _exists( $key, $group ) )
		{
			$found             = true;
			$this -> cache_hits += 1;
			if ( is_object( $this -> cache[ $group ][ $key ] ) )
			{
				return clone $this -> cache[ $group ][ $key ];
			} else {
				return $this -> cache[ $group ][ $key ];
			}
		}

		$found               = false;
		$this -> cache_misses += 1;
		return false;
	}

	public function get_multiple( $keys, $group = '', $force = false )
	{
		$this -> _init();

		$values = array();

		foreach ( $keys as $key )
		{
			$values[ $key ] = $this -> get( $key, $group, $force );
		}

		return $values;
	}

	public function delete( $key, $group = '', $deprecated = false )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$key = $this -> blog_prefix . $key;
		}

		if ( ! $this -> _exists( $key, $group ) )
		{
			return false;
		}

		unset( $this -> cache[ $group ][ $key ] );
		return true;
	}

	public function delete_multiple( array $keys, $group = '' )
	{
		$this -> _init();

		$values = array();

		foreach ( $keys as $key )
		{
			$values[ $key ] = $this -> delete( $key, $group );
		}

		return $values;
	}

	public function incr( $key, $offset = 1, $group = '' )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if ( empty( $group ) )
		{
			$group = '@';
		}

		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$key = $this -> blog_prefix . $key;
		}

		if ( ! $this -> _exists( $key, $group ) )
		{
			return false;
		}

		if ( ! is_numeric( $this -> cache[ $group ][ $key ] ) )
		{
			$this -> cache[ $group ][ $key ] = 0;
		}

		$offset = (int) $offset;

		$this -> cache[ $group ][ $key ] += $offset;

		if ( $this -> cache[ $group ][ $key ] < 0 )
		{
			$this -> cache[ $group ][ $key ] = 0;
		}

		return $this -> cache[ $group ][ $key ];
	}

	public function decr( $key, $offset = 1, $group = '' )
	{
		if( !self::_is_valid_key( $key ) )
			return( false );

		$this -> _init();

		if( empty( $group ) )
			$group = '@';

		if ( $this -> multisite && ! isset( $this -> aGlobalGroup[ $group ] ) )
		{
			$key = $this -> blog_prefix . $key;
		}

		if ( ! $this -> _exists( $key, $group ) )
		{
			return false;
		}

		if ( ! is_numeric( $this -> cache[ $group ][ $key ] ) )
		{
			$this -> cache[ $group ][ $key ] = 0;
		}

		$offset = (int) $offset;

		$this -> cache[ $group ][ $key ] -= $offset;

		if ( $this -> cache[ $group ][ $key ] < 0 )
		{
			$this -> cache[ $group ][ $key ] = 0;
		}

		return $this -> cache[ $group ][ $key ];
	}

	public function flush()
	{
		$this -> _init();

		$this -> cache = array();

		return true;
	}

	public function flush_group( $group )
	{
		$this -> _init();

		unset( $this -> cache[ $group ] );

		return true;
	}

	public function switch_to_blog( $blog_id )
	{
		$this -> _init();

		if( $this -> curSite )
		{
			$this -> curSite -> blog_id = ( int )$blog_id;
			$this -> curSiteId = \seraph_accel\GetSiteId( $this -> curSite );
		}

		$blog_id           = (int) $blog_id;
		$this -> blog_prefix = $this -> multisite ? $blog_id . ':' : '';
	}

	public function reset()
	{
		$this -> _init();

		if( $this -> curSite )
		{
			$this -> curSite -> blog_id = $this -> curSite -> blog_id_orig;
			$this -> curSiteId = \seraph_accel\GetSiteId( $this -> curSite );
		}
	}

	public function stats()
	{
		echo '<p>';
		echo "<strong>Cache Hits:</strong> {$this -> cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this -> cache_misses}<br />";
		echo '</p>';
		echo '<ul>';
		foreach ( $this -> cache as $group => $cache )
		{
			echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 ) . 'k )</li>';
		}
		echo '</ul>';
	}
}

