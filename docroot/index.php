<?php
//renders JSON of everything per settings, given auth key

if(!isset($_REQUEST['auth'])) returnJSON('[]');
$auth = $_REQUEST['auth'];

require_once '../settings.php';
require_once '../vendor/autoload.php';

$authkeys = json_decode(AUTHKEYS);
if(!property_exists($authkeys,$auth)) returnJSON('[]');
$prefs = $authkeys->$auth;

if(property_exists($prefs,'tz')) date_default_timezone_set($prefs->tz);
if(!property_exists($prefs,'timeformat')) $prefs->timeformat = DEFAULT_TIME_FORMAT;
if(!property_exists($prefs,'dateshortformat')) $prefs->dateshortformat = DEFAULT_DATE_SHORT_FORMAT;
if(!property_exists($prefs,'days')) $prefs->days = DEFAULT_DAYS;

//prepare the data structure that will be returned as JSON for display
$c = array(); 
$d = new DateTime();
for($i=0; $i<$prefs->days; $i++){
  $date = new stdClass();
  if($i>0) $d->add(new DateInterval('P1D'));
  $date->weekday = $d->format("l");
  $date->weekdayShort = $d->format("D");
  $date->weekdayRelative = ($i==0? 'Today': ($i==1? 'Tomorrow': $date->weekday));
  $date->date = $d->format("j");
  $date->dateShort = $d->format($prefs->dateshortformat);
  $date->month = $d->format("F");
  $date->monthShort = $d->format("M");
  if($i==0) {
    if(property_exists($prefs,'latitude') && property_exists($prefs,'longitude')) {
      $date->sun = date_sun_info($d->format('U'),$prefs->latitude,$prefs->longitude);
      foreach($date->sun as $k=>$s) {
        if($s) $date->sun[$k] = date($prefs->timeformat, $s);
      }
    }
  } 
  $date->weather = array();
  $date->events = array();
  $c[$d->format('Y-m-d')] = $date;
}

//Weather
if(property_exists($prefs,'nws')) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://api.weather.gov/gridpoints/".$prefs->nws."/forecast");
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
      if($p->name=='Overnight') continue; //don't bother with these
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
} //end if nws specified

function weatherReplace($in) {
  return str_replace('Showers And Thunderstorms','Rain',$in);
}

//Calendars
use ICal\ICal;
if(property_exists($prefs,'cals') && is_array($prefs->cals) && sizeof($prefs->cals)) {
  
  $events = new stdClass();
  $events->events = array();

  foreach($prefs->cals as $cal) {
    $ical = new ICal('ICal.ics', array(
      'defaultSpan'                 => 1,
      'filterDaysAfter'             => $prefs->days,
      'filterDaysBefore'            => $prefs->days,
    ));
    try {
      if(property_exists($cal,'userAgent')) $ical->initURL($cal->src, null, null, $cal->userAgent);
      else $ical->initUrl($cal->src);
    } catch (Exception $e) {
      //die($e);
      returnJSON('[]');
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
      $dz = array_key_exists('TZID',$ie->dtstart_array[0])? $ical->timeZoneStringToDateTimeZone($ie->dtstart_array[0]['TZID']): null;
      //extract event's zoneless start and end times
      $dstart = $dz? substr($ie->dtstart_array[3], strpos($ie->dtstart_array[3],':')+1): $ie->dtstart_array[3];
      $dend = $dz? substr($ie->dtend_array[3], strpos($ie->dtend_array[3],':')+1): $ie->dtend_array[3];
      //convert to datetime objects using event's zone
      $dstart = $dz? new DateTime($dstart, $dz): new DateTime($dstart);
      $dend = $dz? new DateTime($dend, $dz): new DateTime($dend);
      //set datetime objects to the web app's default zone
      $dstart->setTimezone(new DateTimeZone(date_default_timezone_get()));
      $dend->setTimezone(new DateTimeZone(date_default_timezone_get())); //TODO more efficient way to do this?
      //finally re-render strings
      $event->allday = ($dz===null); //ASSUMES no zone means all day
      //times
      if(!$event->allday) {
        $event->ltstart = $dstart->format('Hi'); //for sorting
        $event->timestart = $dstart->format($dstart->format('i')=='00'?$prefs->timeFormatTopOfHour:$prefs->timeFormat);
        if($prefs->timeIncludeEnd) $event->timeend = $dend->format($dend->format('i')=='00'?$prefs->timeFormatTopOfHour:$prefs->timeFormat);
      }
      //duration
      $ddiff = $dstart->diff($dend);
      $event->duration = $ddiff->days*24*60 + $ddiff->h*60 + $ddiff->i;
      //dates
      $event->dstart = $dstart->format('Y-m-d');
      $event->ldstart = $dstart->format('Ymd');
      $event->dstartShort = $dstart->format($prefs->dateshortformat);
      //if it's an all-day event (e.g. duration is multiple of 1440), take a day off $dend
      if($event->allday) $dend->sub(new DateInterval("P1D"));
      $event->dend = $dend->format('Y-m-d');
      $event->ldend = $dend->format('Ymd');
      $event->dendShort = $dend->format($prefs->dateshortformat);

      $event->style = $cal->style; //i.e. which calendar it came from

      $events->events[] = $event;
    }
  } //end foreach $cals as $cal

  //shuffle($events->events); //temporary for testing
  //Sort by start date, then all day, then start time, then summary
  usort($events->events, function($a,$b){
    $ats = property_exists($a,'ltstart')? $a->ltstart: '0';
    $bts = property_exists($b,'ltstart')? $b->ltstart: '0';
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
} //end if cals specified



$j = array();
foreach($c as $cd) $j[] = $cd; //convert to non-associative array

if(isset($_REQUEST['sample'])) { //as html
  //Pretend display
  foreach($c as $cd) {
    //header
    if($cd->weekdayRelative=='Today') {
      echo "<h2>".$cd->weekdayShort." <span style='font-size: 1.5em;'>".$cd->date."</span> ".$cd->monthShort."</h2>";
      //sunrise, sunset
      if(property_exists($cd,'sun')) echo "<p>".($cd->sun['sunrise']? "Sunrise <strong>".$cd->sun['sunrise']."</strong>": "")." &nbsp; ".($cd->sun['sunset']? "Sunset <strong>".$cd->sun['sunset']."</strong>": "")."</p>";
      //moon TODO
    } else {
      echo "<h3><strong>".$cd->weekdayRelative." ".$cd->dateShort."</strong></h3>";
    }
    //weather
    echo "<ul style='list-style-type: none; padding-left: 0;'>";
    foreach($cd->weather as $cw) {
      echo "<li><strong>";
      echo ($cw->isDaytime?'High ':'Low ');
      //echo $cw->name.' ';
      echo $cw->temperature."°</strong> ".$cw->shortForecast."</li>";
    }
    echo "</ul>";
    //events
    echo "<ul>";
    foreach($cd->events as $ce) {
      echo "<li style='color: ".$ce->style."'>";
      if($ce->allday) echo "<strong>".$ce->summary."</strong>".($ce->dend!==$ce->dstart? " (thru ".$ce->dendShort.")": "");
      else echo "<strong>".$ce->timestart."</strong>".(property_exists($ce,'timeend')?'–'.$ce->timeend:'')." ".$ce->summary;
      echo "</li>";
    }
    echo "</ul>";
  }

  echo "<pre>".json_encode($j,JSON_PRETTY_PRINT)."</pre>";

} else { //not sample
  returnJSON(json_encode($j)); 
}

function returnJSON($content) {
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Length: '.strlen($content)); //prevents Apache from transfer chunking
  die($content);
}
?>