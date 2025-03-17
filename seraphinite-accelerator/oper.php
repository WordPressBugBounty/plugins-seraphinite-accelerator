<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

function _CacheDirWalk_User( $path, $item, &$ctx )
{
	$path = $path . '/' . $item . '/s';
	if( !@is_dir( $path ) )
		return( true );

	return( Gen::DirEnum( $path, $ctx,
		function( $path, $item, &$ctx )
		{
			$path = $path . '/' . $item . '/s';
			if( !@is_dir( $path ) )
				return( true );

			$ctx[ 'isDefSess' ] = ( $item == '@' );

			return( Gen::DirEnum( $path, $ctx,
				function( $path, $item, &$ctx )
				{
					$path = $path . '/' . $item . '/o';
					if( !@is_dir( $path ) )
						return( true );

					$ctx[ 'isUserCtx' ] = !( $ctx[ 'isDefSess' ] && ( $item == '@' ) );
					$ctx[ 'dirRootLen' ] = strlen( $path );

					$recurse = true;
					$objPathSpec = Gen::GetArrField( $ctx[ 'spec' ], array( 'objPath' ) );
					if( $objPathSpec !== null )
					{
						if( $objPathSpec )
							$path .= '/' . $objPathSpec;
						$recurse = Gen::GetArrField( $ctx[ 'spec' ], array( 'objPathRecurse' ), false );
					}

					foreach( Gen::GetArrField( $ctx[ 'spec' ], array( 'objPathMask' ), false ) ? @glob( $path, GLOB_NOSORT | GLOB_ONLYDIR ) : array( $path ) as $pathReal )
					{
						if( @is_dir( $pathReal ) && $ctx[ 'cbPath' ] && call_user_func_array( $ctx[ 'cbPath' ], array( &$ctx[ 'ctxWalk' ], $ctx[ 'isUserCtx' ], str_replace( '\\', '/', substr( $pathReal, $ctx[ 'dirRootLen' ] ) ) ) ) === false )
							return( false );

						if( Gen::DirEnum( $pathReal, $ctx,
							function( $path, $item, &$ctx )
							{
								$item = $path . '/' . $item;
								if( @is_dir( $item ) )
									return( $ctx[ 'cbPath' ] ? call_user_func_array( $ctx[ 'cbPath' ], array( &$ctx[ 'ctxWalk' ], $ctx[ 'isUserCtx' ], str_replace( '\\', '/', substr( $item, $ctx[ 'dirRootLen' ] ) ) ) ) : true );
								return( $ctx[ 'cbObj' ] ? call_user_func_array( $ctx[ 'cbObj' ], array( &$ctx[ 'ctxWalk' ], $ctx[ 'isUserCtx' ], $item ) ) : true );
							}
							, $recurse
						) === false )
						{
							return( false );
						}
					}
				}
			) );
		}
	) );
}

function _CacheDirWalk_View( $path, $viewId, &$ctx2 )
{
	$path .= '/' . $viewId;
	if( !@is_dir( $path ) )
		return( true );

	$ctx = &$ctx2 -> ctx;

	if( $ctx2 -> aViewId !== null )
	{
		$f = false;
		foreach( $ctx2 -> aViewId as $viewIdFilter )
		{
			if( Gen::StrEndsWith( $viewIdFilter, '*' ) )
			{
				$viewIdFilter = substr( $viewIdFilter, 0, -1 );
				if( $viewId != $viewIdFilter && !Gen::StrStartsWith( $viewId, $viewIdFilter . '-' ) )
					continue;
			}
			else if( $viewId != $viewIdFilter )
				continue;

			$f = true;
			break;
		}

		if( !$f )
			return( true );
	}

	$viewDir = $path;

	if( $cbView = $ctx[ 'cbView' ] )
		call_user_func_array( $cbView, array( &$ctx[ 'ctxWalk' ], $viewId, $viewDir, true ) );

	$path .= '/c';
	if( !@is_dir( $path ) )
		return( true );

	$userIdSpec = Gen::GetArrField( $ctx[ 'spec' ], array( 'userId' ) );
	$res = $userIdSpec ? _CacheDirWalk_User( $path, '' . $userIdSpec, $ctx ) : Gen::DirEnum( $path, $ctx, 'seraph_accel\\_CacheDirWalk_User' );

	if( $cbView = $ctx[ 'cbView' ] )
		call_user_func_array( $cbView, array( &$ctx[ 'ctxWalk' ], $viewId, $viewDir, false ) );

	return( $res );
}

function _CacheDirWalk( $siteId, $siteSubId, $aViewId, &$ctxWalk, $spec = null, $cbObj = null, $cbData = null, $cbPath = null, $cbView = null, $cbSite = null )
{
	$siteCacheRootPath = GetCacheDir() . '/s/' . $siteId;

	$ctx = array( 'ctxWalk' => &$ctxWalk, 'spec' => $spec, 'cbObj' => $cbObj, 'cbPath' => $cbPath, 'cbData' => $cbData, 'cbView' => $cbView );

	if( $cbSite )
		if( call_user_func_array( $cbSite, array( &$ctx[ 'ctxWalk' ], $siteCacheRootPath, true ) ) === false )
			return( false );

	if( $cbObj || $cbPath )
	{
		foreach( ( $siteSubId === null ) ? @glob( GetCacheViewsDir( $siteCacheRootPath ) . '*' ) : array( GetCacheViewsDir( $siteCacheRootPath ) . '-' . $siteSubId ) as $viewDir )
		{
			$ctx2 = new AnyObj();
			$ctx2 -> ctx = &$ctx;
			$ctx2 -> aViewId = $aViewId;
			if( Gen::DirEnum( $viewDir, $ctx2, 'seraph_accel\\_CacheDirWalk_View' ) === false )
				return( false );

		}
	}

	if( $cbData )
	{
		$cacheDataDir = GetCacheDataDir( $siteCacheRootPath );
		$ctx[ 'cacheDataDirLen' ] = strlen( $cacheDataDir );

		if( Gen::DirEnum( $cacheDataDir, $ctx,
			function( $path, $item, &$ctx )
			{
				$itemId = $item;

				$item = $path . '/' . $item;
				if( @is_dir( $item ) )
					return( true );

				$itemIdPrefix = explode( '/', substr( $path, $ctx[ 'cacheDataDirLen' ] + 1 ) );
				if( strlen( $itemIdPrefix[ 0 ] ) > 1 )
				{
					$itemType = $itemIdPrefix[ 0 ];
					array_splice( $itemIdPrefix, 0, 1 );
				}
				else
					$itemType = 'html';

				$itemId = explode( '.', $itemId );
				$itemId = implode( '', $itemIdPrefix ) . ($itemId[ 0 ]??null) . '.' . ($itemId[ 1 ]??null);

				return( call_user_func_array( $ctx[ 'cbData' ], array( &$ctx[ 'ctxWalk' ], $itemType, $itemId, $item ) ) );
			}
		, true ) === false )
		{
			return( false );
		}
	}

	if( $cbSite )
		if( call_user_func_array( $cbSite, array( &$ctx[ 'ctxWalk' ], $siteCacheRootPath, false ) ) === false )
			return( false );

	return( true );
}

function CacheGetInfo( $siteId, $cbCancel )
{
	$info = array(
		'cbCancel' => $cbCancel, '_ai_fileMask' => '@^[\\w\\-]+\\.(?:' . implode( '|', array( 'jpe','jpg','jpeg','png','gif','bmp', 'webp','avif' ) ) . ')$@iS',
		'nObj' => 0, 'nDataObj' => 0, 'nCacheObj' => 0, 'sizeCacheObj' => 0, 'nFile' => 0, 'nJs' => 0, 'sizeJs' => 0, 'nCss' => 0, 'sizeCss' => 0, 'nImg' => 0, 'sizeImg' => 0, 'nLrn' => 0, 'sizeLrn' => 0, 'nAi' => 0, 'sizeAi' => 0, 'nExtObj' => 0, 'sizeExtObj' => 0,
		'size' => 0, 'sizeUncompr' => 0, 'sizeObjFrag' => 0, 'sizeObj' => 0
	);

	if( _CacheDirWalk( $siteId, null, null, $info, null,
		function( &$info, $isUserCtx, $objFile )
		{
			if( $info[ 'cbCancel' ]() )
				return( false );

			if( Gen::GetFileExt( Gen::GetFileName( $objFile, true ) ) == 'html' )
				$info[ 'nObj' ] += 1;
			else
				$info[ 'nDataObj' ] += 1;
			$info[ 'nFile' ] += 1;
			$sz = @filesize( $objFile );
			$info[ 'size' ] += $sz;
			$info[ 'sizeUncompr' ] += $sz;

			foreach( Gen::GetArrField( CacheReadDsc( $objFile ), array( 'p' ), array() ) as $oiCi )
			{
				if( $info[ 'cbCancel' ]() )
					return( false );

				$sz = GetCacheCos( $oiCi );

				$info[ 'sizeObj' ] += $sz;

			}
		}

		,
		function( &$info, $dataType, $dataId, $dataFile )
		{
			if( $info[ 'cbCancel' ]() )
				return( false );

			$sz = @filesize( $dataFile );
			$info[ 'size' ] += $sz;
			$info[ 'nFile' ] += 1;

			if( $dataType == 'img' )
			{
				$info[ 'nImg' ] += 1;
				$info[ 'sizeImg' ] += $sz;
				$info[ 'sizeUncompr' ] += $sz;
			}
			else if( $dataType == Gen::GetFileExt( $dataFile ) )
			{
				switch( $dataType )
				{
				case 'js':		$info[ 'nJs' ] += 1; $info[ 'sizeJs' ] += $sz; break;
				case 'css':		$info[ 'nCss' ] += 1; $info[ 'sizeCss' ] += $sz; break;
				}

				$info[ 'sizeUncompr' ] += $sz;
			}
			else
				$info[ 'sizeUncompr' ] += GetCacheCos( Gen::GetFileName( Gen::GetFileName( $dataFile, true ), true ) );

			if( $dataType == 'html' )
			{
				$fileNameCount = null;
				if( $dataType == Gen::GetFileExt( $dataFile ) )
					$fileNameCount = $dataFile;
				else
				{
					$dataFileUncompr = Gen::GetFileName( $dataFile, true, true );
					if( $dataType == Gen::GetFileExt( $dataFileUncompr ) && !@file_exists( $dataFileUncompr ) )
						$fileNameCount = $dataFileUncompr;
				}

				if( $fileNameCount )
					$info[ 'sizeObjFrag' ] += GetCacheCos( Gen::GetFileName( $fileNameCount, true ) );
			}
		}

		,
		null

		,
		function( &$info, $viewId, $viewDir, $begin )
		{
			if( !$begin )
				return;

			if( Gen::DirEnum( $viewDir . '/l', $info,
				function( $path, $item, &$info )
				{
					if( $info[ 'cbCancel' ]() )
						return( false );

					$path = $path . '/' . $item;
					if( @is_dir( $path ) )
					{
						$info[ 'nFile' ] += 1;
						return;
					}

					$sz = @filesize( $path );
					$info[ 'size' ] += $sz;
					$info[ 'sizeUncompr' ] += $sz;
					$info[ 'sizeLrn' ] += $sz;

					$info[ 'nLrn' ] += 1;
					$info[ 'nFile' ] += 1;
				}
			, true ) === false )
			{
				return( false );
			}
		}

		,
		function( &$info, $siteDir, $begin )
		{
			if( !$begin )
				return;

			if( Gen::DirEnum( $siteDir . '/l', $info,
				function( $path, $item, &$info )
				{
					if( $info[ 'cbCancel' ]() )
						return( false );

					$path = $path . '/' . $item;
					if( @is_dir( $path ) )
					{
						$info[ 'nFile' ] += 1;
						return;
					}

					$sz = @filesize( $path );
					$info[ 'size' ] += $sz;
					$info[ 'sizeUncompr' ] += GetCacheCos( Gen::GetFileName( Gen::GetFileName( $path, true ), true ) );
					$info[ 'sizeLrn' ] += $sz;
					$info[ 'nFile' ] += 1;
				}
			, true ) === false )
			{
				return( false );
			}

			if( Gen::DirEnum( $siteDir . '/ai', $info,
				function( $path, $item, &$info )
				{
					if( $info[ 'cbCancel' ]() )
						return( false );

					$path = $path . '/' . $item;
					if( @is_dir( $path ) )
					{
						$info[ 'nFile' ] += 1;
						return;
					}

					$sz = @filesize( $path );
					$info[ 'size' ] += $sz;
					$info[ 'sizeUncompr' ] += $sz;
					$info[ 'sizeAi' ] += $sz;
					if( preg_match( $info[ '_ai_fileMask' ], $item ) )
						$info[ 'nAi' ] += 1;
					$info[ 'nFile' ] += 1;
				}
			, true ) === false )
			{
				return( false );
			}

			if( Gen::DirEnum( $siteDir . '/eo', $info,
				function( $path, $item, &$info )
				{
					if( $info[ 'cbCancel' ]() )
						return( false );

					$path = $path . '/' . $item;
					if( @is_dir( $path ) )
					{
						$info[ 'nFile' ] += 1;
						return;
					}

					$sz = @filesize( $path );
					$info[ 'size' ] += $sz;
					$info[ 'sizeUncompr' ] += $sz;
					$info[ 'sizeExtObj' ] += $sz;
					if( Gen::GetFileExt( $item ) )
						$info[ 'nExtObj' ] += 1;
					$info[ 'nFile' ] += 1;
				}
			, true ) === false )
			{
				return( false );
			}
		}
	) === false )
	{
		return( null );
	}

	if( Gen::DirEnum( GetCacheDir() . '/oc', $info,
		function( $path, $item, &$info )
		{
			if( $info[ 'cbCancel' ]() )
				return( false );

			$path = $path . '/' . $item;
			if( @is_dir( $path ) )
			{
				$info[ 'nFile' ] += 1;
				return;
			}

			$sz = @filesize( $path );
			$info[ 'size' ] += $sz;
			$info[ 'sizeUncompr' ] += $sz;
			$info[ 'sizeCacheObj' ] += $sz;
			if( Gen::GetFileExt( $item ) )
				$info[ 'nCacheObj' ] += 1;
			$info[ 'nFile' ] += 1;
		}
	, true ) === false )
	{
		return( false );
	}

	unset( $info[ 'cbCancel' ], $info[ '_ai_fileMask' ] );
	return( $info );
}

