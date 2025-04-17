<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

require_once( __DIR__ . '/common.php' );
require_once( __DIR__ . '/oper.php' );
require_once( __DIR__ . '/sql.php' );
require_once( __DIR__ . '/content.php' );
require_once( __DIR__ . '/options.php' );
require_once( __DIR__ . '/tune.php' );
require_once( __DIR__ . '/cache_ext.php' );

if( defined( 'SERAPH_ACCEL_ADVCACHE_COMP' ) )
	require_once( __DIR__ . '/cache.php' );

Plugin::Init();

function OnActivate()
{
	CacheInitEnv( Plugin::SettGetGlobal(), Plugin::SettGet(), true );
	CacheInitQueueTable( true );
}

function OnDeactivate()
{
	CacheInitEnv( null, null, false );
}

function OnChangeVer( $verPrev, $pkPrev )
{

}

function RunOpt( $op = 0, $push = true )
{
	Plugin::AsyncTaskPost( 'CacheRevalidateAll', array( 'op' => $op ), Plugin::ASYNCTASK_TTL_DEF, $push );
}

function _AddMenus( $accepted = false )
{
	add_menu_page( Plugin::GetPluginString( 'TitleLong' ), Plugin::GetNavMenuTitle(), 'manage_options', 'seraph_accel_manage',																		$accepted ? 'seraph_accel\\_ManagePage' : 'seraph_accel\\Plugin::OutputNotAcceptedPageContent', Plugin::FileUri( 'icon.png?v=2.27.20', __FILE__ ) );
	add_submenu_page( 'seraph_accel_manage', esc_html_x( 'Title', 'admin.Manage', 'seraphinite-accelerator' ), esc_html_x( 'Title', 'admin.Manage', 'seraphinite-accelerator' ), 'manage_options', 'seraph_accel_manage',	$accepted ? 'seraph_accel\\_ManagePage' : 'seraph_accel\\Plugin::OutputNotAcceptedPageContent' );
	add_submenu_page( 'seraph_accel_manage', Wp::GetLocString( 'Settings' ), Wp::GetLocString( 'Settings' ), 'manage_options', 'seraph_accel_settings',										$accepted ? 'seraph_accel\\_SettingsPage' : 'seraph_accel\\Plugin::OutputNotAcceptedPageContent' );
}

function OnInitAdminModeNotAccepted()
{
	add_action( Wp::IsMultisiteGlobalAdmin() ? 'network_admin_menu' : 'admin_menu',
		function()
		{
			_AddMenus();
		}
	);
}

function OnInitAdminMode()
{
	add_action( 'admin_init',
		function()
		{
			if( isset( $_REQUEST[ 'seraph_accel_saveSettings' ] ) )
			{
				unset( $_POST[ 'seraph_accel_saveSettings' ] );
				Plugin::ReloadWithPostOpRes( array( 'saveSettings' => wp_verify_nonce( ($_REQUEST[ '_wpnonce' ]??''), 'save' ) ? _OnSaveSettings( $_POST ) : Gen::E_CONTEXT_EXPIRED ) );
				exit;
			}

		}
	);

	add_action( 'seraph_accel_postOpsRes',
		function( $res )
		{
			if( ( $hr = ($res[ 'saveSettings' ]??null) ) !== null )
				echo( Plugin::Sett_SaveResultBannerMsg( $hr, Ui::MsgOptDismissible ) );
		}
	);

	add_action( Wp::IsMultisiteGlobalAdmin() ? 'network_admin_menu' : 'admin_menu',
		function()
		{
			_AddMenus( true );
		}
	);

	add_action( 'admin_notices', 'seraph_accel\\_OnAdminNotices' );

	$sett = Plugin::SettGet();

	add_action( 'added_option',			'seraph_accel\\_OnUpdateOption', 10 );
	add_action( 'updated_option',		'seraph_accel\\_OnUpdateOption', 10 );
	add_action( 'deleted_option',		'seraph_accel\\_OnUpdateOption', 10 );
}

function _OnAdminNotices()
{

	if( is_plugin_active( str_replace( 'seraphinite-accelerator', 'seraphinite-accelerator-ext', Plugin::BASENAME ) ) )
	{
		deactivate_plugins( array( Plugin::GetCurBaseName() ) );
		return;
	}

	$siteId = GetSiteId();
	$tmCur = Gen::GetCurRequestTime();
	$sett = Plugin::SettGet();

	$isCacheEnabled = Gen::GetArrField( $sett, 'cache/enable', false, '/' );
	if( $isCacheEnabled )
	{
		if( Gen::GetArrField( Plugin::StateGet(), array( 'settChangedUpdateCache' ), false ) )
		{
			Plugin::_admin_printscriptsstyles();
			echo( wp_kses( Ui::BannerMsg( Ui::MsgWarn,
				Ui::Tag( 'strong', Plugin::GetPluginString( 'TitleFull' ) ) .
				Ui::Tag( 'p', vsprintf( Wp::safe_html_x( 'SettChangedUpdateCache_%1$s%2$s', 'admin.Notice', 'seraphinite-accelerator' ), Ui::Link( array( '', '' ), menu_page_url( 'seraph_accel_manage', false ) . '#operate' ) ) ) .
				Ui::TagOpen( 'input', array( 'type' => 'button', 'class' => 'button button-primary ctlSpaceAfter ctlVaMiddle', 'value' => esc_html( Wp::GetLocString( 'Dismiss' ) ), 'onclick' => 'var ctlMsg=jQuery(this).closest(".notice");jQuery(this).attr("disabled","");ctlMsg.find(".seraph_accel_spinner").show();jQuery.ajax({url:"' . Plugin::GetAdminApiUri( 'StateSet', array( 'settChangedUpdateCache' => '' ) ) . '",type:"POST",data:""}).always(function(res){seraph_accel.Ui.BannerMsgClose(ctlMsg);});return false;' ) ) .
				Ui::Spinner( false, array( 'class' => 'ctlVaMiddle', 'style' => array( 'display' => 'none' ) ) )
			, 0 ), Wp::GetKsesSanitizeCtx( 'admin' ) ) );
		}
	}

	SelfDiag_DetectStateAnd3rdPartySettConflicts(
		function( $sev, $text )
		{
			Plugin::_admin_printscriptsstyles();
			echo( Ui::BannerMsg( $sev, Ui::Tag( 'strong', Plugin::GetPluginString( 'TitleFull' ) ) . Ui::Tag( 'p', $text ) ) );
		}
	);
}

function _InitCatchDataUpdate( $level )
{
	$sett = Plugin::SettGet();

	if( Gen::GetArrField( $sett, array( 'cache', 'updPost' ), false ) )
	{
		if( _CheckUpdatePost_Rtn::$level < 1 && $level >= 1 )
		{
			add_action( 'transition_post_status', 'seraph_accel\\_OnPostStatusUpdate', 99999, 3 );
		}

		if( _CheckUpdatePost_Rtn::$level < 2 && $level >= 2 )
		{
			add_action( 'add_term_relationship', 'seraph_accel\\_OnPostTermsBeforeAdd', 99999, 3 );
			add_action( 'delete_term_relationships', 'seraph_accel\\_OnPostTermsBeforeDelete', 99999, 3 );
			add_action( 'deleted_term_relationships', 'seraph_accel\\_OnPostTermsAfterDelete', 99999, 3 );
			add_action( 'set_object_terms', 'seraph_accel\\_OnPostTermsAfterUpdate', 99999, 6 );

			add_action( 'edit_post',						'seraph_accel\\_OnPostUpdated', 0 );

			add_action( 'pmxi_saved_post', 'seraph_accel\\_OnPostUpdated', 0 );
			add_action( 'pre_post_update', 'seraph_accel\\_OnPostUpdated', 99999 );
			add_action( 'post_updated', 'seraph_accel\\_OnPostUpdatedEx', 99999, 3 );
			add_action( 'before_delete_post', 'seraph_accel\\_OnPostDeleting', 0 );
			add_action( 'wp_update_comment_count', 'seraph_accel\\_OnCommentUpdateCount', 99999, 3 );
			add_filter( 'wp_update_comment_data', 'seraph_accel\\_OnCommentBeforeUpdate', 99999, 2 );

		}

		if( _CheckUpdatePost_Rtn::$level < 3 && $level >= 3 )
		{
			add_action( 'added_post_meta', function( $object_id, $meta_key, $_meta_value ) { _OnPostMetaUpdated( $object_id, $meta_key, $_meta_value ); }, 99999, 3 );
			add_action( 'updated_post_meta', function( $meta_id, $object_id, $meta_key, $_meta_value ) { _OnPostMetaUpdated( $object_id, $meta_key, $_meta_value ); }, 99999, 4 );
			add_action( 'deleted_post_meta', function( $meta_ids, $object_id, $meta_key, $_meta_value ) { _OnPostMetaUpdated( $object_id, $meta_key, $_meta_value ); }, 99999, 4 );

			add_filter( 'pre_update_option_permalink-manager-uris', function( $value, $old_value, $option ) { _OnOptionUpdated_PermalinkManagerUris( $option, $value, $old_value ); return( $value ); }, 99999, 3 );
		}
	}

	$updGlob = false;

	_CheckUpdatePost_Rtn::Init( $level, $updGlob );
}

function OnInit( $isAdminMode )
{
	$sett = Plugin::SettGet();

	global $seraph_accel_g_cacheCtxSkip;
	global $seraph_accel_g_simpCacheMode;
	global $seraph_accel_g_prepCont;

	$settContPr = Gen::GetArrField( $sett, array( 'contPr' ), array() );
	$cacheEnable = Gen::GetArrField( $sett, array( 'cache', 'enable' ), false );

	CacheInitQueueTable();

	Gen::SetTempDirFunc( 'seraph_accel\\Wp::GetTempDir' );

	if( Gen::GetArrField( $sett, 'cache', 'viewsGeo', 'enable' ) )
	{
		add_action( 'init',
			function()
			{
				$svc = ExtDb_GetWooMaxMindGeolocationSvc();
				$dbIP2CFile = $svc ? $svc -> get_database_service() -> get_database_path() : null;

				if( $dbIP2CFile )
				{
					add_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_Start', -9999 );
					Plugin::AsyncTaskDel( 'ExtDbUpdSche' );
				}
				else
					Plugin::AsyncTaskPost( 'ExtDbUpdSche', null, array( time() + 7 * 24 * 60 * 60, 24 * 60 * 60 ), false, function( $args, $argsPrev ) { return( false ); } );
			}
		);
	}
	else
		Plugin::AsyncTaskDel( 'ExtDbUpdSche' );

	if( $cacheEnable && Gen::GetArrField( $sett, array( 'cache', 'useTimeoutClnForWpNonce' ), false ) )
	{
		add_action( 'init',
			function()
			{
				if( is_user_logged_in() )
					return;

				$settCache = Gen::GetArrField( Plugin::SettGet(), array( 'cache' ), array() );

				$ctx = new AnyObj();
				$ctx -> nonceTtlNeeded = Gen::GetArrField( $settCache, array( 'timeoutCln' ), 0 ) * 60 * 2;
				if( !$ctx -> nonceTtlNeeded )
					return;

				$ctx -> cb =
					function( $ctx, $nonceTtl )
					{
						return( $nonceTtl < $ctx -> nonceTtlNeeded ? $ctx -> nonceTtlNeeded : $nonceTtl );
					};

				add_filter( 'nonce_life', array( $ctx, 'cb' ), 99999 );
			}
		, -99999 );
	}

	add_action( $isAdminMode ? 'admin_init' : 'init',
		function()
		{
			if( is_admin() )
				Plugin::SettCacheClear();
			$sett = Plugin::SettGet();
			$settGlob = Plugin::SettGetGlobal();

			if( isset( $sett[ PluginOptions::VERPREV ] ) )
			{
				if( $sett[ PluginOptions::VERPREV ] === 0 )
					Plugin::StateUpdateFlds( array( 'settWiz' => true ) );

				unset( $sett[ PluginOptions::VERPREV ] );
				Plugin::SettSet( $sett );

				CacheInitEnv( $settGlob, $sett );

			}
			else
			{
				if( Gen::GetArrField( $sett, array( 'cache', 'enable' ), false ) && Gen::GetArrField( $settGlob, array( 'cache', 'forceAdvCache' ), false ) )
					CacheInitEnvDropin( $sett );
				if( Gen::GetArrField( $settGlob, array( 'cacheObj', 'enable' ), false ) && Gen::GetArrField( $settGlob, array( 'cacheObj', 'forceDropin' ), false ) )
					CacheInitEnvObjDropin( $settGlob );
			}
		}
	);

	if( $cacheEnable )
	{
		CacheInitQueueProcessor();

		CacheInitClearProcessor();
		CacheInitOperScheduler();

		if( !$seraph_accel_g_cacheCtxSkip && Gen::GetArrField( $sett, array( 'cache', 'ctx' ), false ) )
		{

			add_action( 'set_logged_in_cookie',
				function( $logged_in_cookie, $expire, $expiration, $user_id, $action, $token )
				{
					UpdateClientSessId( $user_id, $token, $expire );
				},
			10, 6 );

			add_action( 'clear_auth_cookie',
				function( $userId )
				{
					UpdateClientSessId( 0 );
				}
			);
		}
	}

	$isGet = ($_SERVER[ 'REQUEST_METHOD' ]??null) === 'GET';

	$updPostMetaAlways = Gen::GetArrField( $sett, array( 'cache', 'updPostMeta' ), false );
	if( $updPostMetaAlways && !in_array( ($_REQUEST[ 'action' ]??null), array( 'heartbeat', 'wp-remove-post-lock' ) ) )
		_InitCatchDataUpdate( 3 );

	if( $isAdminMode )
	{
		if( ($_REQUEST[ 'page' ]??null) === 'pmxi-admin-import' && ($_REQUEST[ 'action' ]??null) === 'process' )
			_InitCatchDataUpdate( 3 );
		if( !$updPostMetaAlways )
			_InitCatchDataUpdate( !$isGet && _IsRequestAjax() ? 3 : 2 );

		return;
	}

	if( !$updPostMetaAlways )
	{
		if( isset( $_REQUEST[ 'import_key' ] ) )
			_InitCatchDataUpdate( 3 );
		else if( $isGet )
			_InitCatchDataUpdate( Wp::IsInRunningCron() ? 2 : 1 );

		add_filter( 'itglx_wc1c_ignore_catalog_file_processing', function( $ignoreProcessing ) { _InitCatchDataUpdate( 3 ); return( $ignoreProcessing ); } );
	}

	if( $isGet || ( is_string( $seraph_accel_g_simpCacheMode ) && Gen::StrStartsWith( $seraph_accel_g_simpCacheMode, 'data:' ) ) )
	{
		{
			$settTest = Gen::GetArrField( $sett, array( 'test' ), array() );
			if( ( ($settTest[ 'contDelay' ]??null) || ($settTest[ 'contExtra' ]??null) ) )
				add_action( 'wp_loaded', function() { ob_start( 'seraph_accel\\_OnContentTest' ); } );
		}

	}

	if( !$isGet && !$updPostMetaAlways )
	{
		add_action( 'rest_api_init', function( $wp_rest_server ) { _InitCatchDataUpdate( 3 ); }, 0, 1 );
		add_action( 'init', function() { _InitCatchDataUpdate( _IsRequestAjax() ? 3 : 2 ); } );
	}

	if( $cacheEnable && !$seraph_accel_g_cacheCtxSkip && Gen::GetArrField( $sett, array( 'cache', 'ctx' ), false )  )
	{
		add_action( 'init',
			function()
			{
				$curUserId = get_current_user_id();
				$token = null;
				$expirationNew = null;
				if( $curUserId )
				{
					if( $info = wp_parse_auth_cookie( '', 'logged_in' ) )
					{
						$token = ($info[ 'token' ]??null);
						$expirationNew = ($info[ 'expiration' ]??null);
					}

				}

				UpdateClientSessId( $curUserId, $token, $expirationNew );
			}
		);
	}

}

