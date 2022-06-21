<?php
date_default_timezone_set('America/Chicago');
define('DEFAULT_TIME_FORMAT', 'H:i');
define('DEFAULT_DATE_SHORT_FORMAT', 'm/d');
define('DEFAULT_DAYS', 2);
define('NWS_USER_AGENT', 'user agent string here');

//For /cal – generating JSON for a single calendar at a time
define('AUTHKEY','random auth string here'); //if present, this will require any requests to /cal to include ?auth=[authkey] in order to process

//For full content JSON generation, by auth key
//nws = National Weather Service WFO/Gridpoint
//cals = iCalendar sources
define('AUTHKEYS','{
  "random auth string here": {
    "tz": "America/Chicago",
    "timeFormat": "G:i",
    "timeFormatTopOfHour": "G",
    "timeIncludeEnd": true,
    "dateShortFormat": "n/j",
    "days": 2,
    "latitude": 32.55,
    "longitude": -96.55,
    "nws": "FWD/86,102",
    "cals": [
      {
        "src": "ics url here",
        "style": "black"
      },
      {
        "src": "ics url here",
        "style": "red",
        "userAgent": "user agent string here"
      }  
    ]
  }
}');
//Some calendar sources require userAgent string (e.g. office365)