class DscLockUpdater
{
	private $timeout;

	private $lock;
	private $tmLastUpdate = 0.0;

	function __construct( $timeout = 0.0 )
	{
		$this -> timeout = $timeout;
		$this -> lock = new Lock( 'dl', GetCacheDir() );
	}

	function __destruct()
	{
		$this -> Release();
	}

	function Acquire()
	{
		return( $this -> lock -> Acquire() );
	}

	function Release( $force = false )
	{
		if( $this -> timeout && !$force )
		{
			$tmCur = microtime( true );
			if( $tmCur - $this -> tmLastUpdate < $this -> timeout )
				return;

			$this -> tmLastUpdate = $tmCur;
		}

		$this -> lock -> Release();
	}
}

function _CacheOp_Clear_Dsc_MarkExistedParts( &$datasDel, $dsc, $aTypes )
{
	{
		$aChunks = Gen::GetArrField( $dsc, array( 'p' ), array() );
		if( isset( $dsc[ 't' ] ) && is_string( $dsc[ 't' ] ) )
			$aChunks[] = $dsc[ 't' ];

		foreach( $aChunks as $oiCi )
			foreach( $aTypes as $type )
				unset( $datasDel[ $type ][ $oiCi ] );
	}

	foreach( Gen::GetArrField( $dsc, array( 's' ), array() ) as $childType => $children )
		foreach( $children as $childId )
			unset( $datasDel[ $childType ][ $childId ] );
}

function _CacheOp_GetViews( $viewId = null, $geoId = null )
{
	if( $viewId !== null && !is_array( $viewId ) )
		$viewId = array( $viewId );

	if( $geoId === null )
		return( $viewId );

	if( $viewId === null )
		$viewId = array( 'cmn' );

	if( !is_array( $geoId ) )
		$geoId = array( $geoId );

	$viewIdNew = array();
	foreach( $viewId as $viewIdI )
		foreach( $geoId as $geoIdI )
			$viewIdNew[] = $viewIdI . ( strlen( $geoIdI ) ? ( '-' . $geoIdI ) : '' );

	return( $viewIdNew );
}

function _CacheOp_GetViewsForDirWalk( $viewId )
{
	return( $viewId !== null ? array_map( function( $v ) { return( $v . '*' ); }, $viewId ) : null );
}

