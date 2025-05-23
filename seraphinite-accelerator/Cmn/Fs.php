<?php

namespace seraph_accel;

if( !defined( 'ABSPATH' ) )
	exit;

class Fs
{
	const MimeTypeDef		= '';
	const BufSizeDef		= 4096;

	static function StreamOutFileContent( $fileName, $mimeType = Fs::MimeTypeDef, $bHeadOnly = false, $bufSize = Fs::BufSizeDef, $asAttachment = false, $nTtl = 0, $bNotMdf = true )
	{
		$size = @filesize( $fileName );
		$time = @filemtime( $fileName );

		if( $size === false || $time === false )
		{
			http_response_code( 404 );
			return;
		}

		if( empty( $mimeType ) )
			$mimeType = Fs::GetMimeContentType( $fileName );

		$begin = 0;
		$end = $size;

		$isRange = false;
		if( isset( $_SERVER[ 'HTTP_RANGE' ] ) )
		{
			$range = str_replace( ' ', '', $_SERVER[ 'HTTP_RANGE' ] );
			$pos = strpos( $range, 'bytes=' );
			if( is_int( $pos ) )
			{
				$isRange = true;

				$range = substr( $range, $pos + strlen( 'bytes=' ) );

				$pos = strpos( $range, '-' );
				if( is_int( $pos ) )
				{
					$begin = intval( substr( $range, 0, $pos ) );
					$range = substr( $range, $pos + 1 );
					if( strlen( $range ) )
						$end = intval( $range );
				}
				else
					$begin = intval( $range );
			}
		}

		header( 'Content-Type: ' . $mimeType );
		header( 'Cache-Control: public, ' . ( $nTtl ? ( 'max-age=' . $nTtl ) : 'must-revalidate, max-age=0' ) );
		if( !$nTtl )
			header( 'Pragma: no-cache' );
		header( 'Accept-Ranges: bytes' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $time ) . ' GMT' );

		if( $isRange )
			header( 'Content-Range: bytes ' . $begin . '-' . $end . '/' . $size );

		if( $asAttachment )
			header( 'Content-Disposition: attachment;filename="' . basename( $fileName ) . '"' );

		if( $bNotMdf && isset( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) )
		{
			if( strtotime( preg_replace( '@;.*$@', '', $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) ) == $time )
			{
				http_response_code( 304 );
				return;
			}
		}

		$file = @fopen( $fileName, 'rb' );
		if( !$file )
		{
			http_response_code( 599 );
			return;
		}

		if( $isRange )
			http_response_code( 206 );
		else
			http_response_code( 200 );

		if( !$bHeadOnly )
		{
			header( 'Content-Length: ' . ( $end - $begin ) );

			if( $isRange )
			{
				$cur = $begin;
				if( $begin )
					@fseek( $file, $begin, 0 );

				$fileOut = @fopen( 'php://output', 'w' );
				if( !$fileOut )
				{
					@fclose( $file );

					http_response_code( 599 );
					return;
				}

				while( !@feof( $file ) && $cur < $end && ( @connection_status() == 0 ) )
				{
					$nRead = min( $bufSize, $end - $cur );
					@fwrite( $fileOut, @fread( $file, $nRead ) );
					$cur += $nRead;
				}

				@fclose( $fileOut );
			}
			else
				@readfile( $fileName );
		}

