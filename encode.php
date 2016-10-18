<?php
date_default_timezone_set('America/Los_Angeles');
set_time_limit ( 0 );
error_reporting ( E_ALL ^ E_NOTICE );
ini_set ( 'display_errors', true );

function makeMultipleTwo($value) {
	$sType = gettype ( $value / 2 );
	if ($sType == "integer") {
		return $value;
	} else {
		return ($value - 1);
	}
}

/**
 * This function creates specfied directories.
 *
 * @param string $path		Path you want created.
 */
function create_dirs($path) {
	if (! is_dir ( $path )) {
		$directory_path = "";
		$directories = explode ( "/", $path );
		array_pop ( $directories );
		
		foreach ( $directories as $directory ) {
			$directory_path .= $directory . "/";
			if (! is_dir ( $directory_path )) {
				@mkdir ( $directory_path, 0777 );
				@chmod ( $directory_path, 0777 );
			}
		}
	}
}

$root = dirname ( __FILE__ );

set_include_path ( '/var/www/virtual/lustre/shared-libraries/Smarty' . PATH_SEPARATOR . $root . '/includes' . PATH_SEPARATOR . $root . '/config' . PATH_SEPARATOR . get_include_path () );

include ('ATK_Database.php');

//Load our INI file
$loadini = parse_ini_file ( 'queue.ini' );
$process_folder = $loadini ['process'];
$content_folder = $loadini ['content'];
$thumb_folder = $loadini ['thumbs'];
$encode_ftp = $loadini ['process_ftp'];
$content_root = $loadini ['content_root'];

//connect to the database
$db = db_connect ( $loadini ['user'], $loadini ['pass'], $loadini ['hostname'], $loadini ['database'], 'mysql' );
$mdb = db_connect ( $loadini ['mdb_user'], $loadini ['mdb_pass'], $loadini ['mdb_hostname'], $loadini ['mdb_database'], $loadini ['mdb_type'] );

$touch_file = "/var/www/virtual/lustre/process/log/encoding";

