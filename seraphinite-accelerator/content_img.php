<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

function _Image_GetAiFileId( $aiFileId = null )
{
	if( !$aiFileId )
		return( null );

	if( preg_match( '@^([\\w\\-]+)/ai/([\\w\\-]+)@', $aiFileId, $m ) )
		$aiFileId = $m[ 1 ] . '.' . $m[ 2 ];
	else
		$aiFileId = null;

	return( $aiFileId );
}

function Image_MakeOwnRedirUrlArgsEx( $path, $aiFileId, $nonce = null )
{
	return( array( 'seraph_accel_gi' => $path, 'ai' => $aiFileId, 'n' => $nonce ) );
}

function Image_MakeOwnRedirUrlArgs( $path, $aiFileId = null )
{

	$path = Gen::GetNormalizedPath( $path );
	return( Image_MakeOwnRedirUrlArgsEx( $path, _Image_GetAiFileId( $aiFileId ), Gen::GetNonce( $path, GetSalt() ) ) );
}

function Image_MakeAiUrlArgs( $args, $path, $aiFileId )
{

	$path = Gen::GetNormalizedPath( $path );

	return( array_merge( ( array )$args, array( 'seraph_accel_ai' => _Image_GetAiFileId( $aiFileId ), 'n' => Gen::GetNonce( $path, GetSalt() ) ) ) );
}

