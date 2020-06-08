#!/usr/bin/php
<?php

include 'config.php';

if( @$argv[1] != "" ) {
	$logfile = $argv[1];
}

$lastprocessedfile = $logfile . ".lastprocessed";
//$history = array();
$last_datapoint_line = "";
 
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

$log = file( $logfile );

$lastprocessed = @file_get_contents( $lastprocessedfile );
if ( $lastprocessed === false  ) { //If file was not present
        $lastprocessed = -1;
} else {
        if ( $lastprocessed > max( array_keys( $log ) ) ) { //If file was present but log rotated
                $lastprocessed = -1;
        }
}

foreach ( $log as $linenumber => $line ) {


        $lineold = $line;

        $linedata = explode( ",", $line);
        $datetime = $linedata[0];
        @$sensor = $linedata[1];
        @$cmd = $linedata[2];

        if ( substr( $cmd, 0, 4) == "TEMP" ) {
                $field = "TEMP";
                $data = trim( substr( $cmd, 4) );
        } else if ( substr( $cmd, 0, 4) == "RHUM" ) {
                $field = "RHUM";
                $data = trim( substr( $cmd, 4) );
        } else if ( substr( $cmd, 0, 4) == "BATT" ) {
                $field = "BATT";
                $data = trim( substr( $cmd, 4) );
        } else if ( substr( $cmd, 0, 4) == "LVAL" ) {
                $field = "LVAL";
                $data = trim( substr( $cmd, 4) );
        } else if ( substr( $cmd, 0, 4) == "TILT" ) {
                $field = "TILT";
                $data = trim( substr( $cmd, 4) );
        } else if ( substr( $cmd, 0, 3) == "LUX" ) {
                $field = "LUX";
                $data = trim( substr( $cmd, 3) );
		if ( $data == "88888" ) $data = "";
        } else {
                continue;
        }

	$channel_token = $channel[$sensor]["token"];
	$mqtt_topic = $channel[$sensor]["topic"];
	$mqtt_retain = @$channel[$sensor]["dont_retain"] ? false : true;
	$run_cmd = @$channel[$sensor]["run"];
	$max_freq = @$channel[$sensor]["max_freq"];

        if ( $channel_token == "" ) {
                continue;
        }

	$datapoint = array();
	$datapoint[ "line" ] = $line;
	$datapoint[ "datetime" ] = $datetime;
	$datapoint[ "sensor" ] = $sensor;
	$datapoint[ "cmd" ] = $cmd;
	$datapoint[ "field" ] = $field;
	$datapoint[ "data" ] = $data;

        //Check for repeated messages and ignore if found
        //foreach( $history as $old_datapoint ) {
        //        if( $old_datapoint[ "line" ] == $datapoint[ "line" ] ) {
        //                continue 2;
        //        }
        //}
        //Otherwise, add to history
        //$history[] = $datapoint;

        //Ignore consecutive repeated lines
        if( $datapoint[ "line" ] == $last_datapoint_line ) {
                continue;
        } else {
                $last_datapoint_line = $datapoint[ "line" ];
        }

        //print_r("|".$datetime."|");
        $dt = DateTime::createFromFormat( "d M Y H:i:s O", trim($datetime) );
        //print_r(DateTime::getLastErrors());

	$post_data = array();
	$post_data["ts"] = $dt->getTimestamp() *1000;
	if( $field == "RHUM" ) { $post_data["values"]["humidity"] = floatval( $data ); }
	if( $field == "TEMP" ) { $post_data["values"]["temperature"] = floatval( $data ) ; }
	if( $field == "BATT" ) { $post_data["values"]["voltage"] = floatval( $data ); }
	if( $field == "LVAL" ) { $post_data["values"]["luminosity"] = floatval( $data ); }
	if( $field == "LUX" ) { $post_data["values"]["lux"] = floatval( $data ); }
	if( $field == "TILT" ) { $post_data["values"]["tilt"] = true; }

	//Debounce
	if( $max_freq ) {
		if( $dt->getTimestamp() - @$last_alert[$sensor] <= $max_freq ) { //dont repeat alerts in less than X seconds
			continue;
		}
		$last_alert[$sensor] = $dt->getTimestamp();
	}

	//Ignore everything up to lastprocessed
        if ( $linenumber <= $lastprocessed ) {
                continue;
        }

	if( $run_cmd <> '' ) {
		error_log( 'Running: ' . $run_cmd );
		exec( $run_cmd );
	}

       	$url = $apiurl . $channel_token . "/telemetry";
	$ch = curl_init( $url );
	$data_string = json_encode( $post_data );
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
	    'Content-Type: application/json',                                                                                
	    'Content-Length: ' . strlen($data_string))                                                                       
	);                                                                                                                   
                                                                                                                     
	$result = curl_exec($ch);

	//print_r( $url );
	//print_r( $datapoint );
	//print_r( $post_data );
	//print_r( $result );
	//print_r( $ch );
	//print_r( $curl_error );

        if ( $result === false ) {
                die("Couldn't update ThingsBoard");
        }

	$topic = $mqtt_topic . array_pop( array_keys( $post_data["values"] ) );
	$retain_flag = $mqtt_retain ? "-r" : "";
	$message = array_pop( $post_data["values"] );
	$cmd = "mosquitto_pub $retain_flag -h '$mqtt_server_ip' -p '$mqtt_server_port' -u '$mqtt_server_user' -P '$mqtt_server_pw' -t '$topic' -m $message";
	error_log( $cmd );
	exec( $cmd, $output, $result );
        if ( $result != 0 ) {
                die("Couldn't update MQTT");
        }

        file_put_contents( $lastprocessedfile, $linenumber);
}

// All done; we blank the PID file and explicitly release the lock 
// (although this should be unnecessary) before terminating.
ftruncate($lock_file, 0);
flock($lock_file, LOCK_UN);
?>
