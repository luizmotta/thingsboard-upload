#!/usr/bin/php
<?php

include 'config.php';

// Check for another running instance
$lock_file = fopen(__FILE__.'.pid', 'c');
$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
if ($lock_file === false || (!$got_lock && !$wouldblock)) {
    throw new Exception(
        "Unexpected error opening or locking lock file. Perhaps you " .
        "don't  have permission to write to the lock file or its " .
        "containing directory?"
    );
}
else if (!$got_lock && $wouldblock) {
    exit("Another instance is already running; terminating.\n");
}

// Lock acquired; let's write our PID to the lock file for the convenience
// of humans who may wish to terminate the script.
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . "\n");

/* MAIN LOOP */

$last_filemtime = 0;
while ( true ) {
	clearstatcache( $logfile );
	$filemtime = filemtime( $logfile );
	if( $last_filemtime <> $filemtime ) {
		$last_filemtime = $filemtime;
		system('php upload.php');;
	}
	sleep( 1 );
}


// All done; we blank the PID file and explicitly release the lock 
// (although this should be unnecessary) before terminating.
ftruncate($lock_file, 0);
flock($lock_file, LOCK_UN);
?>
