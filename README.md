# iot-calendar-server

PHP web app that generates content for [iot-calendar-client](https://github.com/clockspot/iot-calendar-client) devices to consume. Given a correct auth key from the client, it will process NWS and iCal data from predefined sources (specified in the settings file) and return it as a combined JSON object.

`/cal` is a subset of that: given a correct auth key and iCal source URL (in theory), it will process that source and return it as JSON, in imitation of the [icalendar.org IoT Calendar Service](https://icalendar.org/iot.html). This is/was intended for use with a client that does more of the data fetching and processing on its own (e.g. [nano-test-local](https://github.com/clockspot/iot-calendar-client/tree/master/nano-test-local)), but at this writing I'm prioritizing the "full service" approach described above.

iCal data is parsed with the [PHP ics-parser](https://github.com/u01jmg3/ics-parser) library.