if (file_exists ( $touch_file )) {
	//echo "\n" . '<br>Previous Encode Job still running at: ' . date ( "F j, Y, g:i a" );
	if((time() - filemtime($touch_file)) > 1800) {
		unlink($touch_file);
	}
} else {
	//Creates a file to let script know it is running.
	touch ( $touch_file );
	echo "\n" . '<br>Started Encode Job at: ' . date ( "F j, Y, g:i a" );
	
	$extension_soname = "ffmpeg.so";
	
	//load ffmpeg extension
	if (! extension_loaded ( "ffmpeg" )) {
		dl ( $extension_soname ) or error ( "Can’t load extension $extension_fullname", true );
	}
	//Query our first file in the queue
	$query = "Select model_id, photog, setid, type, filename from process_queue where processed <> '1' and returned <> '1' order by date_ent limit 1";
	$row = $db->queryRow ( $query );
	foreach ( $row as $k => $v ) {
		$$k = $v;
	}
	/*
	$model_id  = 'dul001';
	$photog = 'ROK';
	$setid = '219839';
	$type = 'wmv';
	$filename = 'dul001ROK_219839001.wmv';
	*/
	//Query model's site name
	$model_query = "SELECT atk_name from models where model_id = '$model_id';";
	echo 'Model Query: '.$model_query."\r";
	$model_name = $mdb->queryOne ( $model_query );
	echo $model_name."\r";
	$loc = $mdb->queryOne("SELECT p.set from photosets p where set_id='$setid'");
	$ex = explode('/',$loc);
	$clip = array_pop($ex);
	$clip = substr($clip,0,19);
	$location = implode('/',$ex).'/';
	print_r($model_name);
	// Set our source file
	$srcFile = $content_root . $location . $filename;
	//Create our directories
	$destThumb = $thumb_folder . substr ( $model_id, 0, 1 ) . '/' . $model_id . '/' . $setid . '/thumbs/ScreenCaps/';
	$destBigThumbs = $thumb_folder . substr ( $model_id, 0, 1 ) . '/' . $model_id . '/' . $setid . '/bigthumbs/ScreenCaps/';
	$destTrl = $content_root . $location;
	$destContent = $content_root . $location;
	create_dirs ( $destThumb );
	create_dirs ( $destBigThumbs );
	//create_dirs ( $destContent );
	//Set the destfile name
	$destFile = strtolower ( $model_name . '_' . $setid );
	echo "Destfile: ".$destFile;
	//Path to ffmpeg
	$ffmpegPath = "/usr/bin/ffmpeg";
	//path tp flvtool2
	$flvtool2Path = "/usr/bin/flvtool2";
	
	if ($type == 'mov') {
		$formats = array (  'thumbs' , 'bigthumbs' );
		$end = '.mov';
	} elseif ($type == 'wmv') {
		$formats = array (  'thumbs' , 'bigthumbs' );
		$end = '.wmv';
	} elseif ($type == 'mp4') {
		$formats = array (  'thumbs' , 'bigthumbs' );
		$end = '.mp4';
	}
	$pids = array ();
	
	foreach ( $formats as $foo ) {
		$pid = pcntl_fork ();
		if ($pid == - 1) {
			$to = "helpdesk@atkcash.com";
			$subject = "ATKMovies.com Encode Fork Failure";
			$body = "The Movie Encoding process for ATKMovies has failed.";
			if (mail ( $to, $subject, $body )) {
				echo ("<p>Message successfully sent!</p>");
			} else {
				echo ("<p>Message delivery failed...</p>");
			}
			exit ( 0 );
		} else if ($pid) {
			// we are in the parent
			$pids [] = $pid;
			//pcntl_waitpid ( $pid, $status );
		} else {
			// we're in the child - encode the movie
			if ($foo == 'trl') {
				// Create our FFMPEG-PHP class
				//$mp4creatorPath = "/usr/bin/mp4creator";
				// Create our FFMPEG-PHP class
				$ffmpegObj = new ffmpeg_movie ( $srcFile );
				$hgt = $ffmpegObj->getFrameHeight();
				$wdt = $ffmpegObj->getFrameWidth();
				$trailer = $clip.'_tr.mp4';
				$mp4srcFile = $clip.'_sd.mp4';
				
				// split out video
				$exec_string = "$ffmpegPath -t 30 -i $destContent"."$mp4srcFile -acodec libfaac -ab 96k -vcodec libx264 -vpre slow -crf 22 -threads 0 $destContent".$trailer;
				echo $exec_string;
				exec ( $exec_string, $exec_output );
			
			} elseif ($foo == 'thumbs') {
				$srcFile = substr($srcFile,0,-6)."hd.mp4";
				// Create our FFMPEG-PHP class
				$ffmpegObj = new ffmpeg_movie ( $srcFile );
				// Call our convert using exec()
				$exec_string = $ffmpegPath . " -i " . $srcFile . " -r .08 -s 200x150 -sameq -y  " . $destThumb . $destFile . '_%03d.jpg';
				exec ( $exec_string, $exec_output );
			} elseif ($foo == 'bigthumbs') {
				$srcFile = substr($srcFile,0,-6)."hd.mp4";
				// Create our FFMPEG-PHP class
				$ffmpegObj = new ffmpeg_movie ( $srcFile );
				$hgt = $ffmpegObj->getFrameHeight();
				$wdt = $ffmpegObj->getFrameWidth();
				// Call our convert using exec()
				$exec_string = $ffmpegPath . " -i " . $srcFile . " -r .08 -s ".$wdt."x".$hgt." -sameq -y  " . $destBigThumbs . $destFile . '_%03d.jpg';
				echo $exec_string;
				exec ( $exec_string, $exec_output );
			} 
			exit ();
		}
	}
	
	foreach ( $pids as $pid ) {
		pcntl_waitpid ( $pid, $status );
	}
	//exec ( 'cp ' . $srcFile . ' ' . $destContent . $destFile . $end );
	//exec ( 'cp ' . $srcFile . ' ' . $destContent . $filename );
	$finish = '/usr/bin/php /var/www/virtual/lustre/process/finish.php ' . $model_id . ' ' . $setid . ' ' . $model_name;
	echo "\n" . $finish . "\n";
	exec ( $finish, $fin_output );
	echo "\n" . '<br>Finished Encode Job at: ' . date ( "F j, Y, g:i a" ) . '<br>';
	unlink ( $touch_file );
}
