<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

require( __DIR__ . '/htmlparser.php' );
require( __DIR__ . '/content_img.php' );
require( __DIR__ . '/content_js.php' );
require( __DIR__ . '/content_css.php' );
require( __DIR__ . '/content_frm.php' );

function GetContentProcessCtxEx( $serverArgs, $sett, $siteId, $siteUrl, $siteRootPath, $wpRootSubPath, $cacheDir, $scriptDebug )
{
	$ctx = array(
		'siteDomainUrl' => Net::GetSiteAddrFromUrl( $siteUrl, true ),
		'siteRootUri' => Gen::SetLastSlash( Net::Url2Uri( $siteUrl ), false ),
		'siteRootPath' => Gen::SetLastSlash( $siteRootPath, false ),
		'wpRootSubPath' => $wpRootSubPath . '/',
		'siteId' => $siteId,
		'dataPath' => GetCacheDataDir( $cacheDir . '/s/' . $siteId ),
		'deps' => array(),
		'subs' => array(),
		'subCurIdx' => 0,
		'debugM' => (isset($sett[ 'debug' ])?$sett[ 'debug' ]:null),
		'debug' => (isset($sett[ 'debugInfo' ])?$sett[ 'debugInfo' ]:null),
		'jsMinSuffix' => $scriptDebug ? '' : '.min',
		'userAgent' => strtolower( isset( $_SERVER[ 'SERAPH_ACCEL_ORIG_USER_AGENT' ] ) ? $_SERVER[ 'SERAPH_ACCEL_ORIG_USER_AGENT' ] : (isset($serverArgs[ 'HTTP_USER_AGENT' ])?$serverArgs[ 'HTTP_USER_AGENT' ]:null) ),
		'mode' => ( 1 | 2 | 4 ),
		'modeReq' => 0,
		'aAttrImg' => array(),

	);

	$ctx[ 'compatView' ] = ContProcIsCompatView( Gen::GetArrField( $sett, array( 'cache' ), array() ), $ctx[ 'userAgent' ] );

	CorrectRequestScheme( $serverArgs );

	$ctx[ 'serverArgs' ] = $serverArgs;
	$ctx[ 'requestUriPath' ] = Gen::GetFileDir( (isset($serverArgs[ 'REQUEST_URI' ])?$serverArgs[ 'REQUEST_URI' ]:null) );
	$ctx[ 'host' ] = Gen::GetArrField( Net::UrlParse( $serverArgs[ 'REQUEST_SCHEME' ] . '://' . GetRequestHost( $serverArgs ) ), array( 'host' ) );
	if( !$ctx[ 'host' ] )
		$ctx[ 'host' ] = (isset($serverArgs[ 'SERVER_NAME' ])?$serverArgs[ 'SERVER_NAME' ]:null);

	$settContPr = Gen::GetArrField( $sett, array( 'contPr' ), array() );
	if( Gen::GetArrField( $settContPr, array( 'normUrl' ), false ) )
		$ctx[ 'srcUrlFullness' ] = Gen::GetArrField( $settContPr, array( 'normUrlMode' ), 0 );
	else
		$ctx[ 'srcUrlFullness' ] = 0;

	$ctx[ 'aVPth' ] = array_map( function( $vPth ) { $vPth[ 'f' ] .= 'S'; return( $vPth ); }, Gen::GetArrField( $sett, array( 'cache', '_vPth' ), array() ) );

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

		$seraph_accel_g_ctxProcess = GetContentProcessCtxEx( $serverArgs, $sett, GetSiteId(), $siteRootUrl, $siteRootPath, $siteWpRootSubPath, GetCacheDir(), defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	}

	return( $seraph_accel_g_ctxProcess );
}

