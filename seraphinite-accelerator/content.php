<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

require( __DIR__ . '/htmlparser.php' );
require( __DIR__ . '/content_img.php' );
require( __DIR__ . '/content_js.php' );
require( __DIR__ . '/content_css.php' );
require( __DIR__ . '/content_frm.php' );

function GetContentProcessCtxEx( $serverArgs, $sett, $siteId, $siteUrl, $siteRootPath, $siteContentPath, $wpRootSubPath, $cacheDir, $scriptDebug )
{
	$ctx = array(
		'siteDomainUrl' => Net::GetSiteAddrFromUrl( $siteUrl, true ),
		'siteRootUri' => Gen::SetLastSlash( Net::Url2Uri( $siteUrl ), false ),
		'siteRootPath' => Gen::SetLastSlash( $siteRootPath, false ),
		'siteContPath' => Gen::SetLastSlash( $siteContentPath, false ),
		'siteRootDataPath' => null,
		'siteCacheRootDir' => $cacheDir,
		'dataPath' => GetCacheDataDir( $cacheDir . '/s/' . $siteId ),
		'wpRootSubPath' => $wpRootSubPath . '/',
		'siteId' => $siteId,
		'deps' => array(),
		'subs' => array(),
		'subCurIdx' => 0,
		'debugM' => ($sett[ 'debug' ]??null),
		'debug' => ($sett[ 'debugInfo' ]??null),
		'jsMinSuffix' => $scriptDebug ? '' : '.min',
		'userAgent' => strtolower( isset( $_SERVER[ 'SERAPH_ACCEL_ORIG_USER_AGENT' ] ) ? $_SERVER[ 'SERAPH_ACCEL_ORIG_USER_AGENT' ] : ($serverArgs[ 'HTTP_USER_AGENT' ]??null) ),
		'mode' => ( 1 | 2 | 4 ),
		'modeReq' => 0,
		'aAttrImg' => array(),

		'aCssCrit' => array(),
		'aCssRpl' => array(),
		'aCssRplExcl' => array(),

		'bJsCssAddType' => apply_filters( 'seraph_accel_jscss_addtype', false ),

	);

	if( strpos( $ctx[ 'dataPath' ], $ctx[ 'siteRootPath' ] . '/' ) === 0 )
		$ctx[ 'siteRootDataPath' ] = $ctx[ 'siteRootPath' ];
	else if( strpos( $ctx[ 'dataPath' ], $ctx[ 'siteContPath' ] . '/' ) === 0 )
		$ctx[ 'siteRootDataPath' ] = Gen::GetFileDir( $ctx[ 'siteContPath' ] );
	else
		$ctx[ 'siteRootDataPath' ] = $cacheDir;

	$ctx[ 'compatView' ] = ContProcIsCompatView( Gen::GetArrField( $sett, array( 'cache' ), array() ), $ctx[ 'userAgent' ] );

	CorrectRequestScheme( $serverArgs );

	$ctx[ 'serverArgs' ] = $serverArgs;
	$ctx[ 'requestUriPath' ] = Gen::GetFileDir( ($serverArgs[ 'REQUEST_URI' ]??null) );
	$ctx[ 'host' ] = Gen::GetArrField( Net::UrlParse( $serverArgs[ 'REQUEST_SCHEME' ] . '://' . GetRequestHost( $serverArgs ) ), array( 'host' ) );
	if( !$ctx[ 'host' ] )
		$ctx[ 'host' ] = ($serverArgs[ 'SERVER_NAME' ]??null);

	$settContPr = Gen::GetArrField( $sett, array( 'contPr' ), array() );
	if( Gen::GetArrField( $settContPr, array( 'normUrl' ), false ) )
		$ctx[ 'srcUrlFullness' ] = Gen::GetArrField( $settContPr, array( 'normUrlMode' ), 0 );
	else
		$ctx[ 'srcUrlFullness' ] = 0;

	$ctx[ 'aVPth' ] = array_map( function( $vPth ) { $vPth[ 'f' ] .= 'S'; return( $vPth ); }, GetVirtUriPathsFromSett( $sett ) );

	return( $ctx );
}