function CacheOp( $op, $priority = 0, $viewId = null, $geoId = null, $cbIsAborted = true )
{
	$ctx = new AnyObj();
	$ctx -> op = $op;
	$ctx -> pluginFileValueName = ( $ctx -> op === 1 ) ? 'cln' : 'o';

	if( $cbIsAborted === true && PluginFileValues::Get( $ctx -> pluginFileValueName ) !== null )
		return( false );

	if( $op == 10 )
	{
		CacheExt_Clear();
		return( null );
	}

	if( $cbIsAborted === true )
		PluginFileValues::Set( $ctx -> pluginFileValueName, $op );

	$settCacheGlobal = Gen::GetArrField( Plugin::SettGetGlobal(), array( 'cache' ), array() );

	$sett = Plugin::SettGet();

	$ctx -> viewId = _CacheOp_GetViews( $viewId, $geoId );
	$ctx -> curSiteId = GetSiteId();
	$ctx -> lock = new DscLockUpdater(  );
	$ctx -> datasDel = array();
	$ctx -> procWorkInt = ($settCacheGlobal[ 'procWorkInt' ]??null);
	$ctx -> procPauseInt = ($settCacheGlobal[ 'procPauseInt' ]??null);
	$ctx -> cbIsAborted = $cbIsAborted;
	$ctx -> _isAborted =
		function( $ctx )
		{

			if( $ctx -> cbIsAborted === true )
				return( PluginFileValues::Get( $ctx -> pluginFileValueName ) === null );
			return( call_user_func( $ctx -> cbIsAborted ) );
		};
	$ctx -> isAborted = function( $ctx ) { return( !Gen::SliceExecTime( $ctx -> procWorkInt, $ctx -> procPauseInt, 5, array( $ctx, '_isAborted' ) ) ); };

	unset( $settCacheGlobal );

	if( $op == 1 )
	{
		$ctx -> timeout = Gen::GetArrField( $sett, array( 'cache', 'timeoutCln' ), 0 ) * 60;
		$ctx -> timeoutCtx = Gen::GetArrField( $sett, array( 'cache', 'ctxTimeoutCln' ), 0 ) * 60;
		$ctx -> timeoutExtObj = Gen::GetArrField( $sett, array( 'cache', 'extObjTimeoutCln' ), 0 ) * 60;
		$ctx -> tmCur = Gen::GetCurRequestTime();
		unset( $sett );

		if( Gen::DirEnum( ProcessCtlData_GetFullPath(), $ctx,
			function( $path, $item, &$ctx )
			{
				if( $ctx -> isAborted() )
					return( false );

				$item = $path . '/' . $item;
				if( @is_dir( $item ) )
					return;

				$tmFile = @filemtime( $item );
				if( $tmFile !== false && $ctx -> tmCur - $tmFile > 43200 )
					@unlink( $item );
			}
		) === false )
		{
			return( false );
		}

		if( _CacheDirWalk( $ctx -> curSiteId, null, null, $ctx, null,
			null,

			function( &$ctx, $dataType, $dataId, $dataFile )
			{
				if( $ctx -> isAborted() )
					return( false );

				$ctx -> datasDel[ $dataType ][ $dataId ] = true;
			}
		) === false )
		{
			return( false );
		}
	}

	if( $op != 3 )
	{
		if( _CacheDirWalk( $ctx -> curSiteId, null, _CacheOp_GetViewsForDirWalk( $ctx -> viewId ), $ctx, null,
			function( &$ctx, $isUserCtx, $objFile )
			{
				if( $ctx -> isAborted() )
					return( false );

				if( $ctx -> op != 1 )
				{
					_CacheObjFileOp( $ctx -> lock, $objFile, $ctx -> op );
					return;
				}

				{
					$dscFileTm = @filemtime( $objFile );
					if( $dscFileTm >= 60 )
					{
						$dscFileTmAge = $ctx -> tmCur - $dscFileTm;
						$timeout = $isUserCtx ? $ctx -> timeoutCtx : $ctx -> timeout;

						if( $timeout > 0 && $dscFileTmAge > $timeout )
						{
							@unlink( $objFile );
							return;
						}
					}
				}

				$dsc = CacheReadDsc( $objFile );
				if( !$dsc )
				{

					return;
				}

				if( isset( $dsc[ 'l' ] ) )
				{
					unset( $ctx -> lrnsDel[ $dsc[ 'l' ] ] );

					$lrnDsc = Learn_ReadDsc( $ctx -> curViewDir . '/l/' . Learn_Id2File( $dsc[ 'l' ] ) );
					Learn_KeepNeededData( $ctx -> datasDel, $ctx -> lrnsGlobDel, $lrnDsc, $ctx -> lrnDataPath );
				}

				$dscType = Gen::GetFileExt( Gen::GetFileName( $objFile, true ) );
				_CacheOp_Clear_Dsc_MarkExistedParts( $ctx -> datasDel, $dsc, array( $dscType ) );
				foreach( Gen::GetArrField( $dsc, array( 'b' ), array() ) as $idSubPart => $dscPart )
					_CacheOp_Clear_Dsc_MarkExistedParts( $ctx -> datasDel, $dscPart, array( 'html', 'js', 'css' ) );
			}

			, $op == 2 ? function( &$ctx, $dataType, $dataId, $dataFile )
			{
				if( $ctx -> isAborted() )
					return( false );

				@unlink( $dataFile );
			} : null

			,
			null

			,
			function( &$ctx, $viewId, $viewDir, $begin )
			{
				if( $begin )
				{
					$ctx -> curViewDir = $viewDir;
					if( $ctx -> op == 2 )
						Gen::DelDir( $viewDir . '/l' );
				}

				if( $ctx -> op != 1 )
					return;

				if( $begin )
				{
					$ctx -> lrnsDel = array();

					if( Gen::DirEnum( $viewDir . '/l', $ctx,
						function( $path, $item, &$ctx )
						{
							if( $ctx -> isAborted() )
								return( false );

							if( @is_dir( $path . '/' . $item ) )
								return;

							while( strpos( $item, '.' ) !== false )
								$item = Gen::GetFileName( $item, true );
							$ctx -> lrnsDel[ Gen::GetFileName( $path ) . '/' . @hex2bin( $item ) ] = true;
						}
					, true ) === false )
					{
						return( false );
					}
				}
				else
				{
					foreach( $ctx -> lrnsDel as $learnId => $del )
						Learn_Clear( $viewDir . '/l/' . Learn_Id2File( $learnId ) );
				}
			}

			,
			function( &$ctx, $siteDir, $begin )
			{
				if( $begin )
				{
					$ctx -> lrnDataPath = $siteDir . '/l';
					$ctx -> lrnsGlobDel = array();

					if( $ctx -> op == 2 )
						Gen::DelDir( $ctx -> lrnDataPath );

					if( $ctx -> op != 1 )
						return;

					if( Gen::DirEnum( $ctx -> lrnDataPath, $ctx,
						function( $path, $item, &$ctx )
						{
							if( $ctx -> isAborted() )
								return( false );

							$path .= '/' . $item;
							if( !@is_dir( $path ) )
								$ctx -> lrnsGlobDel[ str_replace( '\\', '/', substr( $path, strlen( $ctx -> lrnDataPath ) + 1 ) ) ] = true;
						}
					, true ) === false )
					{
						return( false );
					}
				}
				else
				{
					foreach( $ctx -> lrnsGlobDel as $file => $del )
						@unlink( $siteDir . '/l/' . $file );

					if( $ctx -> op == 1 )
					{
						if( Images_ProcessSrcSizeAlternatives_Cache_Cleanup( $siteDir . '/d', $ctx -> tmCur - $ctx -> timeout, array( $ctx, 'isAborted' ) ) === false )
							return( false );

						if( Gen::DirEnum( $siteDir . '/eo', $ctx,
							function( $path, $item, &$ctx )
							{
								if( $ctx -> isAborted() )
									return( false );

								$path = $path . '/' . $item;
								if( @is_dir( $path ) || !Gen::GetFileExt( $item ) )
									return;

								$tmFile = @filectime( $path );
								if( $tmFile !== false )
								{
									if( $tmFile < $ctx -> tmCur - $ctx -> timeoutExtObj )
										@unlink( $path );
									else if( ( $tmFile = @filemtime( $path ) ) !== false && $tmFile < $ctx -> tmCur )
										@unlink( $path );
								}
							}
						, true ) === false )
						{
							return( false );
						}
					}
				}
			}
		) === false )
		{
			return( false );
		}
	}

	if( ( $op == 2 || $op == 0 ) )
	{

		BatCache_Clear();

		if( Gen::GetArrField( $sett, array( 'cache', 'srvClr' ), false ) )
			CacheExt_Clear();
	}

	if( $op == 1 )
	{
		foreach( $ctx -> datasDel as $datasDelType => $datasDel )
			if( empty( $datasDel ) )
				unset( $ctx -> datasDel[ $datasDelType ] );

		if( $ctx -> datasDel )
		{
			if( _CacheDirWalk( $ctx -> curSiteId, null, null, $ctx, null,
				null,
				function( &$ctx, $dataType, $dataId, $dataFile )
				{
					if( $ctx -> isAborted() )
						return( false );

					if( ($ctx -> datasDel[ $dataType ][ $dataId ]??null) )
					{
						$tmFile = @filemtime( $dataFile );
						if( $tmFile !== false && $ctx -> tmCur - $tmFile > min( $ctx -> timeout, ( 12 * 60 * 60 ) ) )
							@unlink( $dataFile );
					}
				}
			) === false )
			{
				return( false );
			}
		}

		if( Gen::DirEnum( GetCacheDir() . '/oc', $ctx,
			function( $path, $item, &$ctx )
			{
				if( $ctx -> isAborted() )
					return( false );

				$path = $path . '/' . $item;
				if( @is_dir( $path ) )
					return;

				$tmFile = @filemtime( $path );
				if( $tmFile !== false && $tmFile < $ctx -> tmCur )
					@unlink( $path );
			}
		, true ) === false )
		{
			return( false );
		}

		BatCache_Clear();

	}

	if( $op == 1 || $op == 2 )
	{
		$siteCacheRootPath = GetCacheDir() . '/s/' . $ctx -> curSiteId;

		foreach( glob( GetCacheViewsDir( $siteCacheRootPath ) . '*' ) as $viewDir )
		{
			if( Gen::DirEnum( $viewDir, $ctx,
				function( $path, $item, &$ctx )
				{
					if( $ctx -> isAborted() )
						return( false );

					$path = $path . '/' . $item;
					if( @is_dir( $path ) )
						@rmdir( $path );
				}
				, true
			) === false )
			{
				return( false );
			}
		}

		if( Gen::DirEnum( GetCacheDataDir( $siteCacheRootPath ), $ctx,
			function( $path, $item, &$ctx )
			{
				if( $ctx -> isAborted() )
					return( false );

				$path = $path . '/' . $item;
				if( @is_dir( $path ) )
					@rmdir( $path );
			}
		, true ) === false )
		{
			return( false );
		}
	}

	if( $cbIsAborted === true )
		PluginFileValues::Del( $ctx -> pluginFileValueName );
	return( true );
}

function _CacheObjFileOp( $lock, $objFile, $op )
{
	switch( $op )
	{
	case 0:
		$lock -> Acquire();

		if( Gen::StrEndsWith( $objFile, '.p' ) )
			@unlink( $objFile );
		else
			@touch( $objFile, 0 );

		$lock -> Release();
		break;

	case 2:
		$lock -> Acquire();

		@unlink( $objFile );
		$lock -> Release();
		break;
	}

}

function CacheOpUser( $userId, $op )
{
	$curSiteId = GetSiteId();

	$ctx = array( 'op' => $op, 'lock' => new DscLockUpdater() );

	_CacheDirWalk( $curSiteId, null, null, $ctx, array( 'userId' => $userId ),
		function( &$ctx, $isUserCtx, $objFile )
		{
			_CacheObjFileOp( $ctx[ 'lock' ], $objFile, $ctx[ 'op' ] );
		}
	);

}

function CacheOp_IsPostVisible( $post )
{
	return( in_array( $post -> post_status, array( 'publish' ) ) );
}

function CacheOpPost( $postId, $reason, $priority = 0, $proc = null, $cbIsAborted = false, $immediatelyPushQueue = true )
{
    $post = get_post( $postId );
	if( !$post )
		return;

	$sett = Plugin::SettGet();
	$op = Gen::GetArrField( $sett, array( 'cache', 'updPostOp' ), 0 );

	$lang = Wp::GetCurLang();
	$langPost = Wp::GetPostLang( $postId, $post -> post_type );

	Wp::SetCurLang( $langPost );

	$ctx = new AnyObj();
	$ctx -> cbIsAborted = $cbIsAborted;
	$ctx -> urls = array( get_permalink( $post ) );
	$ctx -> cb =
		function( $ctx, $url )
		{
			if( !is_bool( $ctx -> cbIsAborted ) && call_user_func( $ctx -> cbIsAborted ) )
				return( false );
			$ctx -> urls[] = $url;
		};

	if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
	{
		$txt = '';
		switch( $op )
		{
		case 0:		$txt .= $priority == 4 ? 'Scheduled automatic revalidation' : 'Automatic revalidation'; break;
		case 3:	$txt .= 'Automatic revalidation if needed'; break;
		case 2:				$txt .= $priority == 4 ? 'Scheduled automatic deleting' : 'Automatic deleting'; break;
		}

		$txt .= ' due to post with ID ' . $postId . ' ';
		if( $reason == 'delete' )
			$txt .= 'deleted';
		else
		{
			$txt .= 'changed';
			if( is_string( $reason ) )
				$txt .= ': ' . $reason;
		}
		$txt .= '; scope: URL(s): ' . implode( ', ', array_merge( $ctx -> urls, Gen::GetArrField( $sett, array( 'cache', 'updPostDeps' ), array() ) ) );

		LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
	}

	$bAborted = false;

	Wp::SetCurLang( $lang );

	if( $bAborted )
		return( false );

	if( $reason == 'delete' && $op !== 2 )
	{
		if( CacheOpUrls( false, $ctx -> urls[ 0 ], 2, $priority, $cbIsAborted, $proc ) === false )
			return( false );
		array_splice( $ctx -> urls, 0, 1 );
	}

	return( CacheOpUrls( false, $ctx -> urls, $op, $priority, $cbIsAborted, $proc, null, null, null, $immediatelyPushQueue ) );
}

function CacheOpCancel( $op )
{
	return( PluginFileValues::Del( ( $op === 1 ) ? 'cln' : 'o' ) );
}

function CacheGetCurOp( $op )
{
	return( PluginFileValues::Get( ( $op === 1 ) ? 'cln' : 'o' ) );
}

function CacheOpUrl_GetFullUrl( $url )
{
	if( ($url[ 0 ]??null) === '/' && ($url[ 1 ]??null) === '/' )
	{
	}
	else if( strpos( $url, '://' ) === false )
	{
		if( $url && $url[ 0 ] != '/' )
			$url = '/' . $url;
		$url = rtrim( Wp::GetSiteRootUrl(), '/' ) . $url;
	}

	return( $url );
}

function CacheOpUrl_ParseUrl( $url, &$siteAddr, &$siteSubId, &$path, &$query )
{
	global $seraph_accel_sites;

	$url = CacheOpUrl_GetFullUrl( $url );

	$urlComps = Net::UrlParse( $url, Net::URLPARSE_F_PATH_FIXFIRSTSLASH | Net::URLPARSE_F_PRESERVEEMPTIES );
	if( !($urlComps[ 'scheme' ]??null) )
		Net::GetUrlWithoutProtoEx( Wp::GetSiteRootUrl(), $urlComps[ 'scheme' ] );

	$host = Net::GetSiteAddrFromUrl( Net::UrlDeParse( $urlComps, 0, array(), array( PHP_URL_SCHEME, PHP_URL_HOST, PHP_URL_PORT ) ) );
	$path = CachePathNormalize( $urlComps[ 'path' ], $pathIsDir, false );
	$query = ($urlComps[ 'query' ]??null);
	$siteId = GetCacheSiteIdAdjustPath( $seraph_accel_sites, $host, $siteSubId, $path );
	$siteAddr = $urlComps[ 'scheme' ] . '://' . $host;

	if( $pathIsDir )
		$path .= '/';
	if( $path && $path[ 0 ] != '/' )
		$path = '/' . $path;

	return( $siteId );
}

