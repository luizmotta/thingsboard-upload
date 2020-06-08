#!/bin/sh
pid=`pgrep -f upload_daemon.php`
if [ -z "$pid" ] ; then
	php ~/thingsboard-upload/upload_daemon.php &
fi
