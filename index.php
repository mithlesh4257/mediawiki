<?php
# Main wiki script; see design.doc
#
$wgRequestTime = microtime();

## Enable this to debug total elimination of register_globals
#define( "DEBUG_GLOBALS", 1 );

if( defined('DEBUG_GLOBALS') ) error_reporting(E_ALL);

function &fix_magic_quotes( &$arr ) {
	foreach( $arr as $key => $val ) {
		if( is_array( $val ) ) {
			fix_magic_quotes( $arr[$key] );
		} else {
			$arr[$key] = stripslashes( $val );
		}
	}
	return $arr;
}

if ( get_magic_quotes_gpc() ) {
	fix_magic_quotes( $_COOKIE );
	fix_magic_quotes( $_ENV );
	fix_magic_quotes( $_GET );
	fix_magic_quotes( $_POST );
	fix_magic_quotes( $_REQUEST );
	fix_magic_quotes( $_SERVER );
} elseif( defined('DEBUG_GLOBALS') ) {
	die("DEBUG_GLOBALS: turn on magic_quotes_gpc" );
}

if( defined('DEBUG_GLOBALS') ) {
	if( ini_get( "register_globals" ) ) {
		die( "DEBUG_GLOBALS: turn off register_globals" );
	}
} elseif( !ini_get( "register_globals" ) ) {
	# Insecure, but at least it'll run
	import_request_variables( "GPC" );
}

unset( $IP );
ini_set( "allow_url_fopen", 0 ); # For security...
if(!file_exists("LocalSettings.php")) {
	die( "You'll have to <a href='config/index.php'>set the wiki up</a> first!" );
}
include_once( "./LocalSettings.php" );

if( $wgSitename == "MediaWiki" ) {
	die( "You must set the site name in \$wgSitename before installation.\n\n" );
}

# PATH_SEPARATOR avaialble only from 4.3.0
$sep = (DIRECTORY_SEPARATOR == "\\") ? ";" : ":";
ini_set( "include_path", $IP . $sep . ini_get( "include_path" ) );

include_once( "Setup.php" );

wfProfileIn( "main-misc-setup" );
OutputPage::setEncodings(); # Not really used yet

# Query string fields
if( empty( $_REQUEST['action'] ) ) {
	$action = "view";
} else {
	$action = $_REQUEST['action'];
}

if( isset( $_SERVER['PATH_INFO'] ) ) {
	$title = substr( $_SERVER['PATH_INFO'], 1 );
} elseif( !empty( $_REQUEST['title'] ) ) {
	$title = $_REQUEST['title'];
} else {
	$title = "";
}

# Placeholders in case of DB error
$wgTitle = Title::newFromText( wfMsg( "badtitle" ) );
$wgArticle = new Article($wgTitle);

$action = strtolower( trim( $action ) );
if ( "" == $action ) { $action = "view"; }
if ( !empty( $_REQUEST['printable'] ) && $_REQUEST['printable'] == "yes") {
	$wgOut->setPrintable();
}

if ( "" == $title && "delete" != $action ) {
	$wgTitle = Title::newFromText( wfMsg( "mainpage" ) );
} elseif ( !empty( $_REQUEST['curid'] ) ) {
	# URLs like this are generated by RC, because rc_title isn't always accurate
	$wgTitle = Title::newFromID( $_REQUEST['curid'] );
} else {
	$wgTitle = Title::newFromURL( $title );
}
wfProfileOut( "main-misc-setup" );

# If the user is not logged in, the Namespace:title of the article must be in the Read array in
#  order for the user to see it.
if ( !$wgUser->getID() && is_array( $wgWhitelistRead ) && $wgTitle) {
	if ( !in_array( $wgLang->getNsText( $wgTitle->getNamespace() ) . ":" . $wgTitle->getDBkey(), $wgWhitelistRead ) ) {
		$wgOut->loginToUse();
		$wgOut->output();
		exit;
	}
}

if ( !empty( $_REQUEST['search'] ) ) {
	if( isset($_REQUEST['fulltext']) ) {
		wfSearch( $_REQUEST['search'] );
	} else {
		wfGo( $_REQUEST['search'] );
	}
} else if( !$wgTitle or $wgTitle->getInterwiki() != "" or $wgTitle->getDBkey() == "" ) {
	$wgTitle = Title::newFromText( wfMsg( "badtitle" ) );
	$wgOut->errorpage( "badtitle", "badtitletext" );
} else if ( ( $action == "view" ) && $wgTitle->getPrefixedDBKey() != $title ) {
	/* redirect to canonical url, make it a 301 to allow caching */
	$wgOut->redirect( wfLocalUrl( $wgTitle->getPrefixedURL() ), '301');
} else if ( Namespace::getSpecial() == $wgTitle->getNamespace() ) {
	wfSpecialPage();
} else {
	if ( Namespace::getMedia() == $wgTitle->getNamespace() ) {
		$wgTitle = Title::makeTitle( Namespace::getImage(), $wgTitle->getDBkey() );
	}	
	
	switch( $wgTitle->getNamespace() ) {
	case 6:
		include_once( "ImagePage.php" );
		$wgArticle = new ImagePage( $wgTitle );
		break;
	default:
		$wgArticle = new Article( $wgTitle );
	}

	wfQuery("BEGIN", DB_WRITE);
	switch( $action ) {
		case "view":
		case "watch":
		case "unwatch":
		case "delete":
		case "revert":
		case "rollback":
		case "protect":
		case "unprotect":
			$wgArticle->$action();
			break;
		case "print":
			$wgArticle->view();
			break;
		case "edit":
		case "submit":
			if( !$wgCommandLineMode && !isset( $_COOKIE[ini_get("session.name")] ) ) {
				User::SetupSession();
			}
			include_once( "EditPage.php" );
			$editor = new EditPage( $wgArticle );
			$editor->$action();
			break;
		case "history":
			include_once( "PageHistory.php" );
			$history = new PageHistory( $wgArticle );
			$history->history();
			break;
		default:
			$wgOut->errorpage( "nosuchaction", "nosuchactiontext" );
	}
	wfQuery("COMMIT", DB_WRITE);
}

$wgOut->output();
foreach ( $wgDeferredUpdateList as $up ) { $up->doUpdate(); }
logProfilingData();
wfDebug( "Request ended normally\n" );
?>