function CacheOpUrl_DeParseUrl( $siteAddr, $path, $query = null )
{
	$url = $siteAddr . $path;
	if( $query !== null )
		$url .= '?' . $query;
	return( $url );
}

function CacheOpUrl_UpdateSrv( $cbIsAborted, $settCache, $url, $viewsHeaders, $viewId = null )
{
	CacheExt_Clear( $url );

	if( !Gen::GetArrField( $settCache, array( 'srvUpd' ), false ) )
		return;

	$aHdrs = array();
	foreach( $viewsHeaders as $id => $headers )
	{
		if( $viewId !== null && $viewId != CacheOpViewsHeadersGetViewId( $id ) )
			continue;

		$aHdrs[ $id ] = $headers;
	}

	CacheAdditional_WarmupUrl( $settCache, $url, $aHdrs, $cbIsAborted );
}

function CacheOpUrls( $isExpr, $urls, $op, $priority = 0, $cbIsAborted = true, $proc = null, $viewId = null, $geoId = null, $userId = null, $immediatelyPushQueue = true )
{
	if( $cbIsAborted === true && PluginFileValues::Get( 'o' ) !== null )
		return( false );

	if( !is_array( $urls ) )
		$urls = array( $urls );

	if( $cbIsAborted === true )
		PluginFileValues::Set( 'o', $op );

	$settCacheGlobal = Gen::GetArrField( Plugin::SettGetGlobal(), array( 'cache' ), array() );

	$ctx = new AnyObj();
	$ctx -> op = $op;
	$ctx -> curSiteId = GetSiteId();
	$ctx -> curSiteAddr = null;
	$ctx -> curQuery = null;
	$ctx -> curPathIsDir = false;
	$ctx -> cbIsAborted = $cbIsAborted;
	$ctx -> priority = $priority;
	$ctx -> viewId = _CacheOp_GetViews( $viewId, $geoId );
	$ctx -> userId = $userId;
	$ctx -> lock = new DscLockUpdater();
	$ctx -> procWorkInt = ($settCacheGlobal[ 'procWorkInt' ]??null);
	$ctx -> procPauseInt = ($settCacheGlobal[ 'procPauseInt' ]??null);
	$ctx -> _isAborted =
		function( $ctx )
		{
			if( $ctx -> cbIsAborted === true )
				return( PluginFileValues::Get( 'o' ) === null );
			if( $ctx -> cbIsAborted === false )
				return( false );
			return( call_user_func( $ctx -> cbIsAborted ) );
		};
	$ctx -> isAborted = function( $ctx ) { return( !Gen::SliceExecTime( $ctx -> procWorkInt, $ctx -> procPauseInt, 5, array( $ctx, '_isAborted' ) ) ); };

	unset( $settCacheGlobal );

	$ctx -> settCache = Gen::GetArrField( Plugin::SettGet(), array( 'cache' ), array() );
	$ctx -> viewsHeaders = CacheOpGetViewsHeaders( $ctx -> settCache, $ctx -> viewId );

	$ctx -> cbUrlOp =
		function( $ctx, $url )
		{
			if( CacheOpUrl_ParseUrl( $url, $ctx -> curSiteAddr, $siteSubId, $path, $ctx -> curQuery ) !== $ctx -> curSiteId )
				return;

			$ctx -> curPathIsDir = false;

			$recurse = false;
			$mask = false;
			if( Gen::StrEndsWith( $path, '/*' ) )
			{
				$path = substr( $path, 0, -1 );
				$recurse = true;
			}

			if( strpos( $path, '*' ) !== false )
			{
				$mask = true;
				$ctx -> curPathIsDir = Gen::StrEndsWith( $path, '/' );
			}

			if( _CacheDirWalk( $ctx -> curSiteId, $siteSubId, _CacheOp_GetViewsForDirWalk( $ctx -> viewId ), $ctx, array( 'objPath' => strtolower( trim( $path, '/' ) ), 'objPathRecurse' => $recurse, 'objPathMask' => $mask, 'userId' => $ctx -> userId ),
				function( &$ctx, $isUserCtx, $objFile )
				{
					if( $ctx -> isAborted() )
						return( false );

					if( $ctx -> op == 10 || $ctx -> op == 3 )
						return;

					if( $ctx -> op == 2 && $ctx -> priority != -480 )
						if( $dsc = CacheReadDsc( $objFile ) )
							if( isset( $dsc[ 'l' ] ) )
								Learn_Clear( $ctx -> curViewDir . '/l/' . Learn_Id2File( $dsc[ 'l' ] ) );

					_CacheObjFileOp( $ctx -> lock, $objFile, $ctx -> op );
				}

				, null

				, ( $recurse || $mask ) ? function( &$ctx, $isUserCtx, $siteRelPath )
				{

					if( $ctx -> op == 10 )
						CacheOpUrl_UpdateSrv( array( $ctx, '_isAborted' ), $ctx -> settCache, CacheOpUrl_DeParseUrl( $ctx -> curSiteAddr, $siteRelPath, $ctx -> curQuery ), $ctx -> viewsHeaders, $ctx -> curViewId );

				} : null

				,
				function( &$ctx, $viewId, $viewDir, $begin )
				{
					if( !$begin )
						return;

					$ctx -> curViewId = $viewId;
					$ctx -> curViewDir = $viewDir;
				}
			) === false )
			{
				return( false );
			}

			if( $recurse || $mask )
				return;

			if( $ctx -> op == 10 )
				CacheOpUrl_UpdateSrv( array( $ctx, '_isAborted' ), $ctx -> settCache, CacheOpUrl_DeParseUrl( $ctx -> curSiteAddr, $path, $ctx -> curQuery ), $ctx -> viewsHeaders );

		};

	$bAborted = false;
	foreach( $urls as $url )
		if( ( @call_user_func( array( $ctx, 'cbUrlOp' ), $url ) ) === false )
		{
			$bAborted = true;
			break;
		}

	$ctx -> lock -> Release( true );

	if( $cbIsAborted === true )
		PluginFileValues::Del( 'o' );

	return( !$bAborted );
}

function CacheOpViewsHeadersGetViewId( $id, &$geoId = null )
{
	$pos = strpos( $id, '-' );
	if( $pos === false )
	{
		$geoId = '';
		return( $id );
	}

	$geoId = substr( $id, $pos + 1 );
	return( substr( $id, 0, $pos ) );
}

function CacheOpGetViewsHeaders( $settCache, $viewId = null )
{
	$res = array();

	if( $viewId !== null && !is_array( $viewId ) )
		$viewId = array( $viewId );

	foreach( $viewId === null ? array( 'cmn' ) : $viewId as $viewIdI )
		if( CacheOpViewsHeadersGetViewId( $viewIdI ) == 'cmn' )
			$res[ $viewIdI ] = array( 'User-Agent' => 'Mozilla/99999.9 AppleWebKit/9999999.99 (KHTML, like Gecko) Chrome/999999.0.9999.99 Safari/9999999.99 seraph-accel-Agent/2.27.10' );

	if( ($settCache[ 'views' ]??null) )
	{
		$viewsDeviceGrps = Gen::GetArrField( $settCache, array( 'viewsDeviceGrps' ), array() );
		foreach( $viewsDeviceGrps as $viewsDeviceGrp )
		{
			if( !($viewsDeviceGrp[ 'enable' ]??null) )
				continue;

			$id = ($viewsDeviceGrp[ 'id' ]??null);
			foreach( $viewId === null ? array( $id ) : $viewId as $viewIdI )
				if( CacheOpViewsHeadersGetViewId( $viewIdI ) == $id )
					$res[ $viewIdI ] = array( 'User-Agent' => GetViewTypeUserAgent( $viewsDeviceGrp ) );

		}

		if( Gen::GetArrField( $settCache, array( 'viewsGeo', 'enable' ) ) )
		{
			$aGeoIdInfo = array();
			foreach( $res as $id => $aHdr )
			{
				CacheOpViewsHeadersGetViewId( $id, $geoId );
				$aGeoIdInfo[ $geoId ] = array();
			}

			$ipHost = gethostbyname( Gen::GetArrField( Net::UrlParse( Wp::GetSiteRootUrl() ), array( 'host' ), '' ) );
			foreach( $aGeoIdInfo as $geoId => &$ipInfo )
			{
				$ipInfo[ 'ip' ] = $ipHost;
				$ipInfo[ 'id' ] = GetViewGeoId( $settCache, array(), $ipInfo[ 'ip' ], $geoId );
			}
			unset( $ip );

			foreach( $res as $id => &$aHdr )
			{
				CacheOpViewsHeadersGetViewId( $id, $geoId );
				$aHdr[ 'X-Seraph-Accel-Geoid' ] = $aGeoIdInfo[ $geoId ][ 'id' ];
				$aHdr[ 'X-Seraph-Accel-Geo-Remote-Addr' ] = $aGeoIdInfo[ $geoId ][ 'ip' ];
			}
			unset( $aHdr );
		}
	}

	if( ($settCache[ 'opAgentPostpone' ]??null) )
	{
		foreach( $res as $id => &$aHdr )
		{
			$aHdr[ 'X-Seraph-Accel-Postpone-User-Agent' ] = $aHdr[ 'User-Agent' ];
			unset( $aHdr[ 'User-Agent' ] );
		}
		unset( $aHdr );
	}

	return( $res );
}

function OnOptDel_Sett()
{
	return( CacheInitEnv( Plugin::SettGetGlobal(), Plugin::SettGet() ) );
}

function CacheVerifyEnvDropin( $sett, $verifyEnvDropin = null )
{
	$file = WP_CONTENT_DIR . '/advanced-cache.php';
	$cont = @file_get_contents( $file );

	if( IsEnvDropinLockedBy( $file, $cont ) )
		return( true );

	if( $verifyEnvDropin === null )
		$verifyEnvDropin = new AnyObj();

	$verifyEnvDropin -> needed = str_replace( '.0,', ',', ( string )GetAdvCacheFileContent( $sett ) );
	$verifyEnvDropin -> actual = str_replace( '.0,', ',', ( string )$cont );

	if( $verifyEnvDropin -> actual == $verifyEnvDropin -> needed )
		return( true );

}

function CacheVerifyEnvObjDropin( $settGlob, $verifyEnvDropin = null )
{
	if( $verifyEnvDropin === null )
		$verifyEnvDropin = new AnyObj();

	$verifyEnvDropin -> needed = str_replace( '.0,', ',', ( string )GetObjCacheFileContent( $settGlob ) );
	$verifyEnvDropin -> actual = str_replace( '.0,', ',', ( string )@file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ) );

	if( $verifyEnvDropin -> actual == $verifyEnvDropin -> needed )
		return( true );

}