		@fclose( $file );
	}

	static function StreamCopy( $stmSrc, $stmDst, $bufSize = 4096 )
	{
		while( !@feof( $stmSrc ) )
		{
			$nRead = $bufSize;
			$buf = @fread( $stmSrc, $nRead );
			if( $buf === false )
				return( Gen::E_INTERNAL );

			if( @fwrite( $stmDst, $buf, $nRead ) === false )
				return( Gen::E_INTERNAL );
		}

		return( Gen::S_OK );
	}

	static function CreateEmptyFile( $filePathName, $overwrite = true )
	{
		$f = @fopen( $filePathName, $overwrite ? 'wb' : 'xb' );
		if( $f === false )
			return( Gen::E_INTERNAL );

		@fclose( $f );
		return( Gen::S_OK );
	}

	static $mime_types = array(
		'ai'      => 'application/postscript',
		'aif'     => 'audio/x-aiff',
		'aifc'    => 'audio/x-aiff',
		'aiff'    => 'audio/x-aiff',
		'asc'     => 'text/plain',
		'asf'     => 'video/x-ms-asf',
		'asx'     => 'video/x-ms-asf',
		'au'      => 'audio/basic',
		'avi'     => 'video/x-msvideo',
		'avif'    => 'image/avif',
		'bcpio'   => 'application/x-bcpio',
		'bmp'     => 'image/bmp',
		'bz2'     => 'application/x-bzip2',
		'cdf'     => 'application/x-netcdf',
		'chrt'    => 'application/x-kchart',
		'class'   => 'application/octet-stream',
		'cpio'    => 'application/x-cpio',
		'cpt'     => 'application/mac-compactpro',
		'csh'     => 'application/x-csh',
		'css'     => 'text/css',
		'dcr'     => 'application/x-director',
		'dir'     => 'application/x-director',
		'djv'     => 'image/vnd.djvu',
		'djvu'    => 'image/vnd.djvu',
		'dll'     => 'application/octet-stream',
		'dms'     => 'application/octet-stream',
		'doc'     => 'application/msword',
		'dvi'     => 'application/x-dvi',
		'dxr'     => 'application/x-director',
		'eps'     => 'application/postscript',
		'etx'     => 'text/x-setext',
		'exe'     => 'application/octet-stream',
		'ez'      => 'application/andrew-inset',
		'flv'     => 'video/x-flv',
		'gif'     => 'image/gif',
		'gtar'    => 'application/x-gtar',
		'gz'      => 'application/x-gzip',
		'hdf'     => 'application/x-hdf',
		'hqx'     => 'application/mac-binhex40',
		'htm'     => 'text/html',
		'html'    => 'text/html',
		'ice'     => 'x-conference/x-cooltalk',
		'ief'     => 'image/ief',
		'iges'    => 'model/iges',
		'igs'     => 'model/iges',
		'img'     => 'application/octet-stream',
		'iso'     => 'application/octet-stream',
		'jad'     => 'text/vnd.sun.j2me.app-descriptor',
		'jar'     => 'application/x-java-archive',
		'jnlp'    => 'application/x-java-jnlp-file',
		'jpe'     => 'image/jpeg',
		'jpeg'    => 'image/jpeg',
		'jpg'     => 'image/jpeg',
		'js'      => 'application/javascript',
		'json'    => 'application/json',
		'kar'     => 'audio/midi',
		'kil'     => 'application/x-killustrator',
		'kpr'     => 'application/x-kpresenter',
		'kpt'     => 'application/x-kpresenter',
		'ksp'     => 'application/x-kspread',
		'kwd'     => 'application/x-kword',
		'kwt'     => 'application/x-kword',
		'latex'   => 'application/x-latex',
		'lha'     => 'application/octet-stream',
		'lzh'     => 'application/octet-stream',
		'm3u'     => 'audio/x-mpegurl',
		'man'     => 'application/x-troff-man',
		'me'      => 'application/x-troff-me',
		'mesh'    => 'model/mesh',
		'mid'     => 'audio/midi',
		'midi'    => 'audio/midi',
		'mif'     => 'application/vnd.mif',
		'mov'     => 'video/quicktime',
		'movie'   => 'video/x-sgi-movie',
		'mp2'     => 'audio/mpeg',
		'mp3'     => 'audio/mpeg',
		'mpe'     => 'video/mpeg',
		'mpeg'    => 'video/mpeg',
		'mpg'     => 'video/mpeg',
		'mpga'    => 'audio/mpeg',
		'ms'      => 'application/x-troff-ms',
		'msh'     => 'model/mesh',
		'mxu'     => 'video/vnd.mpegurl',
		'nc'      => 'application/x-netcdf',
		'odb'     => 'application/vnd.oasis.opendocument.database',
		'odc'     => 'application/vnd.oasis.opendocument.chart',
		'odf'     => 'application/vnd.oasis.opendocument.formula',
		'odg'     => 'application/vnd.oasis.opendocument.graphics',
		'odi'     => 'application/vnd.oasis.opendocument.image',
		'odm'     => 'application/vnd.oasis.opendocument.text-master',
		'odp'     => 'application/vnd.oasis.opendocument.presentation',
		'ods'     => 'application/vnd.oasis.opendocument.spreadsheet',
		'odt'     => 'application/vnd.oasis.opendocument.text',
		'ogg'     => 'application/ogg',
		'otg'     => 'application/vnd.oasis.opendocument.graphics-template',
		'oth'     => 'application/vnd.oasis.opendocument.text-web',
		'otp'     => 'application/vnd.oasis.opendocument.presentation-template',
		'ots'     => 'application/vnd.oasis.opendocument.spreadsheet-template',
		'ott'     => 'application/vnd.oasis.opendocument.text-template',
		'pbm'     => 'image/x-portable-bitmap',
		'pdb'     => 'chemical/x-pdb',
		'pdf'     => 'application/pdf',
		'pgm'     => 'image/x-portable-graymap',
		'pgn'     => 'application/x-chess-pgn',
		'png'     => 'image/png',
		'pnm'     => 'image/x-portable-anymap',
		'ppm'     => 'image/x-portable-pixmap',
		'ppt'     => 'application/vnd.ms-powerpoint',
		'ps'      => 'application/postscript',
		'qt'      => 'video/quicktime',
		'ra'      => 'audio/x-realaudio',
		'ram'     => 'audio/x-pn-realaudio',
		'ras'     => 'image/x-cmu-raster',
		'rgb'     => 'image/x-rgb',
		'rm'      => 'audio/x-pn-realaudio',
		'roff'    => 'application/x-troff',
		'rpm'     => 'application/x-rpm',
		'rss'     => 'application/rss+xml',
		'rtf'     => 'text/rtf',
		'rtx'     => 'text/richtext',
		'sgm'     => 'text/sgml',
		'sgml'    => 'text/sgml',
		'sh'      => 'application/x-sh',
		'shar'    => 'application/x-shar',
		'silo'    => 'model/mesh',
		'sis'     => 'application/vnd.symbian.install',
		'sit'     => 'application/x-stuffit',
		'skd'     => 'application/x-koan',
		'skm'     => 'application/x-koan',
		'skp'     => 'application/x-koan',
		'skt'     => 'application/x-koan',
		'smi'     => 'application/smil',
		'smil'    => 'application/smil',
		'snd'     => 'audio/basic',
		'so'      => 'application/octet-stream',
		'spl'     => 'application/x-futuresplash',
		'src'     => 'application/x-wais-source',
		'stc'     => 'application/vnd.sun.xml.calc.template',
		'std'     => 'application/vnd.sun.xml.draw.template',
		'sti'     => 'application/vnd.sun.xml.impress.template',
		'stw'     => 'application/vnd.sun.xml.writer.template',
		'sv4cpio' => 'application/x-sv4cpio',
		'sv4crc'  => 'application/x-sv4crc',
		'svg'     => 'image/svg+xml',
		'swf'     => 'application/x-shockwave-flash',
		'sxc'     => 'application/vnd.sun.xml.calc',
		'sxd'     => 'application/vnd.sun.xml.draw',
		'sxg'     => 'application/vnd.sun.xml.writer.global',
		'sxi'     => 'application/vnd.sun.xml.impress',
		'sxm'     => 'application/vnd.sun.xml.math',
		'sxw'     => 'application/vnd.sun.xml.writer',
		't'       => 'application/x-troff',
		'tar'     => 'application/x-tar',
		'tcl'     => 'application/x-tcl',
		'tex'     => 'application/x-tex',
		'texi'    => 'application/x-texinfo',
		'texinfo' => 'application/x-texinfo',
		'tgz'     => 'application/x-gzip',
		'tif'     => 'image/tiff',
		'tiff'    => 'image/tiff',
		'torrent' => 'application/x-bittorrent',
		'tr'      => 'application/x-troff',
		'tsv'     => 'text/tab-separated-values',
		'txt'     => 'text/plain',
		'ustar'   => 'application/x-ustar',
		'vcd'     => 'application/x-cdlink',
		'vrml'    => 'model/vrml',
		'wav'     => 'audio/x-wav',
		'wax'     => 'audio/x-ms-wax',
		'wbmp'    => 'image/vnd.wap.wbmp',
		'wbxml'   => 'application/vnd.wap.wbxml',
		'webp'    => 'image/webp',
		'wm'      => 'video/x-ms-wm',
		'wma'     => 'audio/x-ms-wma',
		'wml'     => 'text/vnd.wap.wml',
		'wmlc'    => 'application/vnd.wap.wmlc',
		'wmls'    => 'text/vnd.wap.wmlscript',
		'wmlsc'   => 'application/vnd.wap.wmlscriptc',
		'wmv'     => 'video/x-ms-wmv',
		'wmx'     => 'video/x-ms-wmx',
		'wrl'     => 'model/vrml',
		'wvx'     => 'video/x-ms-wvx',
		'xbm'     => 'image/x-xbitmap',
		'xht'     => 'application/xhtml+xml',
		'xhtml'   => 'application/xhtml+xml',
		'xls'     => 'application/vnd.ms-excel',
		'xpm'     => 'image/x-xpixmap',
		'xsl'     => 'text/xml',
		'xwd'     => 'image/x-xwindowdump',
		'xyz'     => 'chemical/x-xyz',
		'zip'     => 'application/zip',

		'bin'     => 'application/octet-stream',
		'xml'     => 'text/xml',
	);

	static $mime_types_rev = array(
		'font/eot'									=> 'eot',
		'application/vnd.ms-fontobject'				=> 'eot',

		'font/opentype'								=> 'otf',
		'application/font-opentype'					=> 'otf',
		'application/x-font-opentype'				=> 'otf',

		'application/font-ttf'						=> 'ttf',
		'application/x-font-ttf'					=> 'ttf',
		'application/x-font-truetype'				=> 'ttf',

		'font/woff'									=> 'woff',
		'application/font-woff'						=> 'woff',
		'application/x-font-woff'					=> 'woff',

		'application/font-woff2'					=> 'woff2',
		'application/x-font-woff2'					=> 'woff2',

		'application/octet-stream'					=> 'bin',

		'application/x-javascript'					=> 'js',
		'text/javascript'							=> 'js',
	);

	static function GetMimeContentType( $filename )
	{
		static $aMime = null;
		if( !$aMime )
			$aMime = array_merge( self::$mime_types, array_flip( self::$mime_types_rev ) );

		$mimeType = ($aMime[ strtolower( Gen::GetFileExt( $filename ) ) ]??null);
		if( empty( $mimeType ) )
			$mimeType = self::_GetMimeContentType( $filename );
		if( empty( $mimeType ) )
			$mimeType = 'application/octet-stream';

		return( $mimeType );
	}

	static private function _GetMimeContentType( $filename )
	{
		if( function_exists( 'mime_content_type' ) )
			return( @mime_content_type( $filename ) );

		if( function_exists( 'finfo_file' ) && function_exists( 'finfo_open' ) && function_exists( 'finfo_close' ) )
		{
			$fileinfo = @finfo_open( FILEINFO_MIME );
			$mime_type = @finfo_file( $fileinfo, $filename );
			@finfo_close( $fileinfo );

			return( $mime_type );
		}
	}

	static function GetFileTypeFromMimeContentType( $mimeType, $def = null )
	{
		static $aMimeRev = null;
		if( $aMimeRev === null )
			$aMimeRev = array_merge( self::$mime_types_rev, array_flip( self::$mime_types ) );

		return( ($aMimeRev[ $mimeType ]??$def) );
	}
}