function &GetContentProcessCtx( $serverArgs, $sett )
{
	global $seraph_accel_g_ctxProcess;

	if( !$seraph_accel_g_ctxProcess )
	{
		$siteRootUrl = Wp::GetSiteRootUrl();

		$siteWpRootSubPath = rtrim( Wp::GetSiteWpRootUrl( '', null, true ), '/' );
		if( strpos( $siteWpRootSubPath, rtrim( $siteRootUrl, '/' ) ) === 0 )
			$siteWpRootSubPath = trim( substr( $siteWpRootSubPath, strlen( rtrim( $siteRootUrl, '/' ) ) ), '/' );
		else
			$siteWpRootSubPath = '';

		if( defined( 'SERAPH_ACCEL_SITEROOT_DIR' ) )
			$siteRootPath = SERAPH_ACCEL_SITEROOT_DIR;
		else
		{
			$siteRootPath = ABSPATH;
			if( $siteWpRootSubPath && Gen::StrEndsWith( rtrim( $siteRootPath, '\\/' ), $siteWpRootSubPath ) )
				$siteRootPath = substr( rtrim( $siteRootPath, '\\/' ), 0, - strlen( $siteWpRootSubPath ) );
		}

		$seraph_accel_g_ctxProcess = GetContentProcessCtxEx( $serverArgs, $sett, GetSiteId(), $siteRootUrl, $siteRootPath, WP_CONTENT_DIR, $siteWpRootSubPath, GetCacheDir(), defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
		ContentProcess_InitLocalCbs( $seraph_accel_g_ctxProcess );
	}

	return( $seraph_accel_g_ctxProcess );
}

function ContentProcess_InitLocalCbs( &$ctxProcess )
{
	$cbs = new AnyObj();
	$cbs -> ctxProcess = &$ctxProcess;

	$cbs -> ReportStage =
		function( $cbs, $stage = null, $stageDsc = null )
		{
			$dataUpd = array( 'stageDsc' => $stageDsc );
			if( $stage )
				$dataUpd[ 'stage' ] = $stage;

			global $seraph_accel_g_prepPrms;
			return( $seraph_accel_g_prepPrms ? ProcessCtlData_Update( ($seraph_accel_g_prepPrms[ 'pc' ]??null), $dataUpd ) : true );
		};

	$cbs -> IsAborted =
		function( $cbs, $ctxProcess = null, $settCache = null )
		{
			return( ContentProcess_IsAborted( $ctxProcess, $settCache ) );
		};

	$cbs -> GetContentProcessorForce =
		function( $cbs, $sett )
		{
			return( GetContentProcessorForce( $sett ) );
		};

	$cbs -> ContPostProc =
		function( $cbs, $type, $content, $isFile = true )
		{
			if( $type == 'css' )
				$content = apply_filters( 'seraph_accel_css_content', $content, $isFile );
			else if( $type == 'js' )
				$content = apply_filters( 'seraph_accel_js_content', $content, $isFile );
			return( $content );
		};

	$cbs -> PreFetchLocalFiles =
		function( $cbs, $a, $cont = true )
		{
			return( array_keys( ContentProcess_PreFetchLocalFiles_Expand( $cbs -> ctxProcess, $a ) ) );
		};

	$cbs -> LocalFileExists =
		function( $cbs, $filePath, $filePathRoot = null )
		{
			return( @file_exists( $filePath ) );
		};

	$cbs -> ReadLocalFile =
		function( $cbs, $filePath, $filePathRoot = null )
		{
			if( !$filePath )
				return( null );

			$cont = Gen::FileGetContents( $filePath );
			if( $cont === false && $filePathRoot && !Gen::DoesFileDirExist( $filePath, $filePathRoot ) )
				$cont = null;
			return( $cont );
		};

	$cbs -> WriteLocalFile =
		function( $cbs, $filePath, $data, $fileTime = null, $delIfFail = false )
		{
			$lock = new Lock( 'il', $cbs -> ctxProcess[ 'siteCacheRootDir' ] );
			return( Gen::FileWriteTmpAndReplace( $lock, $filePath, $data, $fileTime, $delIfFail ) );
		};

	$cbs -> GetLocalFileSize =
		function( $cbs, $filePath )
		{
			return( Gen::FileSize( $filePath ) );
		};

	$cbs -> GetLocalFileMTime =
		function( $cbs, $filePath )
		{
			return( Gen::FileMTime( $filePath ) );
		};

	$cbs -> DeleteLocalFile =
		function( $cbs, $filePath )
		{
			return( Gen::Unlink( $filePath ) );
		};

	$cbs -> asuxsadkxsshi =
		function( $cbs, $dataPath, $type, $oiCfn )
		{
			return( CacheCcEx( $dataPath, $type, $oiCfn ) );
		};

	$cbs -> ScRd =
		function( $cbs, $dataPath, $settCache, $type, $oiCi, $oiCfn )
		{
			return( CacheCrEx( $dataPath, $settCache, $type, $oiCi, $oiCfn ) );
		};

	$cbs -> ScWr =
		function( $cbs, $settCache, $dataPath, $composite, $content, $type, $oiCfn )
		{
			return( CacheCwEx( $settCache, $dataPath, $composite, $content, $type, $oiCfn ) );
		};

	$cbs -> Tof_GetFileDataEx =
		function( $cbs, $dir, $id )
		{
			return( Tof_GetFileDataEx( $dir, $id ) );
		};

	$cbs -> Tof_SetFileDataEx =
		function( $cbs, $dir, $id, $data, $overwrite = true )
		{
			return( Tof_SetFileDataEx( $dir, $id, $data, $overwrite ) );
		};

	$cbs -> Learn_ReadDsc =
		function( $cbs, $lrnFile )
		{
			return( Learn_ReadDsc( $lrnFile ) );
		};

	$cbs -> Learn_Clear =
		function( $cbs, $lrnFile, $bMain = true, $bPending = true )
		{
			Learn_Clear( $lrnFile, $bMain, $bPending );
		};

	$cbs -> Learn_IsStarted =
		function( $cbs, $ctxProcess )
		{
			return( Learn_IsStarted( $ctxProcess ) );
		};

	$cbs -> Learn_Start =
		function( $cbs, $ctxProcess )
		{
			return( Learn_Start( $ctxProcess ) );
		};

	$cbs -> Learn_Finish =
		function( $cbs, $ctxProcess )
		{
			return( Learn_Finish( $ctxProcess ) );
		};

	$cbs -> ExtContents_CacheGet =
		function( $cbs, $extCacheId )
		{
			return( ExtContents_Local_CacheGet( Gen::GetFileDir( $cbs -> ctxProcess[ 'dataPath' ] ), $extCacheId ) );
		};

	$cbs -> ExtContents_CacheSet =
		function( $cbs, $extCacheId, $fileType, $contCacheTtl, $contId, $contCache )
		{
			ExtContents_Local_CacheSet( Gen::GetFileDir( $cbs -> ctxProcess[ 'dataPath' ] ), $extCacheId, $fileType, $contCacheTtl, $contId, $contCache );
		};

	$cbs -> CustomMethod =
		function( $cbs, $name, $args )
		{
			return( ContentProcess_CallCustomMethod( $name, $args ) );
		};

	$cbs -> ImagesProcessSrcSizeAlternatives_CacheGet =
		function( $cbs, $imgStgId )
		{
			return( Images_ProcessSrcSizeAlternatives_Cache_Get( $cbs -> ctxProcess[ 'dataPath' ], $imgStgId ) );
		};

	$cbs -> ImagesProcessSrcSizeAlternatives_CacheSet =
		function( $cbs, $imgStgId, $v )
		{
			return( Images_ProcessSrcSizeAlternatives_Cache_Set( $cbs -> ctxProcess[ 'dataPath' ], $imgStgId, $v ) );
		};

	$cbs -> PostPrepareObj =
		function( $cbs, $type, $addr, $priority, $data = array(), $priorityInitiator = null, $time = null )
		{
			return( CachePostPrepareObjEx( $type, $addr, $cbs -> ctxProcess[ 'siteId' ], $priority, $data, $priorityInitiator, $time ) );
		};

	$ctxProcess[ 'cbs' ] = $cbs;
}

function _JsClk_XpathExtFunc_ifExistsThenCssSel( $v, $cssSel )
{
	if( !is_array( $v ) || count( $v ) < 1 )
		return( false );
	return( new JsClk_ifExistsThenCssSel( $cssSel ) );
}