function CacheGetEnvNginxConfFile()
{
	return( dirname( WP_CONTENT_DIR ) . '/seraph-accel-img-compr-redir.conf' );
}

function CacheVerifyEnvNginxConf( $settGlob, $sett, $verifyEnvDropin = null )
{
	if( $verifyEnvDropin === null )
		$verifyEnvDropin = new AnyObj();

	$verifyEnvDropin -> needed = CacheGetEnvNginxConf( $settGlob, $sett );
	$verifyEnvDropin -> actual = @file_get_contents( CacheGetEnvNginxConfFile() );
	return( $verifyEnvDropin -> actual == $verifyEnvDropin -> needed );
}

function _CacheGetEnvConfPrms( $settGlob, $sett, $init )
{
	$imgTypesCnvFrom_RegExpEnum = implode( '|', array( 'jpe','jpg','jpeg','png','gif','bmp', 'webp','avif' ) );

	$siteRootUri = null;
	$dataUri = null;
	$dataPath = GetCacheDir();
	{
		$bCurBlogRevert = false;
		if( is_multisite() && defined( 'BLOG_ID_CURRENT_SITE' ) && get_current_blog_id() != BLOG_ID_CURRENT_SITE )
		{
			switch_to_blog( BLOG_ID_CURRENT_SITE );
			$bCurBlogRevert = true;
		}

		$ctxProcess = &GetContentProcessCtx( $_SERVER, $sett );

		if( $bCurBlogRevert )
			restore_current_blog();

		$dataPath = str_replace( '\\', '/', $dataPath );
		$dataUri = $ctxProcess[ 'siteRootUri' ] . '/' . substr( $dataPath, strlen( $ctxProcess[ 'siteRootDataPath' ] ) + 1 );
		$siteRootUri = $ctxProcess[ 'siteRootUri' ];

		unset( $ctxProcess );
	}

	$ctxMs = new AnyObj();
	$ctxMs -> settAgg = array();
	$ctxMs -> aSite = array();
	$ctxMs -> cb =
		function( $ctxMs, $siteId, $site, $sett, $availablePlugins )
		{
			$info = array(
				'addr' => Net::GetUrlWithoutProto( Gen::SetLastSlash( Wp::GetSiteRootUrl(), true ) ),
				'sett' => array(),
			);

			Gen::SetArrField( $info[ 'sett' ], array( 'contPr', 'img' ), Gen::GetArrField( $sett, array( 'contPr', 'img' ), array() ) );

			if( Gen::GetArrField( $sett, array( 'contPr', 'img', 'szAdaptAsync' ), false ) )
				Gen::SetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptAsync' ), true );
			if( Gen::GetArrField( $sett, array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) )
				Gen::SetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptOnDemand' ), true );
			if( Gen::GetArrField( $sett, array( 'hdrTrace' ), false ) )
				Gen::SetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), true );

			foreach( array_reverse( array( 'webp','avif' ) ) as $comprType )
				if( Gen::GetArrField( $sett, array( 'contPr', 'img', $comprType, 'redir' ), false ) )
					Gen::SetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', $comprType, 'redir' ), true );

			if( Gen::GetArrField( $sett, array( 'cacheBr', 'enable' ), false ) )
			{
				Gen::SetArrField( $ctxMs -> settAgg, array( 'cacheBr', 'enable' ), true );

				if( ( $v = Gen::GetArrField( $sett, array( 'cacheBr', 'timeout' ), 0 ) ) > Gen::GetArrField( $ctxMs -> settAgg, array( 'cacheBr', 'timeout' ), 0 ) )
					Gen::SetArrField( $ctxMs -> settAgg, array( 'cacheBr', 'timeout' ), $v );
			}

			$ctxMs -> aSite[ $siteId ] = $info;
		}
	;

	$bMultiSite = _DropInInit_EnumSites( array( $ctxMs, 'cb' ), $sett, $init );

	return( array( 'imgTypesCnvFrom_RegExpEnum' => $imgTypesCnvFrom_RegExpEnum, 'siteRootUri' => $siteRootUri, 'dataUri' => $dataUri, 'dataPath' => $dataPath, 'ctxMs' => $ctxMs, 'bMultiSite' => $bMultiSite ) );
}

function _CacheGetEnvNginxConf_GetSiteExpr( $aSite, $exprNegative = 'set $site "";', $exprPositive = null )
{
	$expr = '';

	if( isset( $aSite[ 'm' ] ) )
	{
		$expr .= ' if ($addrSite ~* ^[\\w\\-\\.]*\\.' . preg_quote( $aSite[ 'm' ] ) . ') { set $site "${site}S"; }';
		unset( $aSite[ 'm' ] );
	}

	$expr = ( $aSite ? ( ' if ($addrSite ~* ^[\\w\\-\\.]*\\.(?:' . implode( '|', array_map( 'preg_quote', $aSite ) ) . ')) { set $site "${site}S"; }' ) : '' ) . ' if ($siteSub != "") { set $site "${site}A"; }' . $expr;

	$expr = 'set $site "";' . $expr;

	if( is_string( $exprNegative ) )
		$expr .= ' if ($site ~ ^(?:$|[^S])) { ' . $exprNegative . ' }';
	if( is_string( $exprPositive ) )
		$expr .= ' if ($site ~ ^S) { ' . $exprPositive . ' }';

	return( $expr );
}

function CacheGetEnvNginxConf( $settGlob, $sett, $init = true )
{

	extract( _CacheGetEnvConfPrms( $settGlob, $sett, $init ) );

	$confComprRedirBlock = '';

	if( !Gen::GetArrField( $settGlob, array( 'contPr', 'img', 'redirOwn' ), false ) )
	{
		$bAiRedir = ( Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptAsync' ), false ) || Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) ) && $dataUri;
		if( $bAiRedir )
		{
			$confComprRedirBlock .=
				"\t" . 'set $file_ai "";' . "\n\t" . 'if ($arg_seraph_accel_ai ~* ^([\w\-]+)\.([\w\-]+)$) { set $arg_seraph_accel_ai ""; set $ai_s "$1"; set $ai_f "$2"; set $file_ai "' . $dataPath . '/s/${ai_s}/ai/${ai_f}.${file_ext}"; }' . "\n\t" . 'if (-f $file_ai) { rewrite . ' . $dataUri . '/s/${ai_s}/ai/${ai_f}.${file_ext}; set $file_ai ""; }' . "\n" .
				'';

			if( Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) )
			{
				if( $bMultiSite )
				{
					$aSitesMatch = array();
					foreach( $ctxMs -> aSite as $siteId => $info )
						if( Gen::GetArrField( $info[ 'sett' ], array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) )
							$aSitesMatch[ $siteId ] = $info[ 'addr' ];

					if( count( $aSitesMatch ) != count( $ctxMs -> aSite ) )
						$confComprRedirBlock .=	"\t" . _CacheGetEnvNginxConf_GetSiteExpr( $aSitesMatch, 'set $file_ai "";' ) . "\n";
				}

				$confComprRedirBlock .=
					"\t" . 'if ($file_ai) { rewrite ^(.*)$ ' . str_replace( array( '.seraph_accel_gi.', '.ai.' ), array( '$1', '${ai_s}.${ai_f}' ), Net::UrlDeParse( array( 'path' => $siteRootUri . '/', 'query' => Image_MakeOwnRedirUrlArgsEx( '.seraph_accel_gi.', '.ai.' ) ), Net::URLPARSE_F_PRESERVEEMPTIES ) ) . '&intrnl=${ai_s} last; break; }' . "\n" .
					'';
			}
		}

		$redirCacheAdapt = Gen::GetArrField( $settGlob, array( 'contPr', 'img', 'redirCacheAdapt' ), false );

		$aRedir = array();
		foreach( array_reverse( array( 'webp','avif' ) ) as $comprType )
		{
			if( !Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', $comprType, 'redir' ), false ) )
				continue;

			if( $confComprRedirBlock )
				$confComprRedirBlock .= "\n";

			$confComprRedirBlock .=
				"\t" . '# ' . $comprType . '' . "\n\t" . 'types { image/' . $comprType . ' ' . $comprType . '; }' . "\n\t" . 'set $' . $comprType . '_redir "";' . "\n\t" . 'if ($http_accept ~* "image\\/' . $comprType . '") { set $' . $comprType . '_redir "${' . $comprType . '_redir}A"; }' . "\n\t" . 'if (-f $request_filename.' . $comprType . ') { set $' . $comprType . '_redir "${' . $comprType . '_redir}F"; set $any_redir "R"; }' . "\n" .
				'';

			$aSitesMatch = array();
			foreach( $ctxMs -> aSite as $siteId => $info )
				if( Gen::GetArrField( $info[ 'sett' ], array( 'contPr', 'img', $comprType, 'redir' ), false ) )
				{
					$aSitesMatch[ $siteId ] = $info[ 'addr' ];
					$aRedir[ $siteId ] = $info[ 'addr' ];
				}

			if( $bMultiSite && count( $aSitesMatch ) != count( $ctxMs -> aSite ) )
				$confComprRedirBlock .=	"\t" . _CacheGetEnvNginxConf_GetSiteExpr( $aSitesMatch, 'set $' . $comprType . '_redir "";' ) . "\n";

			$confComprRedirBlock .=
				"\t" . 'if ($' . $comprType . '_redir = "AF") { add_header Vary Accept;' . ( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) ? ' add_header X-Seraph-Accel-Cache "state=preoptimized; redir=conf;";' : '' ) . ' rewrite (.*) $1.' . $comprType . ( $redirCacheAdapt ? ' redirect;' : '' ) .' break; }' . "\n" .
				'';
		}

		if( $aRedir || $bAiRedir )
		{
			$confComprRedirBlockPrefix = '';

			if( $aRedir )
				$confComprRedirBlockPrefix .= "\t" . 'set $any_redir "";' . "\n";

			if( $bAiRedir )
				$confComprRedirBlockPrefix .= "\t" . 'set $file_ext "$1";' . "\n";

			if( $bMultiSite )
			{
				$confComprRedirBlockPrefix .= "\t" . 'set $addrSite ".${host}${request_uri}"; set $siteSub "";';

				$aSubSites = array();
				foreach( $ctxMs -> aSite as $siteId => $info )
					if( $siteId != 'm' )
						$aSubSites[] = $info[ 'addr' ];

				if( $aSubSites )
					$confComprRedirBlockPrefix .= ' if ($addrSite ~* ^[\\w\\-\\.]*\\.(?:' . implode( '|', array_map( 'preg_quote', $aSubSites ) ) . ')) { set $siteSub "Y"; }';

				$confComprRedirBlockPrefix .= "\n";
			}

			$confComprRedirBlock = $confComprRedirBlockPrefix . "\n" . $confComprRedirBlock;

			if( $aRedir )
			{
				$confComprRedirBlock .=
					"\n" .
					( $bMultiSite && count( $aRedir ) != count( $ctxMs -> aSite ) ? ( "\t" . _CacheGetEnvNginxConf_GetSiteExpr( $aRedir, 'set $any_redir "N";' ) . "\n" ) : '' ) .
					"\t" . 'if ($any_redir = "") { add_header Vary Accept;' . ( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) ? ' add_header X-Seraph-Accel-Cache "state=original; redir=conf;";' : '' ) . ' }' . "\n" .
					'';
			}

			unset( $confComprRedirBlockPrefix );
		}
	}

	if( $confComprRedirBlock )
	{
		$confTypesFromBlock = '';
		foreach( array( 'jpe','jpg','jpeg','png','gif','bmp' ) as $type )
			$confTypesFromBlock .= "\t\t" . Fs::GetMimeContentType( '.' . $type ) . "\t" . $type . ';' . "\n";

		$confComprRedirBlock =
			'location ~ .*\.(' . $imgTypesCnvFrom_RegExpEnum . ')$' . "\n" .
			'{' . "\n\t" . 'types' . "\n\t" . '{' . "\n" .
			$confTypesFromBlock .
			"\t" . '}' . "\n\n" .
			$confComprRedirBlock .
			'}' . "\n" .
			'';
	}
	else
		$confComprRedirBlock =
			'# Empty' . "\n";

	$confComprRedirBlock = '# seraphinite-accelerator - Automatic redirection to Avif and WebP versions if they exist' . "\n" . $confComprRedirBlock;
	return( $confComprRedirBlock );
}