function _IsRequestAjax()
{
	return( defined( 'DOING_AJAX' ) && DOING_AJAX && ($_REQUEST[ 'action' ]??null) != 'heartbeat' && ($_REQUEST[ 'action' ]??null) != 'wp-remove-post-lock' );
}

function _OnUpdateOption( $option )
{
	if( $option != 'siteurl' )
		return;

	$sett = Plugin::SettGet();
	if( Gen::GetArrField( $sett, array( 'cache', 'enable' ), false ) )
		CacheInitEnvDropin( $sett );
}

function _OnOptionUpdated_PermalinkManagerUris( $option, $value, $valueOld )
{
	if( !is_array( $value ) || !is_array( $valueOld ) )
		return;

	global $seraph_accel_g_aDelUrls;

	global $permalink_manager_uris;

	$permalink_manager_uris_prev = $permalink_manager_uris;
	$permalink_manager_uris = array();

	foreach( $valueOld as $postId => $path )
	{
		$pathNew = ($value[ $postId ]??null);
		if( $path === $pathNew )
			continue;

		$permalink_manager_uris[ $postId ] = $path;
		if( $url = get_permalink( $postId ) )
		{
			$seraph_accel_g_aDelUrls[ $url ][ 'postId' ] = $postId;
			$seraph_accel_g_aDelUrls[ $url ][ 'permalinkManager_pathChanged' ] = array( 'old' => $path, 'new' => $pathNew );
		}
	}

	$permalink_manager_uris = $permalink_manager_uris_prev;
}

function _OnPostMetaUpdated( $postId, $metaKey, $metaValue )
{
	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_postUpdateSche;

	$sett = Plugin::SettGet();

	foreach( Gen::GetArrField( $sett, array( 'cache', 'updPostMetaExcl' ), array() ) as $exclPattern )
	{
		$tmSch = null;
		if( Gen::StrStartsWith( $exclPattern, 'sch:' ) )
		{
			$tmSch = is_string( $metaValue ) ? strtotime( $metaValue ) : false;
			$exclPattern = substr( $exclPattern, 4 );
		}
		else if( Gen::StrStartsWith( $exclPattern, 'schLoc:' ) )
		{
			$tmSch = is_string( $metaValue ) ? strtotime( $metaValue ) : false;
			if( $tmSch )
				$tmSch -= Wp::GetGmtOffset();
			$exclPattern = substr( $exclPattern, 7 );
		}

		if( @preg_match( $exclPattern, ( string )$metaKey ) )
		{
			if( $tmSch !== null )
				$seraph_accel_g_postUpdateSche[ $postId ][ 'meta' ][ $metaKey ] = $tmSch;
			return;
		}
	}

	$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = false;
	$seraph_accel_g_postUpdated[ $postId ][ 'r' ][ 'metas' ][ $metaKey ] = true;

}

function _OnCommentUpdateCount( $postId, $new, $old )
{
	global $seraph_accel_g_postUpdatedSync;
	$seraph_accel_g_postUpdatedSync[ $postId ][ 'v' ] = false;
	$seraph_accel_g_postUpdatedSync[ $postId ][ 'r' ][ 'comments' ] = $new;
}

function _OnCommentBeforeUpdate( $data, $dataOld )
{
	global $seraph_accel_g_postUpdatedSync;

	$postId = ($data[ 'comment_post_ID' ]??null);

	$postIdPrev = ($dataOld[ 'comment_post_ID' ]??null);
	if( $postIdPrev && $postId !== $postIdPrev )
	{
		$seraph_accel_g_postUpdatedSync[ $postIdPrev ][ 'v' ] = false;

	}

	if( ($data[ 'comment_approved' ]??null) == 1 )
	{
		$seraph_accel_g_postUpdatedSync[ $postId ][ 'v' ] = false;

	}

	return( $data );
}

function _OnPostStatusUpdate( $new_status, $old_status, $post )
{
	if( !$post || $new_status == $old_status )
		return;

	global $seraph_accel_g_postUpdated;

	$seraph_accel_g_postUpdated[ $post -> ID ][ 'v' ] = false;
	$seraph_accel_g_postUpdated[ $post -> ID ][ 'r' ][ 'status' ] = $new_status;

}

function _OnPostTermsBeforeAdd( $postId, $tt_id, $taxonomy )
{
	global $seraph_accel_g_aPostTermsUpdating;

	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_aDelUrls;

	if( isset( $seraph_accel_g_aPostTermsUpdating[ $postId ] ) )
		return;
	$seraph_accel_g_aPostTermsUpdating[ $postId ] = false;

	$post = get_post( $postId );
	if( !$post )
		return;

	if( !isset( $seraph_accel_g_postUpdated[ $postId ] ) && ( !is_post_type_viewable( $post -> post_type ) || !CacheOp_IsPostVisible( $post ) ) )
		return;

	if( $url = get_permalink( $post ) )
	{
		$seraph_accel_g_aPostTermsUpdating[ $postId ] = $url;
	}

	$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = true;
}

function _OnPostTermsBeforeDelete( $postId, $tt_ids, $taxonomy )
{
	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_aDelUrls;

	$post = get_post( $postId );
	if( !$post )
		return;

	if( !isset( $seraph_accel_g_postUpdated[ $postId ] ) && ( !is_post_type_viewable( $post -> post_type ) || !CacheOp_IsPostVisible( $post ) ) )
		return;

	if( $url = get_permalink( $post ) )
	{
		$seraph_accel_g_aDelUrls[ $url ][ 'postId' ] = $postId;
		$seraph_accel_g_aDelUrls[ $url ][ 'termsDel' ] = $taxonomy . ':' . implode( ',', $tt_ids );
	}
	$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = true;
}

function _OnPostTermsAfterDelete( $postId, $tt_ids, $taxonomy )
{
	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_aDelUrls;

	if( !isset( $seraph_accel_g_postUpdated[ $postId ] ) )
		return;

	$post = get_post( $postId );
	if( !$post )
		return;

	if( $url = get_permalink( $post ) )
		unset( $seraph_accel_g_aDelUrls[ $url ][ 'termsDel' ] );
}

function _OnPostTermsAfterUpdate( $postId, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids )
{
	global $seraph_accel_g_aPostTermsUpdating;

	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_aDelUrls;

	if( !isset( $seraph_accel_g_aPostTermsUpdating[ $postId ] ) )
		return;
	$urlOld = $seraph_accel_g_aPostTermsUpdating[ $postId ];
	unset( $seraph_accel_g_aPostTermsUpdating[ $postId ] );

	if( !isset( $seraph_accel_g_postUpdated[ $postId ] ) )
		return;

	unset( $seraph_accel_g_aDelUrls[ $urlOld ][ 'termsDel' ] );
	$seraph_accel_g_aDelUrls[ $urlOld ][ 'postId' ] = $postId;
	$seraph_accel_g_aDelUrls[ $urlOld ][ 'termsUpd' ] = $taxonomy . ':' . implode( ',', $tt_ids );

	$post = get_post( $postId );
	if( !$post )
		return;

	if( $url = get_permalink( $post ) )
		unset( $seraph_accel_g_aDelUrls[ $url ][ 'termsUpd' ] );
	$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = true;
	$seraph_accel_g_postUpdated[ $postId ][ 'r' ][ 'terms' ][ $taxonomy ] = $tt_ids;
}

function _OnPostUpdated( $postId )
{
	global $seraph_accel_g_postUpdated;

	$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = false;

}

function _OnPostUpdatedEx( $postId, $post, $postBefore )
{
	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_aDelUrls;

	if( !$post || !$postBefore || !is_post_type_viewable( $post -> post_type ) )
		return;

	if( CacheOp_IsPostVisible( $post ) )
	{
		$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = true;

		if( CacheOp_IsPostVisible( $postBefore ) )
		{
			$urlOld = get_permalink( $postBefore );
			$url = get_permalink( $post );
			if( $urlOld != $url )
			{
				$seraph_accel_g_aDelUrls[ $urlOld ][ 'postId' ] = $postId;
				$seraph_accel_g_aDelUrls[ $urlOld ][ 'postUpdated' ] = $post -> post_name != $postBefore -> post_name ? 'slug' : ( $post -> post_parent != $postBefore -> post_parent ? 'parent' : 'other' );
			}

			foreach( array_diff_assoc( ( array )$post, ( array )$postBefore ) as $k => $v )
				$seraph_accel_g_postUpdated[ $postId ][ 'r' ][ 'attributes' ][ $k ] = true;
		}
	}
	else if( CacheOp_IsPostVisible( $postBefore ) )
	{
		if( $url = get_permalink( $postBefore ) )
		{
			$seraph_accel_g_aDelUrls[ $url ][ 'postId' ] = $postId;
			$seraph_accel_g_aDelUrls[ $url ][ 'postUpdated' ] = 'hidden';
		}
		$seraph_accel_g_postUpdated[ $postId ][ 'v' ] = true;
	}
}

function _OnPostDeleting( $postId )
{
	$post = get_post( $postId );
	if( !$post || !is_post_type_viewable( $post -> post_type ) )
		return;

	if( CacheOp_IsPostVisible( $post ) )
		CacheOpPost( $postId, 'delete', 5 );
}

function _OnCheckUpdatePost_FinalizeArr( $ctx, &$a )
{
	if( !is_array( $a ) )
		return;

	if( !isset( $ctx -> sRequest ) )
	{
		$ctx -> sRequest = ($_SERVER[ 'REQUEST_URI' ]??'');
		$aArg = array_merge( Net::UrlExtractArgs( $ctx -> sRequest ), $_REQUEST );

		if( strpos( $ctx -> sRequest, '/wp-admin/admin-ajax.' ) !== false )
			$aArg = array_filter( $_REQUEST, function( $k ) { return( in_array( $k, array( 'action' ) ) ); }, ARRAY_FILTER_USE_KEY );
		else if( strpos( $ctx -> sRequest, '/wp-admin/post.' ) !== false )
			$aArg = array_filter( $_REQUEST, function( $k ) { return( in_array( $k, array( 'action' ) ) ); }, ARRAY_FILTER_USE_KEY );
		else
			$aArg = array();

		$ctx -> sRequest = Net::UrlAddArgs( ($_SERVER[ 'REQUEST_URI' ]??''), $aArg );
		$ctx -> sRequest = ($_SERVER[ 'REQUEST_METHOD' ]??'GET') . ' ' . $ctx -> sRequest;
	}

	foreach( $a as $postId => &$postIdVal )
	{
		foreach( array( 'metas', 'attributes' ) as $fld )
			if( isset( $postIdVal[ 'r' ][ $fld ] ) )
				$postIdVal[ 'r' ][ $fld ] = array_keys( $postIdVal[ 'r' ][ $fld ] );

		$postIdVal[ 'r' ][ 'initiator' ] = $ctx -> sRequest;
	}
}

