<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

function GetCodeViewHtmlBlock( $cont )
{
	return( Ui::Tag( 'div', str_replace( array( "\r", "\n", "\t", " " ), array( '', '<br>', '&#9;', '&nbsp;' ), htmlspecialchars( $cont ) ), array( 'class' => array( 'seraph_accel_textarea' ), 'style' => array( 'overflow' => 'scroll', 'height' => '5em', 'min-height' => '3em', 'max-height' => '20em', 'resize' => 'vertical' ) ) ) );
}

function SelfDiag_DetectStateAnd3rdPartySettConflicts( $cb, $ext = false )
{
	global $seraph_accel_g_phpCfgFileChangedInCurrentSession;

	$sett = Plugin::SettGet();
	$settGlob = Plugin::SettGetGlobal();
	$rmtCfg = PluginRmtCfg::Get();

	$contRemindRefreshCache = vsprintf( Wp::safe_html_x( 'RemindRefreshCache_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Ui::Link( array( '', '' ), menu_page_url( 'seraph_accel_manage', false ) . '#operate' ) );

	$isCacheEnabled = Gen::GetArrField( $sett, 'cache/enable', false, '/' );
	$isContProcEnabled = Gen::GetArrField( $sett, 'contPr/enable', false, '/' );
	$isScriptsDelayLoadEnabled = $isContProcEnabled && Gen::GetArrField( $sett, 'contPr/js/optLoad', false, '/' ) && Gen::GetArrField( $sett, 'contPr/js/nonCrit/timeout/enable', false, '/' ) && Gen::GetArrField( $sett, 'contPr/js/nonCrit/timeout/v', 0, '/' );
	$isExtCacheAllowed = Gen::GetArrField( $sett, array( 'cache', 'srv' ), false ) || Gen::GetArrField( $sett, array( 'cache', 'srvClr' ), false );

	if( $isCacheEnabled )
	{
		{
			$dir = GetCacheDir();
			if( ( !@is_dir( $dir ) && Gen::HrFail( Gen::MakeDir( $dir, true ) ) ) || !@is_writable( $dir ) )
				call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'CacheDirNotWrittable_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $dir ) ) );
		}

		if( !$seraph_accel_g_phpCfgFileChangedInCurrentSession )
		{
			if( IsWpCacheActive() )
			{
				$verifyEnvDropin = new AnyObj(); $verifyEnvDropin -> file = null;
				if( !CacheVerifyEnvDropin( $verifyEnvDropin -> file, $sett, $verifyEnvDropin ) && !isset( $sett[ PluginOptions::VERPREV ] ) && ( ($_SERVER[ 'REQUEST_METHOD' ]??null) == 'GET' ) )
				{
					if( !@file_exists( $verifyEnvDropin -> file ) && !@is_writable( dirname( $verifyEnvDropin -> file ) ) )
						call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDirNotWrittable_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( dirname( $verifyEnvDropin -> file ) ), Gen::GetFileName( $verifyEnvDropin -> file ) ) ) );
					else if( !@is_writable( $verifyEnvDropin -> file ) )
						call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotWrittable_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( dirname( $verifyEnvDropin -> file ) ), Gen::GetFileName( $verifyEnvDropin -> file ) ) ) );

					call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotMatch_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( dirname( $verifyEnvDropin -> file ) ), Gen::GetFileName( $verifyEnvDropin -> file ) ) . ( $ext ? '' : sprintf( Wp::safe_html_x( 'ContentDropinNotMatchEx_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), GetCodeViewHtmlBlock( $verifyEnvDropin -> needed ), GetCodeViewHtmlBlock( $verifyEnvDropin -> actual ) ) ) ) );
				}
				else if( !Gen::DoesFuncExist( 'seraph_accel_siteSettInlineDetach' ) )
					call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotLoaded_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( dirname( $verifyEnvDropin -> file ) ), Gen::GetFileName( $verifyEnvDropin -> file ) ) ) );
			}
			else
			{
				$cfgFile = Wp::GetConfigFilePath();
				if( !@is_writable( $cfgFile ) )
					call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ConfigFileNotWrittable_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $cfgFile ) ) );
				else
					call_user_func_array( $cb, array( Ui::MsgErr, Wp::safe_html_x( 'WpCacheNotActive', 'admin.Notice', 'seraphinite-accelerator' ) ) );
			}
		}

		if( !GetSalt() )
			call_user_func_array( $cb, array( Ui::MsgErr, Wp::safe_html_x( 'WpSaltNotDefined', 'admin.Notice', 'seraphinite-accelerator' ) ) );

		if( ( in_array( 'br', Gen::GetArrField( $sett, 'cache/encs', array(), '/' ) ) || in_array( 'brotli', Gen::GetArrField( $sett, 'cache/dataCompr', array(), '/' ) ) ) && @version_compare( @phpversion( 'brotli' ), '0.1.0' ) === -1 )
			call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'PhpBrotliNotActive_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), 'BROTLI', '0.1.0' ) ) );

		$aLocksInfo = array( array( 'pl', GetCacheDir() ), array( 'dl', GetCacheDir() ) );
		foreach( GetSiteIds() as $siteId )
			$aLocksInfo[] = array( 'l', GetCacheDir() . '/q/' . $siteId );
		foreach( $aLocksInfo as $d )
		{
			$lock = new Lock( $d[ 0 ], $d[ 1 ] );
			if( $lock -> Acquire() )
			{
				$lock -> Release();
				unset( $lock );
				continue;
			}

			call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'TmpFileNotWrittable_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $lock -> GetFileName() ) ) );
			unset( $lock );
			break;
		}

	}

	if( !$seraph_accel_g_phpCfgFileChangedInCurrentSession && Gen::GetArrField( $settGlob, array( 'cacheObj', 'enable' ), false ) )
	{
		$verifyEnvDropin = new AnyObj();
		if( !isset( $sett[ PluginOptions::VERPREV ] ) && ( ($_SERVER[ 'REQUEST_METHOD' ]??null) == 'GET' ) && !CacheVerifyEnvObjDropin( $settGlob, $verifyEnvDropin ) )
		{
			if( !@file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && !@is_writable( WP_CONTENT_DIR ) )
				call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDirNotWrittable_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( WP_CONTENT_DIR ), 'object-cache.php' ) ) );
			else if( !@is_writable( WP_CONTENT_DIR . '/object-cache.php' ) )
				call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotWrittable_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( WP_CONTENT_DIR ), 'object-cache.php' ) ) );
			else
				call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotMatch_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( WP_CONTENT_DIR ), 'object-cache.php' ) . ( $ext ? '' : sprintf( Wp::safe_html_x( 'ContentDropinNotMatchEx_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), GetCodeViewHtmlBlock( $verifyEnvDropin -> needed ), GetCodeViewHtmlBlock( $verifyEnvDropin -> actual ) ) ) ) );
		}
		else
		{
			global $seraph_accel_settObjCache;
			if( $seraph_accel_settObjCache === null )
				call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotLoaded_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileName( WP_CONTENT_DIR ), 'object-cache.php' ) ) );
		}
	}

	$verifyEnvDropin = new AnyObj();
	if( !isset( $sett[ PluginOptions::VERPREV ] ) && ( ($_SERVER[ 'REQUEST_METHOD' ]??null) == 'GET' ) && !CacheVerifyEnvNginxConf( $settGlob, $sett, $verifyEnvDropin ) )
	{

		call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'ContentDropinNotMatch_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Gen::GetFileDir( CacheGetEnvNginxConfFile() ), Gen::GetFileName( CacheGetEnvNginxConfFile() ) ) . ( $ext ? '' : sprintf( Wp::safe_html_x( 'ContentDropinNotMatchEx_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), GetCodeViewHtmlBlock( $verifyEnvDropin -> needed ), GetCodeViewHtmlBlock( $verifyEnvDropin -> actual ) ) ) ) );
	}

	if( !Gen::DoesFuncExist( 'fsockopen' ) && !Gen::DoesFuncExist( 'curl_exec' ) )
		call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpExtNotActive_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), 'CURL' ) ) );

	if( $isContProcEnabled )
	{
		if( ( Gen::GetArrField( $sett, 'contPr/normalize', 0, '/' ) & 524288 ) && !Gen::DoesFuncExist( 'tidy_parse_string' ) )
			call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpTidyNotActive_%1$s%2$s%3$s', 'admin.Notice', 'seraphinite-accelerator' ), 'TIDY', esc_html_x( 'TidyChk', 'admin.Settings_Html_Fix', 'seraphinite-accelerator' ), Ui::Link( esc_html_x( 'Title', 'admin.Settings_Html', 'seraphinite-accelerator' ), menu_page_url( 'seraph_accel_settings', false ) . '#html' ) ) ) );

		if( !Gen::DoesFuncExist( 'iconv' ) )
			call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpExtNotActive_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), 'ICONV' ) ) );

		if( !Gen::DoesFuncExist( 'mb_detect_encoding' ) || !Gen::DoesFuncExist( 'mb_convert_encoding' ) )
			call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpExtNotActive_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), 'MBSTRING' ) ) );

		if( !Gen::DoesFuncExist( '\\DOMElement::getAttribute' ) )
			call_user_func_array( $cb, array( Ui::MsgErr, sprintf( Wp::safe_html_x( 'PhpExtNotActive_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), 'LIBXML' ) ) );

		if( !Gen::DoesFuncExist( 'imagecreatefromstring' ) )
		{
			foreach( array( 'webp','avif' ) as $comprType )
				if( Gen::GetArrField( $sett, array( 'contPr', 'img', $comprType, 'enable' ), false, '/' ) )
					call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpGdNotActive_%1$s%2$s%3$s', 'admin.Notice', 'seraphinite-accelerator' ), 'GD',
						sprintf( Plugin::GetPluginString( 'SubjectTitle_%1$s%2$s' ), esc_html_x( 'Lbl', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), Gen::GetArrField( array( 'avif' => sprintf( esc_html_x( 'AvifChk_%1$s%2$s', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), '', '' ), 'webp' => sprintf( esc_html_x( 'WebpChk_%1$s', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), '' ) ), $comprType, '' ) ),
						Ui::Link( esc_html_x( 'Title', 'admin.Settings_Images', 'seraphinite-accelerator' ), menu_page_url( 'seraph_accel_settings', false ) . '#images' ) ) ) );

			if( Gen::GetArrField( $sett, 'contPr/img/szAdaptImg', false, '/' ) || Gen::GetArrField( $sett, 'contPr/img/szAdaptBg', false, '/' ) )
				call_user_func_array( $cb, array( Ui::MsgWarn, sprintf( Wp::safe_html_x( 'PhpGdNotActive_%1$s%2$s%3$s', 'admin.Notice', 'seraphinite-accelerator' ), 'GD',
					sprintf( Plugin::GetPluginString( 'SubjectTitle_%1$s%2$s' ), esc_html_x( 'Title', 'admin.Settings_Images', 'seraphinite-accelerator' ), esc_html_x( 'Lbl', 'admin.Settings_Images_Adapt', 'seraphinite-accelerator' ) ),
					Ui::Link( esc_html_x( 'Title', 'admin.Settings_Images', 'seraphinite-accelerator' ), menu_page_url( 'seraph_accel_settings', false ) . '#images' ) ) ) );
		}
	}

	$themeCh = wp_get_theme();
	for( $theme = $themeCh; $theme && $theme -> parent();  )
		$theme = $theme -> parent();

	if( $theme )
	{
		switch( $theme -> template )
		{
			case 'woostroid2':

				break;
			case 'woodmart':
				$themeOpts = get_option( 'xts-woodmart-options' );

				break;

			case 'dt-the7':
				$themeOpts = get_option( $themeCh -> stylesheet == 'dt-the7-child' ? 'the7dtchild' : 'the7' );

				break;

			case 'themify-ultra':
				$themeOpts = get_option( 'themify_data' );

				break;

			case 'thegem':
				$themeOpts = get_option( 'thegem_theme_options' );

				break;

			case 'xstore':

				break;

			case 'superio':
				$themeOpts = get_option( 'superio_theme_options' );

				break;
		}
	}

	$availablePlugins = Plugin::GetAvailablePluginsEx();

	$plg = ($availablePlugins[ 'wp-smushit' ]??null);
	if( !$plg || !($plg[ 'IsActive' ]??null) )
		$plg = ($availablePlugins[ 'wp-smush-pro' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = get_option( 'wp-smush-settings' );

	}

	$plg = ($availablePlugins[ 'elementor' ]??null);
	if( !$plg || !($plg[ 'IsActive' ]??null) )
		$plg = ($availablePlugins[ 'elementor-pro' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'ewww-image-optimizer' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'webp-express' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'rocket-lazy-load' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'a3-lazy-load' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = get_option( 'a3_lazy_load_global_settings' );

	}

	$plg = ($availablePlugins[ 'optimole-wp' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = wp_parse_args( get_option( 'optml_settings' ) );

	}

	$plg = ($availablePlugins[ 'shortpixel-adaptive-images' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'async-javascript' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'revslider' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = get_option( 'revslider-global-settings' );
		if( !is_array( $plgOpts ) )
			$plgOpts = @json_decode( $plgOpts, true );

	}

	$plg = ($availablePlugins[ 'wp-optimize' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

		$plgOpts = get_option( 'wpo_cache_config' );
		if( $isCacheEnabled && Gen::GetArrField( $plgOpts, 'enable_page_caching' ) )
		    call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Enable page caching', null, 'wp-optimize' ) ) ) );

	}

	$plg = ($availablePlugins[ 'wp-rocket' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );

	}

	$plg = ($availablePlugins[ 'w3-total-cache' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );

	}

	$plg = ($availablePlugins[ 'nitropack' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'wp-fastest-cache' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );

	}

	$plg = ($availablePlugins[ 'hummingbird-performance' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );

	}

	$plg = ($availablePlugins[ 'wp-hummingbird' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );

	}

	$plg = ($availablePlugins[ 'jetpack' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = Gen::GetArrField( get_option( 'jetpack_active_modules' ), array( '' ), array() );
		if( $isCacheEnabled && in_array( 'photon', $plgOpts ) )
			call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Enable site accelerator', null, 'jetpack' ) ) ) );

	}

	$plg = ($availablePlugins[ 'jetpack-boost' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'autoptimize' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

	}

	$plg = ($availablePlugins[ 'litespeed-cache' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

		if( $isCacheEnabled && get_option( 'litespeed.conf.cache' ) )
			call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_ConflictSoft_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Enable Cache', null, 'litespeed-cache' ) ) ) );
	}

	$plg = ($availablePlugins[ 'sg-cachepress' ]??null);
	if( $plg )
	{
		if( ($plg[ 'IsActive' ]??null) )
		{
			if( $isCacheEnabled && get_option( 'siteground_optimizer_enable_cache' ) && !$isExtCacheAllowed )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_ConflictSoft_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Dynamic Caching', null, 'sg-cachepress' ) ) ) );

		}
		else
		{
			if( $isCacheEnabled )
				call_user_func_array( $cb, array( Gen::SevWarn, sprintf( Wp::safe_html_x( '3rdMdl_ConflictOff_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
		}
	}

	$plg = ($availablePlugins[ 'fast-velocity-minify' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		if( $isCacheEnabled )
			call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'breeze' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'wp-meteor' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'wp-cloudflare-page-cache' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'wp-super-cache' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'a2-optimized-wp' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'wp-asset-clean-up' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'perfmatters' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		$plgOpts = get_option( 'perfmatters_options' );

	}

	$plg = ($availablePlugins[ 'flying-pages' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'flying-scripts' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'debloat' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{
		call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdMdl_Conflict_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ] ) ) );
	}

	$plg = ($availablePlugins[ 'clearfy' ]??null);
	if( $plg && ($plg[ 'IsActive' ]??null) )
	{

		$deactive_preinstall_components = Gen::GetArrField( get_option( 'wbcr_clearfy_deactive_preinstall_components' ), array( '' ), array() );

		if( $isCacheEnabled && get_option( 'wbcr_clearfy_enable_cache' ) && !in_array( 'cache', $deactive_preinstall_components ) )
			call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Enable cache', null, 'clearfy' ) ) ) );

		if( $isContProcEnabled && !in_array( 'minify_and_combine', $deactive_preinstall_components ) )
		{
			if( get_option( 'wbcr_clearfy_css_optimize' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Optimize CSS Code?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_css_aggregate' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Aggregate CSS-files?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_css_include_inline' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Also aggregate inline CSS?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_css_defer' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Inline and Defer CSS?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_css_inline' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Inline all CSS?', null, 'minify-and-combine' ) ) ) );

			if( get_option( 'wbcr_clearfy_js_optimize' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Optimize JavaScript Code?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_js_aggregate' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Aggregate JS-files?', null, 'minify-and-combine' ) ) ) );
			if( get_option( 'wbcr_clearfy_js_include_inline' ) )
				call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdSett_Conflict_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), $plg[ 'Name' ], Wp::GetLocString( 'Also aggregate inline JS?', null, 'minify-and-combine' ) ) ) );
		}
	}

	if( !$ext )
		return;

	if( $isContProcEnabled )
	{
		$requestRes = Wp::RemoteGet( Wp::GetSiteRootUrl(), array( 'timeout' => 30, 'sslverify' => false ) );
		if( Net::GetHrFromWpRemoteGet( $requestRes ) === Gen::S_OK )
		{
			$data = wp_remote_retrieve_body( $requestRes );

			if( $isContProcEnabled )
			{
				if( preg_match( '@<script[^>]+type\\s*=\\s*text/ez-screx\\W@', $data ) )
					call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdExtSett_Conflict_%1$s%2$s', 'admin.SelfDiag', 'seraphinite-accelerator' ), 'Ezoic', 'Leap' ) ) );

				if( preg_match( '@<script[^>]+src\\s*=[\'"][^\'"]+cloudflare-static/rocket-loader\\.@', $data ) )
					call_user_func_array( $cb, array( Gen::SevErr, sprintf( Wp::safe_html_x( '3rdExtSett_Conflict_%1$s%2$s', 'admin.SelfDiag', 'seraphinite-accelerator' ), 'CloudFlare', 'Rocket Loader' ) ) );
			}

			if( preg_match( '@<script[^>]+src\\s*=[\'"][^\'"]+/challenge-platform/[^\'"]+/scripts/invisible\\.@', $data ) )
				call_user_func_array( $cb, array( Gen::SevInfo, sprintf( Wp::safe_html_x( '3rdExtSett_SpeedDecr_%1$s%2$s', 'admin.SelfDiag', 'seraphinite-accelerator' ), 'CloudFlare', 'Bot Fight Mode' ) ) );
		}
	}

	if( $isContProcEnabled )
	{

		$bRedirOwn = Gen::GetArrField( $sett, array( 'contPr', 'img', 'redirOwn' ), false );
		foreach( array( 'webp','avif' ) as $comprType )
		{
			if( !Gen::GetArrField( $sett, array( 'contPr', 'img', $comprType, 'enable' ), false ) )
				continue;

			$contMimeTypeTest = 'image/' . $comprType;

			$optionText = sprintf( Plugin::GetPluginString( 'SubjectTitle_%1$s%2$s' ), esc_html_x( 'Lbl', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), Gen::GetArrField( array( 'avif' => sprintf( esc_html_x( 'AvifChk_%1$s%2$s', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), '', '' ), 'webp' => sprintf( esc_html_x( 'WebpChk_%1$s', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ), '' ) ), $comprType, '' ) );

			if( $bRedirOwn )
				$testUrl = add_query_arg( array_merge( Image_MakeOwnRedirUrlArgs( ltrim( Net::Url2Uri( plugins_url( '/Images/Test.png', __FILE__ ), true ), '/' ) ), array( '_' => Gen::MicroTimeStamp() ) ), Wp::GetSiteWpRootUrl() );
			else
				$testUrl = add_query_arg( array( '_' => Gen::MicroTimeStamp() ), plugins_url( '/Images/Test.png', __FILE__ ) );

			$requestRes = Wp::RemoteGet( $testUrl, array( 'timeout' => 30, 'sslverify' => false, 'headers' => array( 'Accept' => $contMimeTypeTest . ',image/*,*/*;q=0.8' ) ) );
			if( Net::GetHrFromWpRemoteGet( $requestRes ) !== Gen::S_OK )
			{
				if( $bRedirOwn )
					call_user_func_array( $cb, array( Gen::SevWarn, _SelfDiag_GetResponseResString( $testUrl, $requestRes ) ) );
			}
			else
			{
				$contMimeType = ( string )Net::GetHeaderFromWpRemoteRequestRes( $requestRes, 'content-type' );

				if( strpos( $contMimeType, $contMimeTypeTest ) === false )
					call_user_func_array( $cb, array( Gen::SevWarn, vsprintf( Wp::safe_html_x( 'ImgConvRedir_NotActive_%1$s%2$s%3$s%4$s%5$s', 'admin.Notice', 'seraphinite-accelerator' ), array_merge( Ui::Link( array( '', '' ), Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Settings_Images_Compr' ), true, array(  ) ), array( $comprType, $optionText, esc_html_x( 'RedirOwnChk', 'admin.Settings_Images_Compr', 'seraphinite-accelerator' ) ) ) ) ) );
			}

			$hr = Img::ConvertDataEx( $dataCnv, @file_get_contents( __DIR__ . '/Images/Test.png' ), $contMimeTypeTest );
			if( Gen::HrFail( $hr ) )
				if( $hr == Gen::E_UNSUPPORTED )
					call_user_func_array( $cb, array( Gen::SevWarn, vsprintf( Wp::safe_html_x( 'ImgConv_NotSupp_%1$s%2$s%3$s%4$s', 'admin.Notice', 'seraphinite-accelerator' ), array_merge( Ui::Link( array( '', '' ), Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Settings_Images_Compr' ), true, array(  ) ), array( $comprType, $optionText ) ) ) ) );
				else
					call_user_func_array( $cb, array( Gen::SevWarn, vsprintf( Wp::safe_html_x( 'ImgConv_NotWork_%1$s%2$s%3$s%4$s%5$s', 'admin.Notice', 'seraphinite-accelerator' ), array_merge( Ui::Link( array( '', '' ), Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Settings_Images_Compr' ), true, array(  ) ), array( $comprType, $optionText, Gen::LastErrDsc_Is() ? MsgUnpackLocIds( Gen::LastErrDsc_Get() ) : sprintf( '0x%08X', $hr ) ) ) ) ) );
		}
	}

	if( UseGzAssets( Gen::GetArrField( $sett, array( 'cache' ), array() ) ) )
	{
		foreach( array( 'js', 'css' ) as $assetType )
		{
			$dataTest = @file_get_contents( __DIR__ . '/Images/Test.' . $assetType );

			$testUrl = add_query_arg( array( '_' => Gen::MicroTimeStamp() ), plugins_url( '/Images/Test.' . $assetType, __FILE__ ) );
			$requestRes = Wp::RemoteGet( $testUrl, array( 'timeout' => 30, 'sslverify' => false, 'headers' => array( 'Accept' => 'text/' . $assetType . ',*/*;q=0.1', 'Accept-Encoding' => 'gzip, deflate, br' ) ) );
			if( Net::GetHrFromWpRemoteGet( $requestRes ) !== Gen::S_OK )
			{

			}
			else
			{
				$data = wp_remote_retrieve_body( $requestRes );
				if( $data )
				{
					if( $data !== $dataTest )
						call_user_func_array( $cb, array( Gen::SevWarn, sprintf( Wp::safe_html_x( 'AssetCompr_Bad_%1$s', 'admin.Notice', 'seraphinite-accelerator' ), $assetType ) ) );
				}
			}
		}
	}
}

function _3rdParty_to_boolean( $value )
{
	if( in_array( $value, [ 'yes', 'enabled', 'true', '1', 'on' ], true ) )
		return( true );
	if( in_array( $value, [ 'no', 'disabled', 'false', '0', 'off' ], true ) )
		return( false );
	return( boolval( $value ) );
}