function _OpCache_Invalidate( $file )
{
	global $seraph_accel_g_phpCfgFileChangedInCurrentSession;

	$seraph_accel_g_phpCfgFileChangedInCurrentSession = true;

	if( function_exists( 'opcache_invalidate' ) )
		@opcache_invalidate( $file, true );
}

function IsEnvDropinLockedBy( $file, $cont = null )
{
	if( !file_exists( $file ) )
		return( false );

	if( is_writable( $file ) )
		return( false );

	if( strpos( $cont, '$batcache' ) !== false )
		return( 'batcache' );

	return( 'unk' );
}

function CacheInitEnvDropin( $sett, $init = true )
{
	$file = WP_CONTENT_DIR . '/advanced-cache.php';
	$cont = @file_get_contents( $file );
	$hr = Gen::S_OK;

	if( $sLock = IsEnvDropinLockedBy( $file, $cont ) )
	{
		$cont = Wp::Config_GetBlock( 'seraphinite-accelerator' );
		if( $cont === false )
			return( Gen::E_FAIL );

		$contNew = _GetAdvCacheFileContent( $sett, true, $init );
		if( $contNew && $sLock == 'batcache' )
		{
			$contNew = 'define( \'SERAPH_ACCEL_ADVCACHE_COMP\', true );' . "\n" . $contNew;
			if( $sLock == 'batcache' )
				$contNew .= '$batcache[ \'cache_control\' ] = false; $batcache[ \'use_stale\' ] = false; $batcache[ \'times\' ] = 1; $batcache[ \'unique\' ][] = !empty( $_SERVER[ \'HTTP_USER_AGENT\'] ) && preg_match( \'/(Android|Mobile|iPod|iPhone|MobileSafari|webOS|BlackBerry|windows phone|symbian|vodafone|opera mini|windows ce|smartphone|palm|midp)/i\', $_SERVER[ \'HTTP_USER_AGENT\' ] ) ? \'mobile\' : \'desktop\';';
			$contNew = "\n" . $contNew . "\n";
		}

		if( $cont != $contNew )
		{
			$hr = Gen::HrAccom( $hr, Wp::Config_SetBlock( 'seraphinite-accelerator', $contNew ) );
			_OpCache_Invalidate( Wp::GetConfigFilePath() );
		}

		return( $hr );
	}
	else if( Wp::Config_GetBlock( 'seraphinite-accelerator' ) )
		$hr = Gen::HrAccom( $hr, Wp::Config_SetBlock( 'seraphinite-accelerator', '' ) );

	if( !$init && ( !$cont || strpos( $cont, '/* seraphinite-accelerator */' ) === false ) )
		return( Gen::S_OK );

	$contNew = GetAdvCacheFileContent( $sett, $init );

	if( $cont != $contNew )
	{
		$hr = Gen::HrAccom( $hr, @file_put_contents( $file, $contNew ) !== false ? Gen::S_OK : Gen::E_FAIL );
		_OpCache_Invalidate( $file );
	}

	return( $hr );
}

function CacheInitEnvObjDropin( $settGlob, $init = true )
{
	$file = WP_CONTENT_DIR . '/object-cache.php';
	$cont = @file_get_contents( $file );

	if( !$init && ( !$cont || strpos( $cont, '/* seraphinite-accelerator */' ) === false ) )
		return( Gen::S_OK );

	$contNew = GetObjCacheFileContent( $settGlob, $init );

	$hr = Gen::S_OK;
	if( $cont != $contNew )
	{
		$hr = Gen::HrAccom( $hr, ( strlen( $contNew ) ? @file_put_contents( $file, $contNew ) : @unlink( $file ) ) !== false ? Gen::S_OK : Gen::E_FAIL );
		_OpCache_Invalidate( $file );

		if( strlen( $contNew ) )
			Gen::DelDir( GetCacheDir() . '/oc', false );
	}

	return( $hr );
}

function IsWpCacheActive()
{
	return( defined( 'WP_CACHE' ) && WP_CACHE );
}

