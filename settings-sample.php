<?php
date_default_timezone_set('America/Chicago');

define('INCLUDE_DURATION', false);
define('TIMESTART_FORMAT', 'H:i');

define('AUTHKEY','random string here'); //if present, this will require any requests to include ?auth=[authkey] in order to process