function _OnCheckUpdatePost()
{
	global $seraph_accel_g_aDelUrls;
	global $seraph_accel_g_postUpdated;
	global $seraph_accel_g_postUpdatedSync;
	global $seraph_accel_g_postUpdateSche;

	if( $seraph_accel_g_aDelUrls )
	{
		$aUrl = array();
		foreach( $seraph_accel_g_aDelUrls as $url => $aU )
			if( count( $aU ) > 1 )
				$aUrl[] = $url;

		if( $aUrl )
		{
			if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
			{
				foreach( $seraph_accel_g_aDelUrls as $url => $aU )
					if( count( $aU ) > 1 )
						LogWrite( 'Automatic deleting due to URL was changed ' . json_encode( $aU ) . ', old URL: ' . $url, Ui::MsgInfo, 'Cache update' );
			}

			CacheOpUrls( false, $aUrl, 2, 5, false );
		}
	}

	$ctx = new AnyObj();
	_OnCheckUpdatePost_FinalizeArr( $ctx, $seraph_accel_g_postUpdatedSync );
	_OnCheckUpdatePost_FinalizeArr( $ctx, $seraph_accel_g_postUpdated );
	unset( $ctx );

	if( $seraph_accel_g_postUpdatedSync )
	{
		if( !is_admin() )
			_CheckUpdatePostProcess( $seraph_accel_g_postUpdatedSync, false );

		$seraph_accel_g_postUpdated = $seraph_accel_g_postUpdated ? ( $seraph_accel_g_postUpdatedSync + $seraph_accel_g_postUpdated ) : $seraph_accel_g_postUpdatedSync;

		unset( $seraph_accel_g_postUpdatedSync );
	}

	if( $seraph_accel_g_postUpdated )
		Plugin::AsyncFastTaskPost( 'CheckUpdatePostProcessAdd', array( 'a' => $seraph_accel_g_postUpdated ), 2 * 60 * 60, Plugin::ASYNCTASK_PUSH_AUTO );

	if( $seraph_accel_g_postUpdateSche )
	{
		foreach( $seraph_accel_g_postUpdateSche as $postId => $aReasons )
		{
			foreach( $aReasons as $reasonType => $aReason )
			{
				foreach( $aReason as $reason => $tmSche )
				{
					$args = array( 'i' => $postId, 'r' => array( $reasonType, $reason ) );
					$fnCheck =
						function( $args, $argsPrev )
						{
							if( $args[ 'i' ] !== Gen::GetArrField( $argsPrev, array( 'i' ), 0 ) )
								return( null );
							if( $args[ 'r' ] !== Gen::GetArrField( $argsPrev, array( 'r' ), array() ) )
								return( null );
							return( $args );
						};

					if( $tmSche )
						Plugin::AsyncFastTaskPost( 'CheckUpdatePostProcessSche', $args, array( $tmSche, 2 * 60 * 60 ), Plugin::ASYNCTASK_PUSH_AUTO, $fnCheck );
					else
						Plugin::AsyncTaskDel( 'CheckUpdatePostProcessSche', $args, $fnCheck );

					if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
					{
						$txt = 'Scheduled automatic updating ';
						if( $tmSche )
							$txt .= 'set at ' . gmdate( 'd M Y H:i:s', $tmSche ) . ' GMT';
						else
							$txt .= 'deleted';

						$txt .= ' due to post with ID ' . $postId . ' changed: ' . json_encode( $args[ 'r' ] );

						LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
					}
				}
			}
		}
	}
}

function _CheckUpdatePostProcess( $aPostUpdated, $proc = null, $cbIsAborted = false )
{
	foreach( $aPostUpdated as $postId => $postIdVal )
	{
		$priority = 5;
		$reason = null;
		if( is_array( $postIdVal ) )
		{
			$reason = ($postIdVal[ 'r' ]??null);
			if( is_array( $reason ) )
			{
				if( isset( $reason[ 'sch' ] ) )
					$priority = 4;
				$reason = @json_encode( $reason );
			}
			$postIdVal = ($postIdVal[ 'v' ]??false);
		}

		if( !$postIdVal )
		{
			$post = get_post( $postId );
			if( $post && is_post_type_viewable( $post -> post_type ) && CacheOp_IsPostVisible( $post ) )
				$postIdVal = true;
		}

		if( $postIdVal && CacheOpPost( $postId, $reason, $priority, $proc, $cbIsAborted, 30 ) === false )
			return( false );
	}
}

function _CheckUpdatePostProcessAdd( $aPostUpdated )
{
	$dirQueue = GetCacheDir() . '/upq/' . GetSiteId();

	$lock = new Lock( 'l', $dirQueue );
	if( !$lock -> Acquire() )
		return;

	$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
	$a -> setItems( $aPostUpdated );
	$a -> dispose(); unset( $a );

	$lock -> Release();

	Plugin::AsyncTaskPost( 'CheckUpdatePostProcess', null, 24 * 60 * 60, true, true );
}

function _CheckUpdatePostProcessRtn( $full = true )
{
	$lockGlobal = new Lock( 'upl', GetCacheDir() );
	if( !$lockGlobal -> Acquire( false ) )
		return;

	$settCacheGlobal = Gen::GetArrField( Plugin::SettGetGlobal(), array( 'cache' ), array() );

	$ctx = new AnyObj();
	$ctx -> procWorkInt = ($settCacheGlobal[ 'procWorkInt' ]??null);
	$ctx -> procPauseInt = ($settCacheGlobal[ 'procPauseInt' ]??null);
	$ctx -> _isAborted =
		function( $ctx )
		{
			return( PluginFileValues::GetEx( $ctx -> dirFileValues, 'up' ) === null );
		};
	$ctx -> isAborted = function( $ctx ) { return( !Gen::SliceExecTime( $ctx -> procWorkInt, $ctx -> procPauseInt, 5, array( $ctx, '_isAborted' ) ) ); };

	unset( $settCacheGlobal );

	$tmStart = time();
	$launchNext = false;

	for( ;; )
	{
		$continue = false;
		foreach( GetSiteIds() as $siteId )
		{
			$dirQueue = GetCacheDir() . '/upq/' . $siteId;
			$ctx -> dirFileValues = PluginFileValues::GetDirVar( $siteId );

			$lock = new Lock( 'l', $dirQueue );
			if( !$lock -> Acquire() )
				continue;

			$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
			$aPostUpdated = $a -> splice( 0, 10 );
			$a -> dispose(); unset( $a );

			$lock -> Release();

			if( !$aPostUpdated )
			{
				PluginFileValues::DelEx( $ctx -> dirFileValues, 'up' );
				continue;
			}

			if( !$full )
			{
				$launchNext = true;
				break;
			}

			if( PluginFileValues::GetEx( $ctx -> dirFileValues, 'up' ) === null )
				PluginFileValues::SetEx( $ctx -> dirFileValues, 'up', count( $aPostUpdated ) );

			$continue = true;

			if( is_multisite() )
				switch_to_blog( ( int )GetBlogIdFromSiteId( $siteId ) );

			_CheckUpdatePostProcess( $aPostUpdated, null, array( $ctx, 'isAborted' ) );

			if( time() - $tmStart > 60 )
			{
				$continue = false;
				$launchNext = true;
				break;
			}
		}

		if( !$continue )
			break;
	}

	$lockGlobal -> Release();

	if( $launchNext )
		Plugin::AsyncTaskPost( 'CheckUpdatePostProcess', null, 24 * 60 * 60, true, true );
}

function OnAsyncTask_CheckUpdatePostProcessAdd( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$aPostUpdated = Gen::GetArrField( $args, array( 'a' ), array() );

	$timeDelay = Gen::GetArrField( Plugin::SettGet(), array( 'cache', 'updPostDelay' ), 0 );

	if( $timeDelay <= 0 )
	{
		_CheckUpdatePostProcessAdd( $aPostUpdated );
		return;
	}

	$dirQueue = GetCacheDir() . '/uppq/' . GetSiteId();

	$lock = new Lock( 'l', $dirQueue );
	if( !$lock -> Acquire() )
		return;

	$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
	$a -> setItems( $aPostUpdated );
	$a -> dispose(); unset( $a );

	$lock -> Release();

	Plugin::AsyncTaskPost( 'CheckUpdatePostProcessAddPostponed', null, array( time() + $timeDelay, 2 * 60 * 60 ), true, function( $args, $argsPrev ) { return( false ); } );
}

function OnAsyncTask_CheckUpdatePostProcessAddPostponed( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$dirQueue = GetCacheDir() . '/uppq/' . GetSiteId();

	$lock = new Lock( 'l', $dirQueue );
	if( !$lock -> Acquire() )
		return;

	$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
	$aPostUpdated = $a -> splice();
	$a -> dispose(); unset( $a );

	$lock -> Release();

	_CheckUpdatePostProcessAdd( $aPostUpdated );
}

function OnAsyncTask_CheckUpdatePostProcessSche( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$postId = Gen::GetArrField( $args, array( 'i' ), 0 );
	$aRsn = Gen::GetArrField( $args, array( 'r' ), array() );

	$aPostUpdated = array( $postId => array( 'v' => false, 'r' => array( 'sch' => array( Gen::GetArrField( $aRsn, 0, '' ) => Gen::GetArrField( $aRsn, 1, '' ) ) ) ) );
	Plugin::AsyncFastTaskPost( 'CheckUpdatePostProcessAdd', array( 'a' => $aPostUpdated ), 2 * 60 * 60, Plugin::ASYNCTASK_PUSH_AUTO );
}

function OnAsyncTask_CheckPostProcess( $args )
{
	Gen::GarbageCollectorEnable( false );

	_CheckUpdatePostProcessRtn( false );
}

function OnAsyncTask_CheckUpdatePostProcess( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	_CheckUpdatePostProcessRtn( true );
}

function _OnCheckUpdateGlob()
{
	global $seraph_accel_g_globUpdated;

	if( !$seraph_accel_g_globUpdated )
		return;

	$op = false;
	foreach( $seraph_accel_g_globUpdated as $fldId => $opParticular )
		if( $op === false || $op < $opParticular )
			$op = $opParticular;

	if( $op === false )
		return;

	if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
	{
		$txt = '';
		switch( $op )
		{
		case 0:			$txt .= 'Automatic revalidation'; break;
		case 3:		$txt .= 'Automatic revalidation if needed'; break;
		case 2:					$txt .= 'Automatic deleting'; break;
		}

		$txt .= ' due to ' . implode( ', ', array_map(
			function( $v )
			{
				static $g_aReason = array( 'term' => 'taxonomie(s)', 'menu' => 'menu(s)', 'elmntrTpl' => 'Elementor template(s)', 'tblPrss' => 'TablePress table(s)' );
				return( ($g_aReason[ $v ]??'UNK') );
			}
		, array_keys( $seraph_accel_g_globUpdated ) ) ) . ' changed; scope: all';

		LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
	}

	RunOpt( $op, false );
}

class _CheckUpdatePost_Rtn
{
	public $updGlob;

	static public $level = 0;

	public function __destruct()
	{
		_OnCheckUpdatePost();
		if( $this -> updGlob )
			_OnCheckUpdateGlob();
	}

	static function Init( $level, $updGlob )
	{
		if( !self::$g_oInst )
			self::$g_oInst = new _CheckUpdatePost_Rtn();
		self::$g_oInst -> updGlob = $updGlob;

		if( self::$level < $level )
			self::$level = $level;
	}

	private static $g_oInst;
}

function _OnContentTest( $buffer )
{
	$pos = Gen::StrPosArr( $buffer, array( '</body>', '</BODY>' ) );
	if( $pos === false )
		return( $buffer );

	$settTest = Gen::GetArrField( Plugin::SettGet(), array( 'test' ), array() );

	if( ($settTest[ 'contExtra' ]??null) )
	{
		$size = Gen::GetArrField( $settTest, array( 'contExtraSize' ), 0 );
		$extra = GetContentTestData( $size );
		$tmCur = time();
		$extra = "\r\n" . Ui::Tag( 'div', Ui::Tag( 'style', '.always-not-existed-t-' . $tmCur . ' {border-style:solid;}' ) . $extra, array( 'class' => 'seraph_accel test-random-content size-' . ( $size / 1024 ) . 'KB', 'data-ts' => date_i18n( 'Y-m-d H:i:s \\G\\M\\T', $tmCur, true ), 'style' => array( 'display' => 'none' ) ) ) . "\r\n";

		$buffer = substr( $buffer, 0, $pos ) . $extra . substr( $buffer, $pos );
	}

	if( ($settTest[ 'contDelay' ]??null) )
	{
		$timeout = Gen::GetArrField( $settTest, array( 'contDelayTimeout' ), 0 ) / 1000;
		while( $timeout && !ContentProcess_IsAborted() )
		{
			sleep( 5 );
			$timeout = ( $timeout < 5 ) ? 0 : ( $timeout - 5 );
		}
	}

	return( $buffer );
}