function CacheInitEnv( $settGlob, $sett, $init = true )
{
	$cacheEnable = Gen::GetArrField( $sett, 'cache/enable', true, '/' );

	if( !$cacheEnable || !$init )
	{
		CacheInitEnvDropin( $sett, false );

		CacheInitClearProcessor( true, false );
		CacheInitOperScheduler( true, false );
	}

	if( !$init )
	{
		CacheInitEnvObjDropin( $settGlob, false );

		if( Gen::HtAccess_IsSupported() )
			Gen::HtAccess_SetBlock( 'seraphinite-accelerator', '' );

		{
			$confComprRedirBlock = CacheGetEnvNginxConf( $settGlob, $sett, false );

			$fileConfComprRedir = CacheGetEnvNginxConfFile();
			if( @file_get_contents( $fileConfComprRedir ) !== $confComprRedirBlock )
				@file_put_contents( $fileConfComprRedir, $confComprRedirBlock );
		}

		return( Gen::S_OK );
	}

	$hr = Gen::S_OK;

	if( $cacheEnable )
	{
		$hr = Gen::HrAccom( $hr, CacheInitEnvDropin( $sett ) );
		if( !IsWpCacheActive() )
		{
			$hr = Gen::HrAccom( $hr, Php::File_SetDefineVal( Wp::GetConfigFilePath(), 'WP_CACHE', true ) );
			_OpCache_Invalidate( Wp::GetConfigFilePath() );

		}

		$hr = Gen::HrAccom( $hr, Gen::MakeDir( GetCacheDir(), true ) );
	}

	$hr = Gen::HrAccom( $hr, CacheInitEnvObjDropin( $settGlob, Gen::GetArrField( $settGlob, array( 'cacheObj', 'enable' ), false ) ) );

	extract( _CacheGetEnvConfPrms( $settGlob, $sett, $init ) );

	if( $aHtAccessSoft = Gen::HtAccess_IsSupported() )
	{

		$htaccessBlock = '';

		if( Gen::GetArrField( $ctxMs -> settAgg, array( 'cacheBr', 'enable' ), false ) )
		{
			$tmStr = '"access plus ' . Gen::GetArrField( $ctxMs -> settAgg, array( 'cacheBr', 'timeout' ), 0 ) . ' minutes"';

			$htaccessBlock .=
				'<IfModule mod_mime.c>' . "\n\t" . 'AddType image/avif .avif' . "\n\t" . 'AddType image/webp .webp' . "\n\t" . 'AddType application/font-woff2 .woff2' . "\n\t" . 'AddType application/x-font-opentype .otf' . "\n" .
				'</IfModule>' . "\n" .
				'<IfModule mod_expires.c>' . "\n\t" . 'ExpiresActive on' . "\n\t" . 'ExpiresByType text/css                      ' . $tmStr . "\n\t" . 'ExpiresByType text/javascript               ' . $tmStr . "\n\t" . 'ExpiresByType application/javascript        ' . $tmStr . "\n\t" . 'ExpiresByType application/x-javascript      ' . $tmStr . "\n\t" . 'ExpiresByType font/eot                      ' . $tmStr . "\n\t" . 'ExpiresByType font/opentype                 ' . $tmStr . "\n\t" . 'ExpiresByType font/woff                     ' . $tmStr . "\n\t" . 'ExpiresByType application/vnd.ms-fontobject ' . $tmStr . "\n\t" . 'ExpiresByType application/font-woff         ' . $tmStr . "\n\t" . 'ExpiresByType application/font-woff2        ' . $tmStr . "\n\t" . 'ExpiresByType application/x-font-ttf        ' . $tmStr . "\n\t" . 'ExpiresByType application/x-font-woff       ' . $tmStr . "\n\t" . 'ExpiresByType image/vnd.microsoft.icon      ' . $tmStr . "\n\t" . 'ExpiresByType image/x-icon                  ' . $tmStr . "\n\t" . 'ExpiresByType image/bmp                     ' . $tmStr . "\n\t" . 'ExpiresByType image/gif                     ' . $tmStr . "\n\t" . 'ExpiresByType image/jpeg                    ' . $tmStr . "\n\t" . 'ExpiresByType image/png                     ' . $tmStr . "\n\t" . 'ExpiresByType image/svg+xml                 ' . $tmStr . "\n\t" . 'ExpiresByType image/avif                    ' . $tmStr . "\n\t" . 'ExpiresByType image/webp                    ' . $tmStr . "\n\t" . 'ExpiresByType audio/ogg                     ' . $tmStr . "\n\t" . 'ExpiresByType video/mp4                     ' . $tmStr . "\n\t" . 'ExpiresByType video/ogg                     ' . $tmStr . "\n\t" . 'ExpiresByType video/webm                    ' . $tmStr . "\n" .
				'</IfModule>' . "\n" .
				'';

			if( !Gen::GetArrField( $settGlob, array( 'cache', 'chkNotMdfSince' ), false ) )
			{

				$htaccessBlock .=
					'<IfModule mod_headers.c>' . "\n\t" . 'Header unset ETag' . "\n" .
					'</IfModule>' . "\n" .
					'FileETag None' . "\n" .
					'';
			}
		}

		if( !Gen::GetArrField( $settGlob, array( 'contPr', 'img', 'redirOwn' ), false ) )
		{
			if( ( Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptAsync' ), false ) || Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) ) && $dataUri )
			{
				$htaccessBlock .=
					'<IfModule mod_rewrite.c>' . "\n\t" . 'RewriteEngine On' . "\n\t" . 'RewriteCond %{REQUEST_URI}?%{QUERY_STRING} \\.(' . $imgTypesCnvFrom_RegExpEnum . ')\\?(|.*\\&)seraph_accel_ai=([\\w\\-]+)\\.([\\w\\-]+)(\\&.*$|$) [NC]' . "\n\t" . 'RewriteCond "' . $dataPath . '/s/%3/ai/%4.%1" -f' . "\n\t" . 'RewriteRule . ' . $dataUri . '/s/%3/ai/%4.%1?%2%5 [L]' . "\n" .
					'</IfModule>' . "\n" .
					'';

				if( Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', 'szAdaptOnDemand' ), false ) )
				{

					$htaccessBlock .=
						'<IfModule mod_rewrite.c>' . "\n\t" . 'RewriteEngine On' . "\n\t" . 'RewriteCond %{REQUEST_URI}?%{QUERY_STRING} ([^?]+\\.(' . $imgTypesCnvFrom_RegExpEnum . '))\\?(|.*\\&)seraph_accel_ai=([\\w\\-]+)\\.([\\w\\-]+)(\\&[^\\s]*) [NC]' . "\n\t" . 'RewriteCond "' . $dataPath . '/s/%4/ai/%5.%2" !-f' . "\n\t" . 'RewriteRule . ' . str_replace( array( '.seraph_accel_gi.', '.ai.' ), array( '%1', '%4.%5' ), Net::UrlDeParse( array( 'path' => $siteRootUri . '/', 'query' => Image_MakeOwnRedirUrlArgsEx( '.seraph_accel_gi.', '.ai.' ) ), Net::URLPARSE_F_PRESERVEEMPTIES ) ) . '&intrnl=%4&%3%6 [L,' . ( version_compare( $aHtAccessSoft[ Gen::HTACCESS_SOFT_VER ], '2.4.57', '>=' ) ? 'BCTLS,END' : 'R=302' ) . ']' . "\n" .
						'</IfModule>' . "\n" .
						'';
				}
			}

			$redirCacheAdapt = Gen::GetArrField( $settGlob, array( 'contPr', 'img', 'redirCacheAdapt' ), false );

			$htaccessBlockRedir = '';
			foreach( array_reverse( array( 'webp','avif' ) ) as $comprType )
			{
				if( !Gen::GetArrField( $ctxMs -> settAgg, array( 'contPr', 'img', $comprType, 'redir' ), false ) )
					continue;

				$htaccessBlockRedir .=
					'<IfModule mod_rewrite.c>' . "\n\t" . 'RewriteEngine On' . "\n\t" . 'RewriteCond %{HTTP_ACCEPT} image\\/' . $comprType . "\n\t" . 'RewriteCond %{REQUEST_FILENAME} \\.(' . $imgTypesCnvFrom_RegExpEnum . ')$' . "\n\t" . 'RewriteCond %{REQUEST_FILENAME}.' . $comprType . ' -f' . "\n\t" . 'RewriteRule ^(.*)\\.(' . $imgTypesCnvFrom_RegExpEnum . ')$ $1\\.$2\\.' . $comprType . ' [QSA' . ( $redirCacheAdapt ? ',R' : '' ) . ']' . "\n" .
					'</IfModule>' . "\n" .
					'<IfModule mod_headers.c>' . "\n\t" . '<FilesMatch \\.(' . $imgTypesCnvFrom_RegExpEnum . ')\\.' . $comprType . '$>' . "\n\t\t" . 'Header merge Vary Accept' . "\n" .
					( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) && ($aHtAccessSoft[ Gen::HTACCESS_SOFT_SUBNAME ]??null) != 'ideawebserver' ? "\t\t" . 'Header set X-Seraph-Accel-Cache "state=preoptimized; redir=htaccess;"' . "\n" : '' ) .
					"\t" . '</FilesMatch>' . "\n" .
					'</IfModule>' . "\n" .
					'';
			}

			if( $htaccessBlockRedir )
			{
				$htaccessBlock .=
					'<IfModule mod_headers.c>' . "\n\t" . '<FilesMatch \\.(' . $imgTypesCnvFrom_RegExpEnum . ')$>' . "\n\t\t" . 'Header merge Vary Accept' . "\n" .
					( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) && ($aHtAccessSoft[ Gen::HTACCESS_SOFT_SUBNAME ]??null) != 'ideawebserver' ? "\t\t" . 'Header set X-Seraph-Accel-Cache "state=original; redir=htaccess;"' . "\n" : '' ) .
					"\t" . '</FilesMatch>' . "\n" .
					'</IfModule>' . "\n" .
					$htaccessBlockRedir;
			}
		}

		{
			$encs = Gen::GetArrField( $settGlob, array( 'cache', 'encs' ), array() );
			$mimeTypes = array(
				'text/plain',

				'text/css',

				'text/javascript',
				'application/javascript',
				'application/x-javascript',
				'application/json',

				'text/html',
				'text/xml',
				'application/atom+xml',
				'application/rss+xml',
				'application/xhtml+xml',
				'application/xml',
				'text/x-component',

				'application/vnd.ms-fontobject',
				'application/x-font-ttf',
				'font/eot',
				'font/opentype',

				'image/bmp',
				'image/svg+xml',
				'image/vnd.microsoft.icon',
				'image/x-icon',
			);

			if( in_array( 'br', $encs ) )
			{
				$htaccessBlock .=
					'<IfModule mod_brotli.c>' . "\n\t" . '<IfModule mod_filter.c>' . "\n\t\t" . 'AddOutputFilterByType BROTLI_COMPRESS';

				foreach( $mimeTypes as $mimeType )
					$htaccessBlock .= ' ' . $mimeType;

				$htaccessBlock .=
					"\n\t" . '</IfModule>' . "\n" .
					'</IfModule>' . "\n" .
					'';
			}

			if( in_array( 'gzip', $encs ) || in_array( 'deflate', $encs ) || in_array( 'compress', $encs ) )
			{
				$htaccessBlock .=
					'<IfModule mod_deflate.c>' . "\n\t" . '<IfModule mod_filter.c>' . "\n\t\t" . 'AddOutputFilterByType DEFLATE';

				foreach( $mimeTypes as $mimeType )
					$htaccessBlock .= ' ' . $mimeType;

				$htaccessBlock .=
					"\n\t" . '</IfModule>' . "\n" .
					'</IfModule>' . "\n" .
					'';
			}
		}

		if( UseGzAssets( Gen::GetArrField( $settGlob, array( 'cache' ), array() ) ) )
		{
			$dataComprs = Gen::GetArrField( $settGlob, array( 'cache', 'dataCompr' ), array() );

			if( in_array( 'brotli', $dataComprs ) )
			{
				$htaccessBlock .=
					'<IfModule mod_headers.c>' . "\n\t" . '<IfModule mod_rewrite.c>' . "\n\t\t" . 'RewriteEngine On' . "\n\t\t" . 'RewriteCond %{HTTP:Accept-Encoding} (^|\\W)br(\\W|$)' . "\n\t\t" . 'RewriteCond %{REQUEST_FILENAME} \\.(css|js)$' . "\n\t\t" . 'RewriteCond %{REQUEST_FILENAME}.br -f' . "\n\t\t" . 'RewriteRule ^(.*)\\.(css|js)$ $1\\.$2\\.br [QSA]' . "\n\t\t" . 'RewriteRule \\.css\\.br$ - [T=text/css,E=no-gzip:1,E=no-brotli:1]' . "\n\t\t" . 'RewriteRule \\.js\\.br$ - [T=application/javascript,E=no-gzip:1,E=no-brotli:1]' . "\n\t" . '</IfModule>' . "\n\t" . '<FilesMatch \\.(js|css)\\.br$>' . "\n\t\t" . 'Header set Content-Encoding br' . "\n\t\t" . 'Header merge Vary Accept-Encoding' . "\n" .
					( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) && ($aHtAccessSoft[ Gen::HTACCESS_SOFT_SUBNAME ]??null) != 'ideawebserver' ? "\t\t" . 'Header set X-Seraph-Accel-Cache "state=precompressed; redir=htaccess;"' . "\n" : '' ) .
					"\t" . '</FilesMatch>' . "\n" .
					'</IfModule>' . "\n" .
					'';
			}

			if( in_array( 'deflate', $dataComprs ) && ($aHtAccessSoft[ Gen::HTACCESS_SOFT_SUBNAME ]??null) != 'ideawebserver' )
			{
				$htaccessBlock .=
					'<IfModule mod_headers.c>' . "\n\t" . '<IfModule mod_rewrite.c>' . "\n\t\t" . 'RewriteEngine On' . "\n\t\t" . 'RewriteCond %{HTTP:Accept-Encoding} (^|\\W)gzip(\\W|$)' . "\n\t\t" . 'RewriteCond %{REQUEST_FILENAME} \\.(css|js)$' . "\n\t\t" . 'RewriteCond %{REQUEST_FILENAME}.gz -f' . "\n\t\t" . 'RewriteRule ^(.*)\\.(css|js)$ $1\\.$2\\.gz [QSA]' . "\n\t\t" . 'RewriteRule \\.css\\.gz$ - [T=text/css,E=no-gzip:1,E=no-brotli:1]' . "\n\t\t" . 'RewriteRule \\.js\\.gz$ - [T=application/javascript,E=no-gzip:1,E=no-brotli:1]' . "\n\t" . '</IfModule>' . "\n\t" . '<FilesMatch \\.(js|css)\\.gz$>' . "\n\t\t" . 'Header set Content-Encoding gzip' . "\n\t\t" . 'Header merge Vary Accept-Encoding' . "\n" .
					( Gen::GetArrField( $ctxMs -> settAgg, array( 'hdrTrace' ), false ) && ($aHtAccessSoft[ Gen::HTACCESS_SOFT_SUBNAME ]??null) != 'ideawebserver' ? "\t\t" . 'Header set X-Seraph-Accel-Cache "state=precompressed; redir=htaccess;"' . "\n" : '' ) .
					"\t" . '</FilesMatch>' . "\n" .
					'</IfModule>' . "\n" .
					'';
			}
		}

		$htaccessBlock = trim( $htaccessBlock );

		if( Gen::HtAccess_GetBlock( 'seraphinite-accelerator' ) != $htaccessBlock )
			$hr = Gen::HrAccom( $hr, Gen::HtAccess_SetBlock( 'seraphinite-accelerator', $htaccessBlock, 5 ) );
	}

	{
		$confComprRedirBlock = CacheGetEnvNginxConf( $settGlob, $sett );

		$fileConfComprRedir = CacheGetEnvNginxConfFile();
		if( @file_get_contents( $fileConfComprRedir ) !== $confComprRedirBlock )
			@file_put_contents( $fileConfComprRedir, $confComprRedirBlock );
	}

	return( $hr );
}

