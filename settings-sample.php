<?php
date_default_timezone_set('America/Chicago');
define('DEFAULT_TIME_FORMAT', 'H:i');
define('DEFAULT_DAYS', 2);
define('INCLUDE_DURATION', true);
define('NWS_USER_AGENT', 'user agent string here');

define('AUTHKEY','random string here'); //if present, this will require any requests to / to include ?auth=[authkey] in order to process

//For /full content generation, by auth key:

//Preferences - can override the defaults above
define('PREFS','{
  "auth key here": {
    "tz": "America/Chicago",
    "timeformat": "H:i",
    "days": 2
  }
}');

//National Weather Service WFO/Gridpoint
define('NWS','{
  "auth key here": "FWD/86,102"
}');

//iCalendar URLs
define('CALS','{
  "auth key here": [
    {
      "src": "ics url here",
      "style": "black"
    }
  ]
}');