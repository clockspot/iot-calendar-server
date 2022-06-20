<?php
//renders JSON of just the calendar specified in $_REQUEST['src']

if(!isset($_REQUEST['src'])) die();

require_once '../settings.php';
if(defined('AUTHKEY') && (!isset($_REQUEST['auth']) || $_REQUEST['auth']!==AUTHKEY)) die();

$filterDaysBefore = isset($_REQUEST['filterDaysBefore'])? intval($_REQUEST['filterDaysBefore']): DEFAULT_DAYS;
$filterDaysAfter = isset($_REQUEST['filterDaysAfter'])? intval($_REQUEST['filterDaysAfter']): DEFAULT_DAYS;

require_once '../vendor/autoload.php';

use ICal\ICal;

try {
  $ical = new ICal('ICal.ics', array(
    'defaultSpan'                 => 1,
    'filterDaysAfter'             => 2,
    'filterDaysBefore'            => 2,
  ));
  $ical->initUrl($_REQUEST['src']);
} catch (\Exception $e) {
  die($e);
}

//looking to mimic https://icalendar.org/iot.html (ish)
// {
//   "events": [
//       {
//           "summary": "Work Anniversary ",
//           "ldstart": "20190124",
//           "ldend": "20190124", //in our case this would be 0125 - we don't actually care about it(?)
//           "duration": 1440,
//           "rday": 0, //in our case, this is omitted (what is it)
//           "allday": 1
//       },
//       {
//           "summary": "Teacher Appointment",
//           "ldstart": "20190124",
//           "ldend": "20190124", 
//           "duration": 30,
//           "rday": 0,
//           "allday": 0,
//           "timestart": "10:30" //in our case this reflects TIMESTART_FORMAT
//       },
//       {
//           "summary": "Project Status Meeting",
//           "ldstart": "20190124",
//           "ldend": "20190124",
//           "duration": 60,
//           "rday": 0,
//           "allday": 0,
//           "timestart": "15"
//       }
//   ]
// }

// $ical->eventCount
// $ical->freeBusyCount
// $ical->todoCount
// $ical->alarmCount

$events = new stdClass();
$events->events = array();
$ies = $ical->eventsFromInterval($filterDaysAfter.' days');
//echo "<pre>"; var_dump($ies);
//echo "<pre>".json_encode($ies,JSON_PRETTY_PRINT)."</pre>";
foreach($ies as $ie) {
  $event = new stdClass();
  $event->summary = $ie->summary;
  //can't use dtstart_tz because that appears to be in the calendar's zone, which may not be the desired zone
  //so we'll use PHP DateTime objects to convert from the event zone to the desired zone (spec'd in settings)
  //extract event's zone (no zone for allday events)
  $dz = array_key_exists('TZID',$ie->dtstart_array[0])? $ie->dtstart_array[0]['TZID']: null;
  //extract event's zoneless start and end times
  $dstart = $dz? substr($ie->dtstart_array[3], strpos($ie->dtstart_array[3],':')+1): $ie->dtstart_array[3];
  $dend = $dz? substr($ie->dtend_array[3], strpos($ie->dtend_array[3],':')+1): $ie->dtend_array[3];
  //convert to datetime objects using event's zone
  $dstart = $dz? new DateTime($dstart, new DateTimeZone($dz)): new DateTime($dstart);
  $dend = $dz? new DateTime($dend, new DateTimeZone($dz)): new DateTime($dend);
  //set datetime objects to the web app's default zone
  $dstart->setTimezone(new DateTimeZone(date_default_timezone_get()));
  $dend->setTimezone(new DateTimeZone(date_default_timezone_get())); //TODO more efficient way to do this?
  //finally re-render strings
  $event->ldstart = $dstart->format('Ymd');
  $event->ldend = $dend->format('Ymd');
  $event->allday = ($dz===null); //ASSUMES no zone means all day
  if(INCLUDE_DURATION) {
    $ddiff = $dstart->diff($dend);
    $event->duration = $ddiff->days*24*60 + $ddiff->h*60 + $ddiff->i;
  }
  if(!$event->allday) $event->timestart = $dstart->format(DEFAULT_TIME_FORMAT);
  $events->events[] = $event;
}
//shuffle($events->events); //temporary for testing
//Sort by start date, then all day, then start time, then summary
usort($events->events, function($a,$b){
  $ats = property_exists($a,'timestart')? $a->timestart: '0';
  $bts = property_exists($b,'timestart')? $b->timestart: '0';
  return ($a->ldstart==$b->ldstart? 
    ($ats==$bts?
      ($a->summary>$b->summary?1:-1)
    : ($ats>$bts?1:-1))
  : ($a->ldstart>$b->ldstart?1:-1));
});

//echo "<pre>".json_encode($events,JSON_PRETTY_PRINT)."</pre>";
echo json_encode($events);
?>