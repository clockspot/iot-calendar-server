<?php
//renders JSON of everything per settings, given auth key

if(!isset($_REQUEST['auth'])) die();
$auth = $_REQUEST['auth'];

require_once '../../settings.php';
require_once '../../vendor/autoload.php';

$prefs = json_decode(PREFS);
$nws = json_decode(NWS);
$cals = json_decode(CALS);

foreach([$prefs,$nws,$cals] as $ar) if(!property_exists($ar,$auth)) die();

$prefs = $prefs->$auth;
$nws = $nws->$auth;
$cals = $cals->$auth;

if(property_exists($prefs,'tz')) date_default_timezone_set($prefs->tz);
if(!property_exists($prefs,'timeformat')) $prefs->timeformat = DEFAULT_TIME_FORMAT;
if(!property_exists($prefs,'days')) $prefs->days = DEFAULT_DAYS;

//prepare the data structure that will be returned as JSON for display
$c = array(); 
$d = new DateTime();
for($i=0; $i<$prefs->days; $i++){
  if($i>0) $d->add(new DateInterval('P1D'));
  $date = new stdClass();
  $date->weekday = $d->format("D");
  $date->date = $d->format("j");
  $date->month = $d->format("M");
  $date->weather = array();
  $date->events = array();
  $c[$d->format('Y-m-d')] = $date;
}

//Weather
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.weather.gov/gridpoints/".$nws."/forecast");
$headers = array('User-Agent: '.NWS_USER_AGENT);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);  
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$out = curl_exec($ch);
curl_close($ch);
$r = json_decode($out);
if(!(property_exists($r,'status') && property_exists($r,'detail')) && property_exists($r,'properties') && is_object($r->properties) && property_exists($r->properties,'periods') && is_array($r->properties->periods)){
  foreach($r->properties->periods as $p) {
    $dt = substr($p->startTime,0,10);
    if(array_key_exists($dt,$c)) {
      $w = new stdClass();
      $w->name = $p->name;
      $w->isDaytime = $p->isDaytime;
      $w->temperature = $p->temperature;
      $w->shortForecast = weatherReplace($p->shortForecast);
      $c[$dt]->weather[] = $w;
    }    
  }
}

function weatherReplace($in) {
  return str_replace('Showers And Thunderstorms','Rain',$in);
}

//Calendars
use ICal\ICal;

$events = new stdClass();
$events->events = array();

foreach($cals as $cal) {
  try {
    $ical = new ICal('ICal.ics', array(
      'defaultSpan'                 => 1,
      'filterDaysAfter'             => $prefs->days,
      'filterDaysBefore'            => $prefs->days,
    ));
    $ical->initUrl($cal->src);
  } catch (Exception $e) {
    die($e);
  }
  
  $ies = $ical->eventsFromInterval($prefs->days.' days');
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
    $event->dstart = $dstart->format('Y-m-d');
    $event->ldstart = $dstart->format('Ymd');
    $event->dend = $dend->format('Y-m-d');
    $event->ldend = $dend->format('Ymd');
    $event->allday = ($dz===null); //ASSUMES no zone means all day
    if(INCLUDE_DURATION) {
      $ddiff = $dstart->diff($dend);
      $event->duration = $ddiff->days*24*60 + $ddiff->h*60 + $ddiff->i;
    }
    if(!$event->allday) $event->timestart = $dstart->format($prefs->timeformat);
    $event->style = $cal->style; //i.e. which calendar it came from

    $events->events[] = $event;
  }
} //end foreach $cals as $cal

shuffle($events->events); //temporary for testing
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

//divvy the events up into $c
foreach($events->events as $event) {
  if(array_key_exists($event->dstart,$c)) {
    $c[$event->dstart]->events[] = $event;
  }
}



$j = array();
foreach($c as $cd) $j[] = $cd; //convert to non-associative array

//Pretend display
?>
<style>
  .month, .weekday { font-size: 2em; }
  .date { font-size: 3em; }
</style>
<?php
$doneToday = false;
foreach($c as $cd) {
  //header
  if(!$doneToday) {
    echo "<p><span class='weekday'>".$cd->weekday."</span> <span class='date'>".$cd->date."</span> <span class='weekday'>".$cd->month."</span></p>";
    //sunrise, sunset, moon
    $doneToday = true;
  } else {
    //echo "<p>".$cd->weekday." ".$cd->date." ".$cd->month."</p>";
  }
  //weather
  echo "<ul style='list-style-type: none; padding-left: 0;'>";
  foreach($cd->weather as $cw) {
    echo "<li><strong>";
    //echo ($cw->isDaytime?'High ':'Low ');
    echo $cw->name.' ';
    echo $cw->temperature."°</strong> ".$cw->shortForecast."</li>";
  }
  echo "</ul>";
  //events
  echo "<ul>";
  foreach($cd->events as $ce) {
    echo "<li style='color: ".$ce->style."'>";
    if($ce->allday) echo "<strong>".$ce->summary."</strong>";
    else echo "<strong>".$ce->timestart."</strong> ".$ce->summary;
    echo "</li>";
  }
  echo "</ul>";
}

echo "<pre>".json_encode($c,JSON_PRETTY_PRINT)."</pre>";
//echo json_encode($c);

?>