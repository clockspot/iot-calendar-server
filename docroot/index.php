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
if(!property_exists($prefs,'timeFormat')) $prefs->timeFormat = (defined('DEFAULT_TIME_FORMAT')? DEFAULT_TIME_FORMAT: 'G:i');
if(!property_exists($prefs,'dateShortFormat')) $prefs->dateShortFormat = (defined('DEFAULT_DATE_SHORT_FORMAT')? DEFAULT_DATE_SHORT_FORMAT: "n/j");
if(!property_exists($prefs,'days')) $prefs->days = (defined('DEFAULT_DAYS')? DEFAULT_DAYS: 2);
if(!property_exists($prefs,'charsetTo') && defined('DEFAULT_CHARSET_TO')) $prefs->charsetTo = DEFAULT_CHARSET_TO;

function cleanString($prefs,$input) {
  if(property_exists($prefs,'charsetTo')) return iconv('UTF-8', $prefs->charsetTo, $input);
  return $input;
}

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
  $date->dateShort = $d->format($prefs->dateShortFormat);
  $date->month = $d->format("F");
  $date->monthShort = $d->format("M");
  if($i==0) {
    if(property_exists($prefs,'latitude') && property_exists($prefs,'longitude')) {
      //built-in approach
      // $date->sun = date_sun_info($d->format('U'),$prefs->latitude,$prefs->longitude);
      // foreach($date->sun as $k=>$s) {
      //   if($s) $date->sun[$k] = date($prefs->timeFormat, $s);
      // }
      //more accurate approach that includes moon stuff
      $sc = new AurorasLive\SunCalc(new DateTime(), $prefs->latitude, $prefs->longitude);
      $scst = $sc->getSunTimes();
      $scmt = $sc->getMoonTimes();
      $scmi = $sc->getMoonIllumination();
      $date->sky = new stdClass();
      $date->sky->sunrise = $scst['sunrise']->format($prefs->timeFormat);
      $date->sky->sunset = $scst['sunset']->format($prefs->timeFormat);
      if($prefs->showDawnDusk) { //astronomical sunrise/sunset
        $date->sky->dawn = $scst['nightEnd']->format($prefs->timeFormat);
        $date->sky->dusk = $scst['night']->format($prefs->timeFormat);
      }
      $date->sky->moonfixed = (isset($scmt['alwaysUp'])&&$scmt['alwaysUp']?"Up":(isset($scmt['alwaysDown'])&&$scmt['alwaysDown']?"Down":false));
      if(!$date->sky->moonfixed) {
        $date->sky->moonrise = $scmt['moonrise']->format($prefs->timeFormat);
        $date->sky->moonset = $scmt['moonset']->format($prefs->timeFormat);
        $date->sky->moonupfirst = ($scmt['moonset'] < $scmt['moonrise']);
      }
      //$date->sky->moonphase = strval(floor($scmi['phase']*100)).'%'; //percentage
      $date->sky->moonphase = octophase($scmi['phase']);
      $date->sky->moonphaseName = phaseName(octophase($scmi['phase']));
    }
  } 
  $date->weather = array();
  $date->events = array();
  $c[$d->format('Y-m-d')] = $date;
}

function octophase($i) {
  //Converts moonphase from float (0 <= $i < 1)
  //to eighths, offset forward by a sixteenth so it's centered around the event
  //e.g. 1/16 to 3/16 = waxing crescent, 3/16 to 5/16 = first quarter...
  $o = floor((floor($i*16)+1)/2);
  if($o>=8) $o -= 8;
  return $o;
}
function phaseName($i) {
  switch($i) {
    case 0: return "New";
    case 1: return "1/8";
    case 2: return "1/4";
    case 3: return "3/8";
    case 4: return "Full";
    case 5: return "5/8";
    case 6: return "3/4";
    case 7: return "7/8";
    default: break;
  }
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
        $precipPos = strpos($p->detailedForecast,'Chance of precipitation is ');
        if($precipPos!==false) $precipPos += strlen('Chance of precipitation is ');
        $w->precipChance = intval($precipPos!==false? substr($p->detailedForecast,$precipPos,strpos(substr($p->detailedForecast,$precipPos),"%")) : 0);
        $w->shortForecast = cleanString($prefs,weatherReplace($p->shortForecast));
        $c[$dt]->weather[] = $w;
      }    
    }
  }
} //end if nws specified

function weatherReplace($in) {
  return str_replace(['Showers And Thunderstorms','Slight Chance','Chance','Partly'],['Rain','Ch','Ch','Pt'],$in);
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
      $event->summary = cleanString($prefs,$ie->summary);
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
        $event->timestart = $dstart->format($dstart->format('i')=='00' && property_exists($prefs,'timeFormatTopOfHour')? $prefs->timeFormatTopOfHour: $prefs->timeFormat);
        if($prefs->timeIncludeEnd) $event->timeend = $dend->format($dend->format('i')=='00' && property_exists($prefs,'timeFormatTopOfHour')? $prefs->timeFormatTopOfHour: $prefs->timeFormat);
      }
      //duration
      $ddiff = $dstart->diff($dend);
      $event->duration = $ddiff->days*24*60 + $ddiff->h*60 + $ddiff->i;
      //dates
      $event->dstart = $dstart->format('Y-m-d');
      $event->ldstart = $dstart->format('Ymd');
      $event->dstartShort = $dstart->format($prefs->dateShortFormat);
      //if it's an all-day event (e.g. duration is multiple of 1440), take a day off $dend
      if($event->allday) $dend->sub(new DateInterval("P1D"));
      $event->dend = $dend->format('Y-m-d');
      $event->ldend = $dend->format('Ymd');
      $event->dendShort = $dend->format($prefs->dateShortFormat);

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
      echo "<h2>$cd->weekdayShort $cd->monthShort $cd->date</h2>";
      //sunrise, sunset
      if(property_exists($cd,'sky')) echo "<p><strong>Sun ".$cd->sky->sunrise."</strong>&ndash;".$cd->sky->sunset." &nbsp; <strong>Moon</strong> ".(!$cd->sky->moonupfirst? "<strong>".$cd->sky->moonrise."</strong>&ndash;".$cd->sky->moonset: $cd->sky->moonset."/<strong>".$cd->sky->moonrise."</strong>")."&nbsp; ".$cd->sky->moonphase."</p>";
    } else {
      echo "<h3>$cd->weekdayShort $cd->monthShort $cd->date</h3>";
    }
    //weather
    echo "<ul style='list-style-type: none; padding-left: 0;'>";
    foreach($cd->weather as $cw) {
      echo "<li><strong>";
      echo ($cw->isDaytime?'High ':'Low ');
      //echo $cw->name.' ';
      echo $cw->temperature."°</strong>".($cw->precipChance?"/$cw->precipChance%":'')."&nbsp; ".$cw->shortForecast."</li>";
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