function ExtDb_GetWooMaxMindGeolocationSvc()
{
	return( Gen::GetArrField( Wp::GetFilters( 'woocommerce_get_geolocation', array( 'WC_Integration_MaxMind_Geolocation', 'get_geolocation' ) ), array( 0, 'f', 0 ) ) );
}

function ExtDbUpd()
{
	$svc = ExtDb_GetWooMaxMindGeolocationSvc();
	$dbIP2CFile = $svc ? $svc -> get_database_service() -> get_database_path() : null;

	if( $dbIP2CFile )
	{
		if( !Wp::GetFilters( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_Start' ) )
			add_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_Start', -9999 );
		do_action( 'woocommerce_geoip_updater', null );

		return;
	}

	$apiKey = Gen::GetArrField( Plugin::SettGet(), array( 'cache', 'viewsGeo', 'apiKeyMmDb' ) );
	if( !$apiKey )
		return;

	if( PluginFileValues::GetEx( PluginFileValues::GetDirVar( 'm' ), 'edbu' ) )
		return;

	PluginFileValues::SetEx( PluginFileValues::GetDirVar( 'm' ), 'edbu', true );

	$dirTmp = GetCacheDir() . '/tmp/mmdb';
	Gen::MakeDir( $dirTmp, true );
	Gen::DelDir( $dirTmp, false );

	Gen::MakeDir( dirname( GetCacheDir() . '/db/mm/ip2c.mmdb' ), true );
	$lock = new Lock( GetCacheDir() . '/db/l', false );

	_UpdateGeoDb_MmIP2C( $dirTmp, $lock, $apiKey );
	_UpdateGeoDb_MmC2IP( $dirTmp, $lock, $apiKey );

	Gen::DelDir( $dirTmp );

	PluginFileValues::DelEx( PluginFileValues::GetDirVar( 'm' ), 'edbu' );
}

function _UpdateGeoDb_MmIP2C( $dirTmp, $lock, $apiKey )
{
	$ext = 'tar.gz';

	$requestRes = Wp::RemoteGet( 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&suffix=' . $ext . '&license_key=' . urlencode( $apiKey ) );
	$hr = Net::GetHrFromWpRemoteGet( $requestRes, true );
	if( $hr != Gen::S_OK )
		return;

	$fileTmp = $dirTmp . '/db-IP2C.' . $ext;

	if( !@file_put_contents( $fileTmp, wp_remote_retrieve_body( $requestRes ) ) )
		return;

	try
	{
		$file = new \PharData( $fileTmp );
		$file -> extractTo( $dirTmp, null, true );
		unset( $file );
	}
	catch ( Exception $exception )
	{

	}
	@unlink( $fileTmp );

	$dirDbRoot = null;
	Gen::DirEnum( $dirTmp, $dirDbRoot,
		function( $path, $item, &$dirDbRoot )
		{
			$path = $path . '/' . $item;
			if( @is_dir( $path ) )
			{
				$dirDbRoot = $item;
				return( false );
			}

			return( true );
		}
	);

	$data = Gen::FileGetContents( $dirTmp . '/' . $dirDbRoot . '/GeoLite2-Country.mmdb' );

	if( !is_string( $data ) )
		return;

	_FileWriteTmpAndReplace( GetCacheDir() . '/db/mm/ip2c.mmdb', null, $data, null, $lock );
}

function _UpdateGeoDb_MmC2IP( $dirTmp, $lock, $apiKey )
{
	$ext = 'zip';

	$requestRes = Wp::RemoteGet( 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country-CSV&suffix=' . $ext . '&license_key=' . urlencode( $apiKey ) );
	$hr = Net::GetHrFromWpRemoteGet( $requestRes, true );
	if( $hr != Gen::S_OK )
		return;

	$fileTmp = $dirTmp . '/db-C2IP.' . $ext;

	if( !@file_put_contents( $fileTmp, wp_remote_retrieve_body( $requestRes ) ) )
		return;

	try
	{
		$file = new \PharData( $fileTmp );
		$file -> extractTo( $dirTmp, null, true );
		unset( $file );
	}
	catch ( Exception $exception )
	{

	}
	@unlink( $fileTmp );

	$dirDbRoot = null;
	Gen::DirEnum( $dirTmp, $dirDbRoot,
		function( $path, $item, &$dirDbRoot )
		{
			$path = $path . '/' . $item;
			if( @is_dir( $path ) )
			{
				$dirDbRoot = $item;
				return( false );
			}

			return( true );
		}
	);

	$aGeonameIdIp = array();
	foreach( array( 'IPv4', 'IPv6' ) as $ipDbId )
	{
		$oDataBlocksIP = new CsvFileAsDb(); $oDataBlocksIP -> open( $dirTmp . '/' . $dirDbRoot . '/GeoLite2-Country-Blocks-' . $ipDbId . '.csv' );
		for( ; $oDataBlocksIP -> valid(); $oDataBlocksIP -> next() )
		{
			$geoname_id = $oDataBlocksIP -> get( 'geoname_id' );
			if( !$geoname_id || isset( $aGeonameIdIp[ $geoname_id ] ) )
				continue;

			$ip = $oDataBlocksIP -> get( 'network' );
			if( $ip && preg_match( '@([^/]+)/\\d+@S', $ip, $m ) )
				$aGeonameIdIp[ $geoname_id ] = $m[ 1 ];
		}

		$oDataBlocksIP -> Release(); unset( $oDataBlocksIP );
	}

	$aRegionsIp = array();
	{
		$oDataLocations = new CsvFileAsDb(); $oDataLocations -> open( $dirTmp . '/' . $dirDbRoot . '/GeoLite2-Country-Locations-en.csv' );
		for( ; $oDataLocations -> valid(); $oDataLocations -> next() )
		{
			$geoname_id = $oDataLocations -> get( 'geoname_id' ); $k = $oDataLocations -> get( 'country_iso_code' );
			if( !$geoname_id || !$k )
				continue;

			$v = ($aGeonameIdIp[ $geoname_id ]??null);
			if( $v !== null )
				$aRegionsIp[ $k ] = $v;
		}

		$oDataLocations -> Release(); unset( $oDataLocations );
	}

	ksort( $aRegionsIp );

	_FileWriteTmpAndReplace( GetCacheDir() . '/db/mm/c2ip-v1.dat', null, @serialize( $aRegionsIp ), null, $lock );
}

function _OnUpdateGeoDb_Mm_Start()
{
	if( PluginFileValues::GetEx( PluginFileValues::GetDirVar( 'm' ), 'edbu' ) )
		return;

	PluginFileValues::SetEx( PluginFileValues::GetDirVar( 'm' ), 'edbu', true );

	add_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_C2IP' );
	add_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_Finish', 9999 );
}

function _OnUpdateGeoDb_Mm_C2IP()
{
	$svc = ExtDb_GetWooMaxMindGeolocationSvc();
	$apiKey = $svc ? $svc -> get_option( 'license_key' ) : null;
	if( !$apiKey )
		return;

	$dirTmp = GetCacheDir() . '/tmp/mmdb';
	Gen::MakeDir( $dirTmp, true );
	Gen::DelDir( $dirTmp, false );

	Gen::MakeDir( dirname( GetCacheDir() . '/db/mm/c2ip-v1.dat' ), true );
	$lock = new Lock( GetCacheDir() . '/db/l', false );

	_UpdateGeoDb_MmC2IP( $dirTmp, $lock, wc_clean( $apiKey ) );

	Gen::DelDir( $dirTmp );
}

function _OnUpdateGeoDb_Mm_Finish()
{
	remove_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_C2IP' );
	remove_action( 'woocommerce_geoip_updater', 'seraph_accel\\_OnUpdateGeoDb_Mm_Finish', 9999 );

	PluginFileValues::DelEx( PluginFileValues::GetDirVar( 'm' ), 'edbu' );
}

function _ManagePage()
{
	Plugin::CmnScripts( array( 'Cmn', 'Gen', 'Ui', 'Net', 'AdminUi' ) );
	wp_register_script( Plugin::ScriptId( 'Admin' ), add_query_arg( Plugin::GetFileUrlPackageParams(), Plugin::FileUrl( 'Admin.js', __FILE__ ) ), array_merge( array( 'jquery' ), Plugin::CmnScriptId( array( 'Cmn', 'Gen', 'Ui', 'Net' ) ) ), '2.27.20' );
	Plugin::Loc_ScriptLoad( Plugin::ScriptId( 'Admin' ) );
	wp_enqueue_script( Plugin::ScriptId( 'Admin' ) );

	Plugin::DisplayAdminFooterRateItContent();

	$adminMsModes = Wp::GetMultisiteAdminModes();

	$isPaidLockedContent = false;

	$rmtCfg = PluginRmtCfg::Get();
	$sett = Plugin::SettGet();
	$siteId = GetSiteId();

	$aViews = GetViewsList( $sett, true );
	$aGeos = GetGeosList( $sett, true );

	{
		Ui::PostBoxes_MetaboxAdd( 'status', esc_html_x( 'Title', 'admin.Manage_Status', 'seraphinite-accelerator' ) . Ui::Tag( 'span', Ui::AdminHelpBtn( Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Manage_Status' ), Ui::AdminHelpBtnModeBlockHeader ) ), false,
			function( $callbacks_args, $box )
			{
				extract( $box[ 'args' ] );

				echo( Ui::TagOpen( 'div', array( 'class' => 'blck' ) ) );
				{
					$info = GetStatusData( $siteId );

					echo( Ui::SettBlock_Begin( array( 'class' => 'compact' ) ) );
					{

						echo( Ui::SettBlock_Item_Begin( esc_html_x( 'PostUpdLbl', 'admin.Manage_Status', 'seraphinite-accelerator' ), array( 'class' => array( 'blck', 'postupd' ) ) ) );
						{
							echo( Ui::Label( $info[ 'cont' ][ 'postUpd' ], false, array( 'data-id-cont' => 'postUpd' ) ) );
							echo( Ui::TagOpen( 'p' ) );
							{
								echo( Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnPostUpdCancel(this); return false;' ) ) );
								echo( Ui::Spinner( false, array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'display' => 'none' ) ) ) );
							}
							echo( Ui::TagClose( 'p' ) );
						}
						echo( Ui::SettBlock_Item_End() );

						echo( Ui::SettBlock_Item_Begin( esc_html_x( 'ScheUpdLbl', 'admin.Manage_Status', 'seraphinite-accelerator' ), array( 'class' => array( 'blck', 'scheupd' ) ) ) );
						{
							echo( Ui::Label( $info[ 'cont' ][ 'scheUpd' ], false, array( 'data-id-cont' => 'scheUpd' ) ) );
							echo( Ui::TagOpen( 'p' ) );
							{
								echo( Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnScheUpdCancel(this); return false;' ) ) );
								echo( Ui::Spinner( false, array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'display' => 'none' ) ) ) );
							}
							echo( Ui::TagClose( 'p' ) );
						}
						echo( Ui::SettBlock_Item_End() );

						echo( Ui::SettBlock_Item_Begin( esc_html_x( 'CleanupLbl', 'admin.Manage_Status', 'seraphinite-accelerator' ), array( 'class' => array( 'blck', 'cleanup' ) ) ) );
						{
							echo( Ui::Label( $info[ 'cont' ][ 'cleanUp' ], false, array( 'data-id-cont' => 'cleanUp' ) ) );
							echo( Ui::TagOpen( 'p' ) );
							{
								echo( Ui::Button( Wp::GetLocString( 'Start', null, 'seraphinite-accelerator' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnCacheOp(this,1); return false;' ) ) );
								echo( Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnCacheOpCancel(this,1); return false;' ) ) );
								echo( Ui::Spinner( false, array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'display' => 'none' ) ) ) );
							}
							echo( Ui::TagClose( 'p' ) );
						}
						echo( Ui::SettBlock_Item_End() );

						echo( Ui::SettBlock_Item_Begin( esc_html_x( 'LoadAvgLbl', 'admin.Manage_Status', 'seraphinite-accelerator' ), array( 'style' => array( 'display' => 'none' ) ) ) );
						{
							echo( Ui::Label( $info[ 'cont' ][ 'loadAvg' ], false, array( 'data-id-cont' => 'loadAvg' ) ) );
						}
						echo( Ui::SettBlock_Item_End() );

						if( Gen::GetArrField( $sett, 'cache/viewsGeo/enable', false, '/' ) )
						{
							echo( Ui::SettBlock_Item_Begin( esc_html_x( 'ExtDbLbl', 'admin.Manage_Status', 'seraphinite-accelerator' ), array( 'class' => array( 'blck', 'extdb' ) ) ) );
							{
								echo( Ui::Label( $info[ 'cont' ][ 'extDb' ], false, array( 'data-id-cont' => 'extDb' ) ) );
								echo( Ui::TagOpen( 'p' ) );
								{
									echo( Ui::Button( Wp::GetLocString( 'Update' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnExtDbUpdBegin(this); return false;' ) ) );
									echo( Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnExtDbUpdCancel(this); return false;' ) ) );
									echo( Ui::Spinner( false, array( 'class' => array( 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'display' => 'none' ) ) ) );
								}
								echo( Ui::TagClose( 'p' ) );
							}
							echo( Ui::SettBlock_Item_End() );
						}
					}
					echo( Ui::SettBlock_End() );
				}
				echo( Ui::TagClose( 'div' ) );
			},
			get_defined_vars(), 'body', null, null, $adminMsModes[ 'local' ]
		);

		Ui::PostBoxes_MetaboxAdd( 'stat', esc_html_x( 'Title', 'admin.Manage_Stat', 'seraphinite-accelerator' ) . Ui::Tag( 'span', Ui::AdminHelpBtn( Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Manage_Stat' ), Ui::AdminHelpBtnModeBlockHeader ) ), false,
			function( $callbacks_args, $box )
			{
				extract( $box[ 'args' ] );

				echo( Ui::TagOpen( 'div', array( 'class' => 'blck' ) ) );
				{
					echo( Ui::Tag( 'table', Ui::Tag( 'tbody', GetStatCont( $siteId, get_option( 'seraph_accel_status' ) ), array( 'data-id-cont' => 'stat' ) ), array( 'class' => 'form-table stat' ) ) );

					echo( Ui::Tag( 'div',
						Ui::Button( esc_html_x( 'Refresh', 'admin.Manage_Stat', 'seraphinite-accelerator' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnStatOp( this, true ); return false;' ) ) .
						Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnStatOp( this, false ); return false;' ) ) .
						Ui::Spinner( false, array( 'class' => 'ctlSpaceAfter ctlVaMiddle', 'style' => array( 'display' => 'none' ) ) )
					) );
				}
				echo( Ui::TagClose( 'div' ) );
			},
			get_defined_vars(), 'body', null, null, $adminMsModes[ 'local' ]
		);

		Ui::PostBoxes_MetaboxAdd( 'operate', esc_html_x( 'Title', 'admin.Manage_Operate', 'seraphinite-accelerator' ) . Ui::Tag( 'span', Ui::AdminHelpBtn( Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Manage_Operate' ), Ui::AdminHelpBtnModeBlockHeader ) ), true,
			function( $callbacks_args, $box )
			{
				extract( $box[ 'args' ] );

				echo( Ui::TagOpen( 'div', array( 'class' => 'blck' ) ) );
				{
					$aScope = array(
						'all'					=> esc_html_x( 'Item_All', 'admin.Manage_Operate_Clear', 'seraphinite-accelerator' ),
						'uri'					=> esc_html_x( 'Item_Uri', 'admin.Manage_Operate_Clear', 'seraphinite-accelerator' ),
					);

					$aScope[ 'uri:@home' ] = Wp::GetLocString( array( 'Front Page', 'page label' ) );
					foreach( get_post_types( array(), 'objects' ) as $postType )
						if( is_post_type_viewable( $postType ) && Gen::GetArrField( $postType, array( 'show_in_nav_menus' ) ) )
							$aScope[ 'uri:@posts@' . $postType -> name ] = Gen::GetArrField( $postType, array( 'labels', 'all_items' ), '' );

					echo( Ui::Tag( 'div', Ui::ComboBox(
						null, $aScope,
						'all', false, array( 'class' => 'type', 'style' => array( 'width' => 'auto' ), 'onchange' => 'seraph_accel.Ui.ComboShowDependedItems( this, jQuery( this.parentNode ).closest( ".postbox" ).first().get( 0 ) )' ) ) ) );

					echo( Ui::Tag( 'div', Ui::Tag( 'textarea', null, array( 'id' => 'seraph_accel_opUrl', 'class' => 'uri ns-uri ctlSpaceAfter ctlSpaceVBefore seraph_accel_textarea', 'style' => array( 'min-height' => 2 * (3/2) . 'em', 'max-height' => 20 * (3/2) . 'em', 'width' => '100%', 'display' => 'none' ), 'placeholder' => _x( 'UriPhlr', 'admin.Manage_Operate', 'seraphinite-accelerator' ) ) ) ) );

					{
						$oSub = '';
						if( count( $aViews ) > 1 )
							$oSub .= Ui::Tag( 'td', Ui::TokensList( array_keys( $aViews ), 'seraph_accel_views', array( 'style' => array( 'min-height' => '3em', 'height' => '5em', 'max-height' => '15em' ), 'data-oninit' => 'seraph_accel.Ui.TokensMetaTree.Expand(this,seraph_accel.Manager._int.views,true)' ) ) );

						if( $aGeos )
							$oSub .= Ui::Tag( 'td', Ui::TokensList( array( '' ), 'seraph_accel_geos', array( 'style' => array( 'min-height' => '3em', 'height' => '5em', 'max-height' => '15em' ), 'data-oninit' => 'seraph_accel.Ui.TokensMetaTree.Expand(this,seraph_accel.Manager._int.geos,true)' ) ) );

						if( $oSub )
						{
							echo( Ui::SettBlock_ItemSubTbl_Begin( array( 'class' => 'ctlSpaceVBefore std', 'style' => array( 'width' => '100%' ) ) ) );
							echo( Ui::Tag( 'tr', $oSub ) );
							echo( Ui::SettBlock_ItemSubTbl_End() );
						}

						unset( $oSub );
					}

					echo( Ui::Tag( 'div',
						Ui::Button( Wp::safe_html_x( 'Delete', 'admin.Manage_Operate', 'seraphinite-accelerator' ), true, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVBefore', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnCacheOp(this,2);return false;' ) ) .
						Ui::Button( Wp::safe_html_x( 'Revalidate', 'admin.Manage_Operate', 'seraphinite-accelerator' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVBefore', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnCacheOp(this,0);return false;' ) ) .
						Ui::Button( Wp::safe_html_x( 'CheckRevalidate', 'admin.Manage_Operate', 'seraphinite-accelerator' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVBefore', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnCacheOp(this,3);return false;' ) ) .
						Ui::Button( Wp::safe_html_x( 'SrvDel', 'admin.Manage_Operate', 'seraphinite-accelerator' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVBefore', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnCacheOp(this,10);return false;' ) ) .
						Ui::Button( Wp::GetLocString( 'Cancel' ), false, null, null, 'button', array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVBefore', 'ctlVaMiddle', 'cancel' ), 'style' => array( 'min-width' => '7em' ), 'disabled' => true, 'onclick' => 'seraph_accel.Manager._int.OnCacheOpCancel(this);return false;' ) ) .
						Ui::Spinner( false, array( 'class' => 'ctlSpaceAfter ctlSpaceVBefore ctlVaMiddle', 'style' => array( 'display' => 'none' ) ) ) .
						Ui::Tag( 'span', null, array( 'class' => 'ctlSpaceAfter ctlSpaceVBefore ctlVaMiddle ctlInlineBlock descr', 'style' => array( 'display' => 'none' ) ) )
					) );
				}
				echo( Ui::TagClose( 'div' ) );
			},
			get_defined_vars(), 'body', null, null, $adminMsModes[ 'local' ]
		);

		if( current_user_can( 'manage_options' ) )
		{
			Ui::PostBoxes_MetaboxAdd( 'htmlChecker', esc_html_x( 'Title', 'admin.Manage_HtmlChecker', 'seraphinite-accelerator' ) . Ui::Tag( 'span', Ui::AdminBtnsBlock( array( array( 'type' => Ui::AdminBtn_Help, 'href' => Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Help.Manage_HtmlChecker' ) ), Plugin::AdminBtnsBlock_GetPaidContent( $isPaidLockedContent ) ), Ui::AdminHelpBtnModeBlockHeader ) ), true,
				function( $callbacks_args, $box )
				{
					extract( $box[ 'args' ] );

					echo( Ui::TagOpen( 'div', array( 'class' => 'blck' ) ) );
					{
						echo( Ui::Tag( 'p', Ui::TextBox( 'seraph_accel_urlCheck', '', array( 'class' => 'url', 'style' => array( 'width' => '100%' ) ), true ) ) );

						echo( Ui::Tag( 'div',
							Ui::CheckBox( esc_html_x( 'LiteChk', 'admin.Manage_HtmlChecker_Fix', 'seraphinite-accelerator' ), null, true, false, array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVAfter' ) ), null, array( 'class' => array( 'liteChk' ) ) ) .
							Ui::CheckBox( esc_html_x( 'MedChk', 'admin.Manage_HtmlChecker_Fix', 'seraphinite-accelerator' ), null, false, false, array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVAfter' ) ), null, array( 'class' => array( 'medChk' ) ) ) .
							Ui::CheckBox( esc_html_x( 'TidyChk', 'admin.Manage_HtmlChecker_Fix', 'seraphinite-accelerator' ), null, false, false, array( 'class' => array( 'ctlSpaceAfter', 'ctlSpaceVAfter' ) ), null, array( 'class' => array( 'tidyChk' ) ) )
						) );

						echo( Ui::Tag( 'p',
							Ui::Button( esc_html_x( 'Check', 'admin.Manage_Operate', 'seraphinite-accelerator' ), true, null, null, 'button', array( 'class' => array( 'ns-all', 'ns-uri', 'ctlSpaceAfter', 'ctlVaMiddle' ), 'style' => array( 'min-width' => '7em' ), 'onclick' => 'seraph_accel.Manager._int.OnHtmlCheck( this );return false;' ) ) .
							Ui::Spinner( false, array( 'class' => 'ctlVaMiddle', 'style' => array( 'display' => 'none' ) ) )
						) );

						echo( Ui::Tag( 'div', null, array( 'class' => 'seraph_accel_textarea messages', 'style' => array( 'overflow' => 'scroll', 'min-height' => '7em', 'height' => '7em', 'max-height' => '100em', 'resize' => 'vertical' ) ) ) );
					}
					echo( Ui::TagClose( 'div' ) );
				},
				get_defined_vars(), 'body', null, null, false
			);
		}
	}

	{
		$htmlContent = Plugin::GetAdvertProductsContent( 'advertProducts' );
		if( !empty( $htmlContent ) )
			Ui::PostBoxes_MetaboxAdd( 'advertProducts', Plugin::GetAdvertProductsTitle(), false, function( $callbacks_args, $box ) { echo( $box[ 'args' ][ 'c' ] ); }, array( 'c' => $htmlContent ), 'normal' );
	}

	{
		$htmlContent = Plugin::GetRateItContent( 'rateIt', Plugin::DisplayContent_SmallBlock );
		if( !empty( $htmlContent ) )
			Ui::PostBoxes_MetaboxAdd( 'rateIt', Plugin::GetRateItTitle(), false, function( $callbacks_args, $box ) { echo( $box[ 'args' ][ 'c' ] ); }, array( 'c' => $htmlContent ), 'side' );

		$htmlContent = Plugin::GetLockedFeatureLicenseContent( Plugin::DisplayContent_SmallBlock );
		if( !empty( $htmlContent ) )
			Ui::PostBoxes_MetaboxAdd( 'switchToFull', Plugin::GetSwitchToFullTitle(), false, function( $callbacks_args, $box ) { echo( $box[ 'args' ][ 'c' ] ); }, array( 'c' => $htmlContent ), 'side' );

		Ui::PostBoxes_MetaboxAdd( 'about', Plugin::GetAboutPluginTitle(), false, function( $callbacks_args, $box ) { echo( Plugin::GetAboutPluginContent() ); }, null, 'side' );
		Ui::PostBoxes_MetaboxAdd( 'aboutVendor', Plugin::GetAboutVendorTitle(), false, function( $callbacks_args, $box ) { echo( Plugin::GetAboutVendorContent() ); }, null, 'side' );

		if( Gen::GetArrField( PluginRmtCfg::Get(), 'Prms.HostingBannerShow-Base' ) )
			Ui::PostBoxes_MetaboxAdd( 'hostingBanner', esc_html_x( 'Title', 'admin.HostingBanner', 'seraphinite-accelerator' ), true, function( $callbacks_args, $box ) { echo( GetHostingBannerContent() ); }, null, 'side' );
	}

	Ui::PostBoxes( Plugin::GetSubjectTitle( esc_html_x( 'Title', 'admin.Manage', 'seraphinite-accelerator' ) ), array( 'body' => array(  ), 'normal' => array(), 'side' => array(  ) ),
		array(),
		get_defined_vars(),
		array( 'wrap' => array( 'id' => 'seraph_accel_manage', 'data-oninit' => 'seraph_accel.Manager._int.views = ' . @json_encode( $aViews ) . ';seraph_accel.Manager._int.geos = ' . @json_encode( $aGeos ) . ';seraph_accel.Manager._int.OnDataRefreshInit(this,' . ( $adminMsModes[ 'local' ] ? 'false' : 'true' ) . ')' ) )
	);
}

function GetHostingBannerContent()
{
	$rmtCfg = PluginRmtCfg::Get();

	$urlLogoImg = add_query_arg( array( 'v' => '2.27.20' ), Plugin::FileUri( 'Images/hosting-icon-banner.svg', __FILE__ ) );
	$urlMoreInfo = Plugin::RmtCfgFld_GetLoc( $rmtCfg, 'Links.UrlHostingInfo' );

	$res = '';

	$res .= Ui::Tag( 'p' );

	{
		$res .= Ui::TagOpen( 'div' );

		if( !empty( $urlLogoImg ) )
			$res .= Ui::Link( Ui::Tag( 'img', null, array( 'class' => 'ctlSpaceAfter', 'width' => 100, 'style' => array( 'float' => 'left' ), 'src' => $urlLogoImg ), true ), $urlMoreInfo, true );

		$res .= '<h3 style="margin:0">' . esc_html_x( 'Name', 'admin.HostingBanner', 'seraphinite-accelerator' ) . '</h3>';

		$res .= Ui::TagClose( 'div' );
	}

	$res .= Ui::Tag( 'p', esc_html_x( 'Description', 'admin.HostingBanner', 'seraphinite-accelerator' ) );

	{
		$resPart = '';

			$resPart .= Ui::Button( Wp::GetLocString( array( 'MoreInfoBtn', 'admin.Common_AboutVendor' ), null, 'seraphinite-accelerator' ), false, null, 'ctlSpaceAfter', 'button', array( 'onclick' => 'window.open( \'' . $urlMoreInfo . '\', \'_blank\' )' ) );

		$res .= Ui::Tag( 'p', $resPart, null, false, array( 'noTagsIfNoContent' => true ) );
	}

	return( $res );
}

function CacheInitClearProcessor( $force = false, $init = true )
{
	if( !$init )
	{
		Plugin::AsyncTaskDel( 'CacheClearPeriodically' );
		return;
	}

	$settCache = Gen::GetArrField( Plugin::SettGet(), array( 'cache' ), array() );
	if( ($settCache[ 'enable' ]??null) && ($settCache[ 'autoClnPeriod' ]??null) )
		Plugin::AsyncTaskPost( 'CacheClearPeriodically', null, array( time() + ($settCache[ 'autoClnPeriod' ]??null) * 60 ), false, $force ? true : function( $args, $argsPrev ) { return( false ); } );
	else
		Plugin::AsyncTaskDel( 'CacheClearPeriodically' );
}

function OnAsyncTask_CacheClearPeriodically( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	CacheInitClearProcessor();

	if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
		LogWrite( 'Automatic cleaning up old; scope: all', Ui::MsgInfo, 'Cache update' );

	CacheOp( 1 );

}

function CacheOperScheduler_Item_GetNextRunTime( $item, $dtCur )
{
	$dtCurVals = DateTime::GetFmtVals( $dtCur, Wp::GetISOFirstWeekDay() );
	$tmCur = $dtCur -> getTimestamp();

	$period = ($item[ 'period' ]??24);
	$periodN = ($item[ 'periodN' ]??0);

	$tmNearest = null;
	foreach( Gen::GetArrField( $item, array( 'times' ), array() ) as $timeItem )
	{
		$tmItem = _CacheOperScheduler_ItemTime_GetNextRunTime( $timeItem, $dtCur, $dtCurVals, $tmCur, $period, $periodN );

		if( !$tmNearest )
			$tmNearest = $tmItem;
		else if( $tmItem < $tmNearest )
			$tmNearest = $tmItem;
	}

	return( $tmNearest );
}

function CacheOperScheduler_ItemTime_GetNextRunTime( $item, $timeItem, $dtCur )
{
	$dtCurVals = DateTime::GetFmtVals( $dtCur, Wp::GetISOFirstWeekDay() );
	$tmCur = $dtCur -> getTimestamp();

	$period = ($item[ 'period' ]??24);
	$periodN = ($item[ 'periodN' ]??0);

	return( _CacheOperScheduler_ItemTime_GetNextRunTime( $timeItem, $dtCur, $dtCurVals, $tmCur, $period, $periodN ) );
}

function _CacheOperScheduler_ItemTime_GetNextRunTime( $timeItem, $dtCur, $dtCurVals, $tmCur, $period, $periodN )
{
	$timeItemTm = ($timeItem[ 'tm' ]??0);
	$timeItemShift = ($timeItem[ 's' ]??0) % $periodN;
	$timeItemMonth = ($timeItem[ 'm' ]??0) % 12;

	$dtTest = clone $dtCur;

	$tmItem = 0;
	$operPrms = array();
	switch( $period )
	{
	case 0:
		$dtTest -> setTime( $dtCurVals[ DateTime::FMT_HOUR ], $dtCurVals[ DateTime::FMT_MINUTE ] );
		$operPrms = array( DateTime::FMT_MINUTE, 'FromMinutes' );
		break;

	case 1:
		$dtTest -> setTime( $dtCurVals[ DateTime::FMT_HOUR ], SettTimeoutEditor_GetMins( $timeItemTm ) );
		$operPrms = array( DateTime::FMT_HOUR, 'FromHours' );
		break;

	case 24:
		$dtTest -> setTime( SettTimeoutEditor_GetHours( $timeItemTm ), SettTimeoutEditor_GetMins( $timeItemTm ) );
		$operPrms = array( DateTime::FMT_DAY, 'FromDays' );
		break;

	case 168:
		$dtTest -> setISODate( $dtCurVals[ DateTime::FMT_YEAR ], $dtCurVals[ DateTime::FMT_WEEK ], SettTimeoutEditor_GetDays( $timeItemTm ) % 7 + 1 ) -> setTime( SettTimeoutEditor_GetHours( $timeItemTm ), SettTimeoutEditor_GetMins( $timeItemTm ) );
		$operPrms = array( DateTime::FMT_WEEK_USINGFIRSTDAY, 'FromWeeks' );
		break;

	case 720:
		$dtTest -> setDate( $dtCurVals[ DateTime::FMT_YEAR ], $dtCurVals[ DateTime::FMT_MONTH ], SettTimeoutEditor_GetDays( $timeItemTm ) % 31 + 1 ) -> setTime( SettTimeoutEditor_GetHours( $timeItemTm ), SettTimeoutEditor_GetMins( $timeItemTm ) );
		$operPrms = array( DateTime::FMT_MONTH, 'FromMonths' );
		break;

	default:
		$dtTest -> setDate( $dtCurVals[ DateTime::FMT_YEAR ], $timeItemMonth + 1, SettTimeoutEditor_GetDays( $timeItemTm ) % 31 + 1 ) -> setTime( SettTimeoutEditor_GetHours( $timeItemTm ), SettTimeoutEditor_GetMins( $timeItemTm ) );
		$operPrms = array( DateTime::FMT_YEAR, 'FromYears' );
		break;
	}

	$dtTest -> add( call_user_func( 'seraph_accel\\DateInterval::' . $operPrms[ 1 ], Gen::AlignNLowShift( $dtCurVals[ $operPrms[ 0 ] ], $periodN ) + $timeItemShift ) );

	$tmItem = $dtTest -> getTimestamp();
	if( $tmItem <= $tmCur )
		$tmItem = $dtTest -> add( call_user_func( 'seraph_accel\\DateInterval::' . $operPrms[ 1 ], $periodN ) ) -> getTimestamp();

	return( $tmItem );
}

function CacheInitOperScheduler( $force = false, $init = true )
{

	if( !$init )
	{
		Plugin::AsyncTaskDel( 'CacheNextScheduledOp', null, true );
		return;
	}

	if( !$force && Plugin::AsyncTaskGetTime( 'CacheNextScheduledOp', null, true ) )
		return;

	$dtCur = new \DateTime( 'now', DateTimeZone::FromOffset( Wp::GetGmtOffset() ) );
	$tmCur = $dtCur -> getTimestamp();
	$dtCurVals = DateTime::GetFmtVals( $dtCur, Wp::GetISOFirstWeekDay() );

	$tmNearest = 0;
	$aId = array();
	foreach( Gen::GetArrField( Plugin::SettGet(), array( 'cache', 'updSche' ), array() ) as $id => $item )
	{
		if( !($item[ 'enable' ]??null) )
			continue;

		$period = ($item[ 'period' ]??24);
		$periodN = ($item[ 'periodN' ]??0);
		$op = ($item[ 'op' ]??0);
		if( !$periodN )
			continue;

		foreach( Gen::GetArrField( $item, array( 'times' ), array() ) as $timeItem )
		{
			$tmItem = _CacheOperScheduler_ItemTime_GetNextRunTime( $timeItem, $dtCur, $dtCurVals, $tmCur, $period, $periodN );

			if( !$tmNearest )
				$tmNearest = $tmItem;
			else if( $tmItem < $tmNearest )
			{
				$tmNearest = $tmItem;
				$aId = array();
			}

			$aId[ $id ] = $op;
		}
	}

	if( !$tmNearest )
		$tmNearest = time() + 60 * 60 * 24;

	Plugin::AsyncTaskPost( 'CacheNextScheduledOp', array( 'aId' => $aId ), array( $tmNearest, 2 * 60 * 60 ), false, true );

}

function OnAsyncTask_CacheNextScheduledOp( $args )
{
	$aId = Gen::GetArrField( $args, array( 'aId' ), array() );
	if( !$aId )
		return;

	CacheInitOperScheduler();

	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	PluginFileValues::Set( 'schu', true );

	$cbIsAborted =
		function()
		{
			return( PluginFileValues::Get( 'schu' ) === null );
		};

	$settSche = Gen::GetArrField( Plugin::SettGet(), array( 'cache', 'updSche' ), array() );

	$aOp = array();
	foreach( $aId as $id => $op )
	{
		$prior = Gen::GetArrField( $settSche, array( $id, 'prior' ), 7 );
		$deps = Gen::GetArrField( $settSche, array( $id, 'deps' ), array() );

		if( $deps )
		{
			foreach( $deps as $url )
				$aOp[ $op ][ $prior ][] = $url;
		}
		else
			$aOp[ $op ][ $prior ] = true;
	}

	foreach( $aOp as $op => $aPrior )
	{
		foreach( $aPrior as $prior => $urls )
		{
			if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
			{
				$txt = '';
				switch( $op )
				{
				case 0:		$txt .= 'Scheduled revalidation'; break;
				case 3:	$txt .= 'Scheduled revalidation if needed'; break;
				case 2:				$txt .= 'Scheduled deleting'; break;
				case 10:			$txt .= 'Scheduled server cache clearing'; break;
				}

				if( $urls === true )
					$txt .= '; scope: all';
				else
					$txt .= '; scope: URL(s): ' . implode( ', ', $urls );

				LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
			}

			if( $urls === true )
			{
				if( CacheOp( $op, $prior, null, null, $cbIsAborted ) === false )
					break;
			}
			else if( CacheOpUrls( true, $urls, $op, $prior, $cbIsAborted ) === false )
				break;
		}
	}

	PluginFileValues::Del( 'schu' );
}

function GetStatusData( $siteId )
{
	$dtCurLoc = new \DateTime( 'now', DateTimeZone::FromOffset( Wp::GetGmtOffset() ) );

	$info = array();

	{
		$nProcessing = PluginFileValues::Get( 'up' );
		$info[ 'postUpd' ] = $nProcessing !== null;

		$nPost = $nProcessing !== null ? $nProcessing : 0;
		foreach( array( 'uppq', 'upq' ) as $dirQueue )
		{
			$dirQueue = GetCacheDir() . '/' . $dirQueue . '/' . $siteId;

			$lock = new Lock( 'l', $dirQueue );
			if( $lock -> Acquire() )
			{
				$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
				$nPost += $a -> count();
				$a -> dispose(); unset( $a );

				$lock -> Release();
			}
			unset( $lock );
		}

		$info[ 'cont' ][ 'postUpd' ] = sprintf( Wp::SanitizeHtml( _nx( 'PostUpdDscr_%1$s', 'PostUpdDscr_%1$s', $nPost, 'admin.Manage_Status', 'seraphinite-accelerator' ) ), $nPost );
	}

	{
		$isRunning = PluginFileValues::Get( 'schu' ) !== null;
		$info[ 'scheUpd' ] = $isRunning;

		$tmNextRun = Plugin::AsyncTaskGetTime( 'CacheNextScheduledOp', null, function( $args, $argsPrev ) { return( Gen::GetArrfield( $argsPrev, array( 'aId' ), array() ) ? $argsPrev : null ); } );
		$info[ 'cont' ][ 'scheUpd' ] = $isRunning ? Wp::SanitizeHtml( _x( 'ScheUpdRunningDscr', 'admin.Manage_Status', 'seraphinite-accelerator' ) ) : sprintf( Wp::SanitizeHtml( _x( 'ScheUpdDscr_%1$s', 'admin.Manage_Status', 'seraphinite-accelerator' ) ), $tmNextRun ? date_i18n( DateTime::RFC2822, $tmNextRun + $dtCurLoc -> getOffset() ) : Wp::GetLocString( 'None' ) );
	}

	{
		$isRunning = !!CacheGetCurOp( 1 );
		$info[ 'cleanUp' ] = CacheGetCurOp( 1 );

		$tmNextRun = Plugin::AsyncTaskGetTime( 'CacheClearPeriodically', null, true );
		$info[ 'cont' ][ 'cleanUp' ] = $isRunning ? Wp::SanitizeHtml( _x( 'CleanUpRunningDscr', 'admin.Manage_Status', 'seraphinite-accelerator' ) ) : sprintf( Wp::SanitizeHtml( _x( 'CleanUpDscr_%1$s', 'admin.Manage_Status', 'seraphinite-accelerator' ) ), $tmNextRun ? date_i18n( DateTime::RFC2822, $tmNextRun + $dtCurLoc -> getOffset() ) : Wp::GetLocString( 'None' ) );
	}

	{
		$loadAvgCont = GetLoadAvg( null );
		$info[ 'cont' ][ 'loadAvg' ] = ( $loadAvgCont !== null ) ? ( ( string )$loadAvgCont . '%' ) : '-';
	}

	{
		$aDbFileTm = array();

		$svc = ExtDb_GetWooMaxMindGeolocationSvc();
		$dbIP2CFile = $svc ? $svc -> get_database_service() -> get_database_path() : null;

		if( $dbIP2CFile )
		{
			$tmNextRun = wp_get_scheduled_event( 'woocommerce_geoip_updater' );
			if( $tmNextRun )
				$tmNextRun = $tmNextRun -> timestamp + ($tmNextRun -> interval??0);
		}
		else
			$tmNextRun = Plugin::AsyncTaskGetTime( 'ExtDbUpdSche', null, true );

		if( !$dbIP2CFile )
			$dbIP2CFile = GetCacheDir() . '/db/mm/ip2c.mmdb';

		$aDbFileTm[ 'GeoIP (MaxMind)' ] = Images_ProcessSrcEx_FileMTime( $dbIP2CFile );
		$aDbFileTm[ 'GeoIP (MaxMind-C2IP)' ] = Images_ProcessSrcEx_FileMTime( GetCacheDir() . '/db/mm/c2ip-v1.dat' );

		$aDbFileTmDisp = array();
		foreach( $aDbFileTm as $dbId => $dbFileTm )
			$aDbFileTmDisp[] = sprintf( Plugin::GetPluginString( 'NameToDetails_%1$s%2$s' ), Ui::Tag( 'strong', $dbId ), $dbFileTm ? date_i18n( DateTime::RFC2822, $dbFileTm ) : esc_html_x( 'GeoDbNone', 'admin.Manage_Status', 'seraphinite-accelerator' ) );

		$info[ 'cont' ][ 'extDb' ] = implode( Plugin::GetPluginString( 'ListTokenSep' ), $aDbFileTmDisp ) . '<br>' . sprintf( Wp::SanitizeHtml( _x( 'ScheExtDbUpdDscr_%1$s', 'admin.Manage_Status', 'seraphinite-accelerator' ) ), $tmNextRun ? date_i18n( DateTime::RFC2822, $tmNextRun + $dtCurLoc -> getOffset() ) : Wp::GetLocString( 'None' ) );
	}

	return( $info );
}

function GetStatCont( $siteId, $info = null )
{
	if( !is_array( $info ) || ($info[ 'v' ]??null) != PLUGIN_STAT_VER )
		$info = null;

	$isPaidLockedContent = false;

	$res = Ui::TableCells(
		array(
			function( $args )
			{
				extract( $args );
				return( array( esc_html_x( 'ObjCountLbl', 'admin.Manage_Stat', 'seraphinite-accelerator' ), Ui::Label( $info ? ( string )$info[ 'nObj' ] : '-' ) ) );
			},

			function( $args )
			{
				extract( $args );
				return( array( esc_html_x( 'DataObjCountLbl', 'admin.Manage_Stat', 'seraphinite-accelerator' ), Ui::Label( $info && isset( $info[ 'nDataObj' ] ) ? ( string )$info[ 'nDataObj' ] : '-' ) ) );
			},

			function( $args )
			{
				extract( $args );
				return( array( esc_html_x( 'CacheObjCountLbl', 'admin.Manage_Stat', 'seraphinite-accelerator' ), Ui::Label( sprintf( esc_html_x( 'CountSizeVal_%1$s%2$s', 'admin.Manage_Stat', 'seraphinite-accelerator' ), $info && isset( $info[ 'nCacheObj' ] ) ? ( string )$info[ 'nCacheObj' ] : '-', $info && isset( $info[ 'sizeCacheObj' ] ) ? size_format( $info[ 'sizeCacheObj' ], 1 ) : '-' ) ) ) );
			},

			function( $args )
			{
				extract( $args );
				return( array( esc_html_x( 'FileCountLbl', 'admin.Manage_Stat', 'seraphinite-accelerator' ), Ui::Label( $info && isset( $info[ 'nFile' ] ) ? ( string )$info[ 'nFile' ] : '-' ) ) );
			},

			function( $args )
			{
				extract( $args );
				return( array( esc_html_x( 'UsedSpaceLbl', 'admin.Manage_Stat', 'seraphinite-accelerator' ), Ui::Label( $info ? size_format( $info[ 'size' ], 1 ) : '-' ) ) );
			},

		)
	, array( 'info' => $info, 'isPaidLockedContent' => $isPaidLockedContent ), 3 );

	return( $res );
}

function OnAsyncTask_UpdateStat( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$siteId = GetSiteId();

	if( PluginFileValues::Get( 'su' ) )
		return;

	PluginFileValues::Set( 'su', true );

	$settCacheGlobal = Gen::GetArrField( Plugin::SettGetGlobal(), array( 'cache' ), array() );

	$ctx = new AnyObj();
	$ctx -> procWorkInt = ($settCacheGlobal[ 'procWorkInt' ]??null);
	$ctx -> procPauseInt = ($settCacheGlobal[ 'procPauseInt' ]??null);
	$ctx -> isAborted =
		function( $ctx )
		{
			return( !Gen::SliceExecTime( $ctx -> procWorkInt, $ctx -> procPauseInt, 5,
				function()
				{
					return( !PluginFileValues::Get( 'su' ) );
				}
			) );
		};

	$info = GetCacheStatusInfo( $siteId, array( $ctx, 'isAborted' ) );
	if( $info )
		$info[ 'v' ] = PLUGIN_STAT_VER;

	update_option( 'seraph_accel_status', $info, false );

	PluginFileValues::Del( 'su' );
}

function OnAdminApi_UpdateStatBegin( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	return( Plugin::AsyncTaskPost( 'UpdateStat' ) );
}

function OnAdminApi_UpdateStatCancel( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	return( PluginFileValues::Del( 'su' ) );
}

function PostUpdCancelEx( $siteId )
{
	$dirFileValues = PluginFileValues::GetDirVar( $siteId );

	foreach( array( 'uppq', 'upq' ) as $dirQueue )
	{
		$dirQueue = GetCacheDir() . '/' . $dirQueue . '/' . $siteId;

		$lock = new Lock( 'l', $dirQueue );
		if( !$lock -> Acquire() )
			return( Gen::E_FAIL );

		$a = new ArrayOnFiles( $dirQueue . '/*.dat.gz' );
		$nPost = $a -> clear();
		$a -> dispose(); unset( $a );

		$lock -> Release();
		unset( $lock );
	}

	PluginFileValues::DelEx( $dirFileValues, 'up' );
	return( Gen::S_OK );
}

function PostUpdCancel( $siteId = null )
{
	foreach( ( $siteId ? array( $siteId ) : GetSiteIds() ) as $siteIdEnum )
		PostUpdCancelEx( $siteIdEnum );
}

function OnAdminApi_PostUpdCancel( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );
	return( PostUpdCancelEx( GetSiteId() ) );
}

function OnAdminApi_ScheUpdCancel( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	return( PluginFileValues::Del( 'schu' ) );
}

function OnAsyncTask_ExtDbUpd( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	ExtDbUpd();
}

function OnAsyncTask_ExtDbUpdSche( $args )
{
	OnAsyncTask_ExtDbUpd( $args );
}

function OnAdminApi_ExtDbUpdBegin( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	return( Plugin::AsyncTaskPost( 'ExtDbUpd' ) );
}

function OnAdminApi_ExtDbUpdCancel( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	return( PluginFileValues::Del( 'edbu' ) );
}

function GetViewDisplayNameById( $viewId )
{
	switch( $viewId )
	{
	case 'mobilehighres':	return( esc_html_x( 'ViewMobileHighResTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' ) );
	case 'mobilelowres':	return( esc_html_x( 'ViewMobileLowResTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' ) );
	case 'mobile':			return( esc_html_x( 'ViewMobileTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' ) );
	}

	return( '' );
}

function IsViewsEnabled( $sett )
{
	$settCache = Gen::GetArrField( $sett, array( 'cache' ), array() );

	if( !($settCache[ 'views' ]??null) )
		return( false );

	foreach( Gen::GetArrField( $settCache, array( 'viewsDeviceGrps' ), array() ) as $viewsGrp )
		if( ($viewsGrp[ 'enable' ]??null) )
			return( true );

	return( false );
}

function GetViewDisplayName( $viewName, $isViewsEnabled, $itemType = 0 )
{
	$viewId = is_string( $viewName ) ? strpos( $viewName, 'id:' ) : false;
	if( $viewId === 0 )
	{
		$viewName = GetViewDisplayNameById( substr( $viewName, 3 ) );
		if( !$viewName )
			$viewName = esc_html_x( 'ViewOtherTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' );
	}
	else if( !$viewName )
		$viewName = ( $isViewsEnabled && $itemType == 0 ) ? esc_html_x( 'ViewComonTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' ) : esc_html_x( 'ViewComonSingleTxt', 'admin.Manage_Queue', 'seraphinite-accelerator' );

	return( $viewName );
}

function GetViewsList( $sett, $bActiveOnly = false )
{
	$isViewsEnabled = IsViewsEnabled( $sett );
	$aViews = array( 'cmn' => array( 'name' => GetViewDisplayName( '', $isViewsEnabled ) ) );
	foreach( Gen::GetArrField( $sett, array( 'cache', 'viewsDeviceGrps' ), array() ) as $viewsDeviceGrp )
	{
		if( $bActiveOnly && !($viewsDeviceGrp[ 'enable' ]??null) )
			continue;

		$aViews[ $viewsDeviceGrp[ 'id' ] ] = array( 'name' => GetViewDisplayName( GetViewDeviceGrpNameFromData( $viewsDeviceGrp ), $isViewsEnabled ) );
	}

	return( $aViews );
}

function GetGeosList( $sett )
{
	if( !Gen::GetArrField( $sett, array( 'cache', 'viewsGeo', 'enable' ), false ) )
		return( array() );

	$aGeos = array( '' => array( 'name' => null ) );

	$aRegionsIp = GetRegion2IPMap();

	$aGrp = Gen::GetArrField( $sett, array( 'cache', 'viewsGeo', 'grps' ), array() );

	$grpIsFirst = true;
	foreach( $aGrp as $grpId => $grp )
	{
		if( !($grp[ 'enable' ]??null) )
			continue;

		$viewGeoId = $grpId;
		if( $grpIsFirst )
		{
			$grpItem = Gen::ArrGetByPos( Gen::GetArrField( $grp, array( 'items' ), array() ), 0 );
			if( ExprConditionsSet_IsTrivial( ExprConditionsSet_Parse( $grpItem ) ) && isset( $aRegionsIp[ $grpItem ] ) )
				$viewGeoId = '';
		}

		$viewGeoName = ( string )($grp[ 'name' ]??'');
		if( !strlen( $viewGeoName ) )
			$viewGeoName = $viewGeoId;

		$aGeos[ $viewGeoId ] = array( 'name' => $viewGeoName );

		$grpIsFirst = false;
	}

	if( !isset( $aGeos[ '' ][ 'name' ] ) )
	{
		$ipHost = gethostbyname( Gen::GetArrField( Net::UrlParse( Wp::GetSiteRootUrl() ), array( 'host' ), '' ) );
		$regId = GetCountryCodeByIp( Gen::GetArrField( $sett, array( 'cache' ), array() ), $ipHost );
		$aGeos[ '' ][ 'name' ] = $regId;

		unset( $aRegionsIp[ $regId ], $ipHost );
	}

	foreach( $aRegionsIp as $regId => $regIP )
	{
		$matched = false;
		foreach( $aGrp as $grpId => $grp )
		{
			if( !($grp[ 'enable' ]??null) )
				continue;

			foreach( Gen::GetArrField( $grp, array( 'items' ), array() ) as $grpItem )
			{
				if( !DoesViewGeoGrpItemMatchEx( ExprConditionsSet_Parse( $grpItem ), $regId ) )
					continue;

				$matched = true;
				break;
			}

			if( $matched )
				break;
		}

		if( !$matched )
			$aGeos[ $regId ] = array( 'name' => $regId );
	}

	return( $aGeos );
}

function GetUserDisplayName( $sessId )
{
	$sessId = explode( '/', $sessId );
	if( count( $sessId ) !== 2 )
		return( '' );

	$sessId[ 0 ] = ( int )$sessId[ 0 ];
	if( !$sessId[ 0 ] )
		return( ( string )$sessId[ 0 ] . ( $sessId[ 1 ] != '@' ? ( '/' . $sessId[ 1 ] ) : '' ) );

	$user = wp_cache_get( $sessId[ 0 ], 'users' );
	if( !$user )
		$user = get_userdata( $sessId[ 0 ] );

	if( !$user )
		return( ( string )$sessId[ 0 ] . ( $sessId[ 1 ] != '@' ? ( '/' . $sessId[ 1 ] ) : '' ) );

	return( $user -> display_name );
}

function GetGeoDisplayName( $sett, $geoId )
{
	$grps = Gen::GetArrField( $sett, array( 'cache', 'viewsGeo', 'grps' ), array() );

	if( !$geoId )
		$grp = Gen::ArrGetByPos( $grps, 0 );
	else
		$grp = Gen::GetArrField( $grps, array( $geoId ), array() );

	if( $grp )
	{
		$name = ($grp[ 'name' ]??null);
		return( $name ? $name : $geoId );
	}

	return( $geoId );
}

function MsgUnpackLocIds( $v )
{
	return( LocId::UnPack( $v,
		function( $id, $comp )
		{
			$txt = _x( $id, 'admin.' . ( $comp ? ( $comp . '_' ) : '' ) . 'Msg', 'seraphinite-accelerator' );
			if( !$txt || $txt == $id )
				$txt = _x( $id, $comp, 'seraphinite-accelerator' );
			return( $txt );
		}
	) );

	esc_html_x( 'ImgConvertUnsupp', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'ImgConvertFile_%1$s%2$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'ImgConvertFileErr_%1$s%2$s%3$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'ImgAdaptFile_%1$s%2$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'DataComprUnsupp_%1$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'DataComprErr_%1$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CacheExtImgErr_%1$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'RequestHeadersTrace_%1$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssUrlWrongType_%1$s%2$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssParseTrace_%1$s%2$s%3$s%4$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssParseSelTrace_%1$s%2$s%3$s%4$s%5$s%6$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssParseTrace_ErrHigh', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssParseTrace_ErrMed', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'CssParseTrace_ErrLow', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'JsUrlWrongType_%1$s%2$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'NameToDetails_%1$s%2$s', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'HdrsForRequest', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'SrvArgs', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'ProcStat', 'admin.Msg', 'seraphinite-accelerator' );
	esc_html_x( 'TimeDurSec_%1$s', 'admin.Msg', 'seraphinite-accelerator' );
}

function OnAsyncTask_CacheRevalidateAll( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$op = Gen::GetArrField( $args, array( 'op' ), 0 );

	if( CacheOp( $op, 100 ) )
		Plugin::StateUpdateFlds( array( 'settChangedUpdateCache' => null ) );
}

function OnAsyncTask_CacheOp( $args )
{
	Gen::SetTimeLimit( 1800 );
	Gen::GarbageCollectorEnable( false );

	$op = Gen::GetArrField( $args, array( 'op' ), 0 );
	$type = Wp::SanitizeId( Gen::GetArrField( $args, array( 'type' ), '' ) );

	$res = false;
	switch( $type )
	{
	case 'uri':
		$urls = Gen::GetArrField( $args, array( 'uri' ), array() );
		if( !$urls )
			$urls = array( Wp::GetSiteRootUrl() );

		$res = CacheOpUrls( true, $urls, $op, 0, true, null, Gen::GetArrField( $args, array( 'v' ) ), Gen::GetArrField( $args, array( 'g' ) ), Gen::GetArrField( $args, array( 'u' ), 0 ) );
		break;

	default:
		if( ( $res = CacheOp( $op, 100, Gen::GetArrField( $args, array( 'v' ) ), Gen::GetArrField( $args, array( 'g' ) ) ) ) && $op != 1 )
			Plugin::StateUpdateFlds( array( 'settChangedUpdateCache' => null ) );
		break;
	}

}

function OnAdminApi_CacheOpBegin( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );

	$args[ 'uri' ] = array_map( 'trim', explode( ";", str_replace( array( '{ASTRSK}' ), array( '*' ), Gen::GetArrField( $args, array( 'uri' ), '' ) ) ) );
	$args[ 'op' ] = @intval( ($args[ 'op' ]??'0') );

	if( isset( $args[ 'v' ] ) )
	{
		if( strlen( $args[ 'v' ] ) )
			$args[ 'v' ] = explode( ',', $args[ 'v' ] );
		else
			unset( $args[ 'v' ] );
	}

	if( isset( $args[ 'g' ] ) )
		$args[ 'g' ] = explode( ',', $args[ 'g' ] );

	if( $args[ 'op' ] == 10 )
		CacheExt_ClearOnExtRequest( Gen::GetArrField( $args, array( 'type' ), '' ) == 'uri' ? ($args[ 'uri' ][ 0 ]??'') : null );

	if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
	{
		$txt = '';
		switch( $args[ 'op' ] )
		{
		case 0:		$txt .= 'Manual revalidation'; break;
		case 3:	$txt .= 'Manual revalidation if needed'; break;
		case 2:				$txt .= 'Manual deleting'; break;
		case 1:			$txt .= 'Manual cleaning up old'; break;
		case 10:			$txt .= 'Manual deleting of server\'s cache'; break;
		}

		$txt .= '; scope: ';

		switch( Gen::GetArrField( $args, array( 'type' ), '' ) )
		{
		case 'uri':
			$txt .= 'URL(s): ' . implode( ', ', $args[ 'uri' ] );
			break;

		default:
			$txt .= 'all';
			break;
		}

		LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
	}

	return( Plugin::AsyncTaskPost( 'CacheOp', $args ) );
}

function OnAdminApi_CacheOpCancel( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( Gen::E_ACCESS_DENIED );
	return( CacheOpCancel( @intval( ($args[ 'op' ]??'0') ) ) );
}

function _HtmlCheck_NrmUrlForCheck( $url )
{
	$a = Net::UrlParse( $url );
	if( !$a )
		return( $url );

	$a[ 'path' ] = Gen::SetLastSlash( ($a[ 'path' ]??'') );
	return( Net::UrlDeParse( $a, Net::URLPARSE_F_PRESERVEEMPTIES, array( PHP_URL_SCHEME, PHP_URL_USER, PHP_URL_PASS ) ) );
}

function OnAdminApi_HtmlCheck( $args )
{
	if( !current_user_can( 'manage_options' ) )
		return( array( 'err' => 'access_denied' ) );

	Gen::SetTimeLimit( 300 );
	Gen::GarbageCollectorEnable( false );

	$url = Wp::SanitizeUrl( ($args[ 'url' ]??null) );
	if( strpos( $url, '//' ) === 0 )
		$url = 'http:' . $url;
	else if( strpos( $url, '://' ) === false )
		$url = 'http://' . $url;

	if( !Gen::StrStartsWith( _HtmlCheck_NrmUrlForCheck( $url ), _HtmlCheck_NrmUrlForCheck( Wp::GetSiteRootUrl() ) ) )
		return( array( 'err' => 'access_denied' ) );

	$requestRes = Wp::RemoteGet( $url, array( 'timeout' => 15, 'sslverify' => false ) );
	if( is_wp_error( $requestRes ) )
		return( array( 'err' => $requestRes -> get_error_message() ) );

	$validationErrors = array();

	if( !$validationErrors )
		$validationErrors[] = array( 'severity' => 'success', 'text' => esc_html_x( 'Ok', 'admin.Manage_HtmlChecker_Msg', 'seraphinite-accelerator' ) );

	return( array( 'err' => '', 'list' => $validationErrors ) );
}

function OnAdminApi_LogClear( $args )
{
	Gen::LogClear( GetCacheDir() . LogGetRelativeFile(), true );
}

class API
{
	const CACHE_OP_REVALIDATE = 0;
	const CACHE_OP_CHECK_REVALIDATE = 3;
	const CACHE_OP_CLEAR = 1;
	const CACHE_OP_DEL = 2;
	const CACHE_OP_SRVDEL = 10;

	static function OperateCache( $op = API::CACHE_OP_DEL, $obj = null, $viewId = null, $userId = null, $geoId = null )
	{
		$args = array( 'uri' => ( array )$obj, 'op' => $op, 'type' => $obj ? 'uri' : '' );
		if( $viewId )
		    $args[ 'v' ] = $viewId;
		if( $geoId !== null )
		    $args[ 'g' ] = $geoId;
		if( $userId )
			$args[ 'u' ] = $userId;

		if( Gen::GetArrField( Plugin::SettGet(), array( 'log' ), false ) && Gen::GetArrField( Plugin::SettGet(), array( 'logScope', 'upd' ), false ) )
		{
			$txt = '';
			switch( $args[ 'op' ] )
			{
			case 0:		$txt .= 'API revalidation'; break;
			case 3:	$txt .= 'API revalidation if needed'; break;
			case 2:				$txt .= 'API deleting'; break;
			case 1:			$txt .= 'API cleaning up old'; break;
			case 10:			$txt .= 'API deleting of server\'s cache'; break;
			}

			$txt .= '; scope: ';

			switch( Gen::GetArrField( $args, array( 'type' ), '' ) )
			{
			case 'uri':
				$txt .= 'URL(s): ' . implode( ', ', $args[ 'uri' ] );
				break;

			default:
				$txt .= 'all';
				break;
			}

			LogWrite( $txt, Ui::MsgInfo, 'Cache update' );
		}

		return( Plugin::AsyncTaskPost( 'CacheOp', $args ) );
	}

	static function GetCacheStatus( $obj, $headers = array() )
	{
		global $seraph_accel_sites;

		$obj = Net::UrlParse( $obj, Net::URLPARSE_F_QUERY | Net::URLPARSE_F_PRESERVEEMPTIES );
		if( !$obj )
			return( null );

		$userAgent = ($headers[ 'User-Agent' ]??'');

		$sett = Plugin::SettGet();
		$settCache = Gen::GetArrField( $sett, array( 'cache' ), array() );

		$pathOrig = Gen::GetArrField( $obj, array( 'path' ), '' );
		$path = CachePathNormalize( $pathOrig, $pathIsDir );
		$args = Gen::GetArrField( $obj, array( 'query' ), array() );
		$addrSite = GetRequestHost( array( 'SERVER_NAME' => Gen::GetArrField( $obj, array( 'host' ), '' ), 'SERVER_PORT' => Gen::GetArrField( $obj, array( 'port' ) ) ) );
		$siteId = GetCacheSiteIdAdjustPath( $seraph_accel_sites, $addrSite, $siteSubId, $path );
		if( $siteId === null )
			return( array( 'err' => 'siteIdUnk' ) );

		$ctxCache = new AnyObj();

		$userId = 0;
		$sessId = null;
		$viewId = GetCacheViewId( $ctxCache, $settCache, $userAgent, $path, $pathOrig, $args );
		$cacheRootPath = GetCacheDir();
		$siteCacheRootPath = $cacheRootPath . '/s/' . $siteId;
		$ctxCache -> viewPath = GetCacheViewsDir( $siteCacheRootPath, $siteSubId ) . '/' . $viewId;
		$ctxsPath = $ctxCache -> viewPath . '/c';

		{
			$ctxCache -> userId = $userId;
			$ctxCache -> userSessId = null;
			$sessId = '@';
			$ctxCache -> isUserSess = false;

			$ctxPathId = $userId . '/s/' . $sessId;
			$stateCookId = '@';
			$ctxPathId .= '/s/' . $stateCookId;
		}

		$objectId = '@';
		if( $pathIsDir )
			$objectId .= 'd';

		if( !empty( $args ) )
		{
			$argsCumulative = '';
			foreach( $args as $argKey => $argVal )
				$argsCumulative .= $argKey . $argVal;

			$objectId = $objectId . '.' . @md5( $argsCumulative );
			unset( $argsCumulative );
		}

		$dataPath = GetCacheDataDir( $siteCacheRootPath );

		$dscFile = $ctxsPath . '/' . $ctxPathId . '/o';
		if( $path )
			$dscFile .= '/' . $path;
		$dscFile .= '/' . $objectId . '.html.dat';
		$dscFilePending = $dscFile . '.p';
		$dscFilePending2 = $dscFilePending . 'p';

		$res = array( 'dscFile' => substr( $dscFile, strlen( $cacheRootPath ) ) );

		$dscFileTm = @filemtime( $dscFile );
		if( $dscFileTm === false )
		{
			$res[ 'cache' ] = false;
			$dsc = null;
		}
		else
		{
			$res[ 'cache' ] = true;
			$dsc = CacheReadDsc( $dscFile );
		}

		$res[ 'optimization' ] = $dsc ? ( isset( $dsc[ 't' ] ) ? false : true ) : null;
		$res[ 'status' ] = @file_exists( $dscFilePending2 ) ? 'revalidating' : ( @file_exists( $dscFilePending ) ? 'pending' : ( $dscFileTm === false ? 'none' : 'done' ) );

		return( $res );
	}
}

