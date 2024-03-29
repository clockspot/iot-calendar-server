<?php
date_default_timezone_set('America/Chicago');
define('DEFAULT_TIME_FORMAT', 'H:i');
define('DEFAULT_DATE_SHORT_FORMAT', 'm/d');
define('DEFAULT_DAYS', 2);
define('GRACE_PERIOD_MINS', 120); //if a request is made within this many hours of midnight, it is assumed to be for the next day (and appears under the log for that day)
define('DEFAULT_CHARSET_TO','ASCII//TRANSLIT');
define('NWS_USER_AGENT', 'user agent string here');

define('LOG_DIR','log'); //Relative to project root - if defined and writable, all requests for a given day are recorded under `Y-m.log`
define('CACHE_DIR','cache'); //Relative to project root - if defined and writable, the server response for a given day is stored here as 'Y-m-d.json', and any future requests for the same date are served this instead. (Intended to help mitigate bugs where the client makes too many requests back to back.)
define('CACHE_RAW_DIR','cacheraw'); //Relative to project root - if defined and writable, the source responses (used to build the server response) for a given day is stored here as 'Y-m-d.json'

define('AUTHKEY','random auth string here'); //if present, this will require any requests to include ?auth=[authkey] in order to process

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
    "showDawnDusk": true,
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