function GetCacheStatusInfo( $siteId, $cbCancel )
{
	$info = CacheGetInfo( $siteId, $cbCancel );
	if( !$info )
		return( null );

	return( $info );
}

function _AddSiteIdSites( &$sitesIds, $addrSite, $siteId, $availablePlugins )
{
	$sitesIds[ $addrSite ] = $siteId;

	$aAddrSite = array();

	if( in_array( 'sitepress-multilingual-cms', $availablePlugins ) )
	{
		$sitePath = Gen::GetArrField( Net::UrlParse( 'http://' . $addrSite ), array( 'path' ) );
		foreach( Gen::GetArrField( get_option( 'icl_sitepress_settings' ), array( 'language_domains' ), array() ) as $lang => $domain )
			$aAddrSite[] = rtrim( $domain . $sitePath, '/' );
	}

	if( in_array( 'polylang', $availablePlugins ) || in_array( 'polylang-pro', $availablePlugins ) )
	{
		$plgOpts = Gen::GetArrField( get_option( 'polylang' ), array( '' ), array() );
		$forceLang = Gen::GetArrField( $plgOpts, array( 'force_lang' ) );
		if( $forceLang == 2 || $forceLang == 3 )
		{
			foreach( Gen::GetArrField( $plgOpts, array( 'domains' ), array() ) as $lang => $url )
			{
				if( $forceLang == 2 )
					$aAddrSite[] = $lang . '.' . $addrSite;
				else if( $urlComps = Net::UrlParse( $url ) )
					$aAddrSite[] = trim( Net::UrlDeParse( $urlComps, 0, array(), array( PHP_URL_HOST, PHP_URL_PORT, PHP_URL_PATH ) ), '/' );
			}
		}
	}

	if( in_array( 'multiple-domain', $availablePlugins ) )
	{
		foreach( Gen::GetArrField( get_option( 'multiple-domain-domains' ), array( '' ), array() ) as $domain => $opts )
		{
			$domain = rtrim( $domain, '/' );
			$path = trim( Gen::GetArrField( $opts, array( 'base' ), '' ), '/' );
			if( strlen( $path ) )
				$domain .= '/' . $path;
			$aAddrSite[] = $domain;
		}
	}

	if( defined( 'SERAPH_ACCEL_ALT_ROOTS' ) )
	{
		foreach( ( array )SERAPH_ACCEL_ALT_ROOTS as $url )
			if( $urlComps = Net::UrlParse( $url ) )
				$aAddrSite[] = trim( Net::UrlDeParse( $urlComps, 0, array(), array( PHP_URL_HOST, PHP_URL_PORT, PHP_URL_PATH ) ), '/' );
	}

	foreach( $aAddrSite as $addr )
		if( !isset( $sitesIds[ $addr ] ) )
			$sitesIds[ $addr ] = $siteId . '-' . md5( $addr );

}

function _DropInInit_EnumSites( $cb, $sett, $init = true )
{

	if( !Gen::DoesFuncExist( 'get_sites' ) || !is_multisite() )
	{
		$settSite = $init ? $sett : null;
		call_user_func( $cb, 'm', null, $settSite, $settSite ? Plugin::GetAvailablePlugins() : array() );
		return( null );
	}

	$idPlg = Plugin::GetCurBaseName( false );
	$idBlog = get_current_blog_id();
	foreach( get_sites() as $site )
	{
		if( $idBlog != $site -> blog_id )
			switch_to_blog( $site -> blog_id );

		$availablePlugins = Plugin::GetAvailablePlugins();

		$settSite = null;
		if( $idBlog != $site -> blog_id )
		{
			if( in_array( $idPlg, $availablePlugins ) )
			{
				Plugin::SettCacheClear();
				$settSite = Plugin::SettGet();
			}
		}
		else if( $init )
			$settSite = $sett;

		call_user_func( $cb, GetSiteId( $site ), $site, $settSite, $settSite ? $availablePlugins : array() );

		if( $idBlog != $site -> blog_id )
			restore_current_blog();
	}

	return( true );
}

function _GetAdvCacheFileContent( $sett, $bTiny = false, $init = true )
{
	$ctxMs = new AnyObj();
	$ctxMs -> bTiny = $bTiny;
	$ctxMs -> varExportTinyFmt = array( 'indent' => '', 'elemSpace' => '', 'assignSpaceBefore' => '', 'assignSpaceAfter' => '', 'escValNl' => true );
	$ctxMs -> sitesIds = array();
	$ctxMs -> content = '';
	$ctxMs -> cb =
		function( $ctxMs, $siteId, $site, $sett, $availablePlugins )
		{
			if( !$sett )
				return;

			$addrSite = strtolower( Net::GetUrlWithoutProto( Gen::SetLastSlash( Wp::GetSiteRootUrl(), false ) ) );
			_AddSiteIdSites( $ctxMs -> sitesIds, $addrSite, $siteId, $availablePlugins );

			$sSett = $ctxMs -> bTiny ? Gen::VarExport( $sett, $ctxMs -> varExportTinyFmt ) : var_export( $sett, true );
			if( $site )
				$ctxMs -> content .= 'function _seraph_accel_siteSettInlineDetach_' . $siteId . '(){ return ' . $sSett . '; }' . "\n";
			else
				$ctxMs -> content .= 'function seraph_accel_siteSettInlineDetach($siteId){ return ' . $sSett . '; }' . "\n";
		}
	;

	if( _DropInInit_EnumSites( array( $ctxMs, 'cb' ), $sett, $init ) )
	{
		if( $ctxMs -> content )
			$ctxMs -> content .= 'function seraph_accel_siteSettInlineDetach($siteId){ $fn = \'_seraph_accel_siteSettInlineDetach_\' . $siteId; return function_exists($fn) ? call_user_func($fn) : null; }' . "\n";
	}

	if( $ctxMs -> content )
		$ctxMs -> content .= '$seraph_accel_sites = ' . ( $ctxMs -> bTiny ? Gen::VarExport( $ctxMs -> sitesIds, $ctxMs -> varExportTinyFmt ) : var_export( $ctxMs -> sitesIds, true ) ) . ';' . "\n";

	return( $ctxMs -> content );
}

function GetAdvCacheFileContent( $sett, $init = true )
{
	$content = _GetAdvCacheFileContent( $sett, false, $init );

	if( $content )
	{

		$content = 'if( defined( \'SERAPH_ACCEL_ADVCACHE_COMP\' ) ) return;' . "\n" . $content;

		$content = '<?php' . "\n\n" .
			'/*' . "\n" .
			'Plugin Name: Seraphinite Accelerator - Advanced Cache (Drop-in)' . "\n" .
			'Plugin URI: http://wordpress.org/plugins/seraphinite-accelerator' . "\n" .
			'Description: Turns on site high speed to be attractive for people and search engines.' . "\n" .

			'Author: Seraphinite Solutions' . "\n" .
			'Author URI: https://www.s-sols.com' . "\n" .
			'*/' . "\n\n" .
			'/* seraphinite-accelerator */' . "\n" . $content;

		$content .= '@include(WP_CONTENT_DIR . \'/plugins/' . Plugin::GetCurBaseName( false ) . '/cache.php\');' . "\n";
		$content .= '?>';
	}
	else
	{
		$content .= '<?php /* Disabled by seraphinite-accelerator */';
	}

	return( $content );
}

function GetObjCacheFileContent( $sett, $init = true )
{
	$content = '';

	if( $init )
	{
		$content = '<?php' . "\n\n" .
			'/*' . "\n" .
			'Plugin Name: Seraphinite Accelerator - Object Cache (Drop-in)' . "\n" .
			'Plugin URI: http://wordpress.org/plugins/seraphinite-accelerator' . "\n" .
			'Description: Turns on site high speed to be attractive for people and search engines.' . "\n" .

			'Author: Seraphinite Solutions' . "\n" .
			'Author URI: https://www.s-sols.com' . "\n" .
			'*/' . "\n\n" .
			'/* seraphinite-accelerator */' . "\n" . $content;

		$content .= 'global $seraph_accel_settObjCache; $seraph_accel_settObjCache = ' . var_export( array( 'cacheObj' => Gen::GetArrField( $sett, array( 'cacheObj' ), array() ) ), true ) . ';' . "\n";

		$content .= '@include(WP_CONTENT_DIR . \'/plugins/' . Plugin::GetCurBaseName( false ) . '/cache_obj.php\');' . "\n";
		$content .= '?>';
	}

	return( $content );
}

function GetLoadAvg( $def = 0 )
{
	if( !function_exists( 'sys_getloadavg' ) )
		return( $def );

	$loadavg = sys_getloadavg();
	if( !is_array( $loadavg ) )
		return( $def );

	$loadavg = ( float )($loadavg[ 0 ]??null);
	if( $loadavg > 1 )
		$loadavg = 1;
	return( $loadavg !== null ? ( int )( round( 100 * $loadavg ) ) : $def );
}

function UpdateClientSessId( $curUserId, $token = null, $expirationNew = null )
{
	$siteId = GetSiteId();
	$tmCur = Gen::GetCurRequestTime();

	$sessInfo = GetCacheCurUserSession( $siteId );
	$sessId = ($sessInfo[ 'sessId' ]??null);

	if( $curUserId )
	{
		if( ($sessInfo[ 'userSessId' ]??null) != $token || ($sessInfo[ 'expiration' ]??null) != $expirationNew || ($sessInfo[ 'userId' ]??null) != $curUserId )
		{
			if( Gen::IsEmpty( $sessId ) )
				$sessId = wp_generate_password( 43, false, false );
			SetCacheCurUserSession( $siteId, $sessId, $token, $curUserId, $expirationNew );
		}
	}
	else if( Gen::IsEmpty( $sessId ) )
	{
		$set = false;
		{
			$cacheSkipData = GetContCacheEarlySkipData( $pathOrig, $path, $pathIsDir, $args );
			if( $cacheSkipData )
			{
				if( $cacheSkipData === array( 'skipped', array( 'reason' => 'noCacheSession' ) ) )
					$set = true;
			}
			else
			{
				$settCache = Gen::GetArrField( Plugin::SettGet(), array( 'cache' ), array() );
				if( ContProcGetExclStatus( $siteId, $settCache, $path, $pathOrig, $pathIsDir, $args, $varsOut, false, !($settCache[ 'enable' ]??null) ) == 'noCacheSession' )
					$set = true;
				unset( $varsOut );
			}
		}

		if( $set )
		{
			$sessId = wp_generate_password( 43, false, false );
			SetCacheCurUserSession( $siteId, $sessId, '0', 0, $tmCur + 12 * HOUR_IN_SECONDS );
		}
	}
	else if( ($sessInfo[ 'userId' ]??null) || ($sessInfo[ 'expiration' ]??null) < $tmCur )
		SetCacheCurUserSession( $siteId, $sessId, '0', 0, $tmCur + 12 * HOUR_IN_SECONDS );
}

