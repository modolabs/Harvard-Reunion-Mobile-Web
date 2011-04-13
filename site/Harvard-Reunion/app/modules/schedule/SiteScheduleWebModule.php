<?php
/**
  * @package Module
  * @subpackage Schedule
  */

includePackage('Maps');

define('SCHEDULE_COOKIE_DURATION', 160 * 24 * 60 * 60);

class SiteScheduleWebModule extends WebModule {
  protected $id = 'schedule';
  protected $user = null;
  protected $schedule = null;
  protected $bookmarks = array();
  const BOOKMARKS_COOKIE_PREFIX   = 'ScheduleBookmarks_';
  const BOOKMARKS_COOKIE_DURATION = SCHEDULE_COOKIE_DURATION;
  const CATEGORY_COOKIE_PREFIX    = 'ScheduleCategory_';
  const CATEGORY_COOKIE_DURATION  = SCHEDULE_COOKIE_DURATION;
  
  protected function getCategory($categories) {
    $category = $this->schedule->getDefaultCategory();
    
    // '.' in cookie names doesn't work properly with the PHP $_COOKIE variable
    $categoryCookieName = self::CATEGORY_COOKIE_PREFIX.
      urlencode(str_replace(array('.', ':'), array('_', '_'), $this->user->getUserID()));
    
    if (isset($this->args['category'], $categories[$this->args['category']])) {
      $category = $this->args['category'];
      
      // Remember cookie
      $expires = time() + self::CATEGORY_COOKIE_DURATION;
      setCookie($categoryCookieName, $category, $expires, COOKIE_PATH);
      
    } else if (isset($_COOKIE[$categoryCookieName], $categories[$_COOKIE[$categoryCookieName]])) {
      $category = $_COOKIE[$categoryCookieName];
    }
    
    return $category;
  }
  
  protected function getBookmarkCookie() {
    return self::BOOKMARKS_COOKIE_PREFIX.$this->schedule->getScheduleId();
  }
  
  private function valueForType($type, $value) {
    $valueForType = $value;
  
    switch ($type) {
      case 'datetime':
        $allDay = $value instanceOf DayRange;
        $sameAMPM = date('a', $value->get_start()) == date('a', $value->get_end());
        $sameDay = false;
        if ($value->get_end() && $value->get_end() != $value->get_start()) {
          $startDate = intval(date('Ymd', $value->get_start()));
          $endDate = intval(date('Ymd', $value->get_end()));
          
          $sameDay = $startDate == $endDate;
          if (!$sameDay) {
            $endIsBefore5am = intval(date('H', $value->get_end())) < 5;
            if ($endIsBefore5am && ($endDate - $startDate == 1)) {
              $sameDay = true;
            }
          }
        }
        $valueForType = date("l, F j", $value->get_start());
        if ($allDay) {
          if (!$sameDay) {
            $valueForType .= date(" - l, F j", $value->get_end());
          }
        } else {
          $valueForType .= ($sameDay ? '<br/>' : ', ').date('g:i', $value->get_start());
          if (!$sameAMPM) {
            $valueForType .= date('a', $value->get_start());
          }
          if (!$sameDay) {
            $valueForType .= date(" - l, F j, ", $value->get_end());
          } else if ($sameAMPM) {
            $valueForType .= '-';
          } else {
            $valueForType .= ' - ';
          }
          $valueForType .= date("g:ia", $value->get_end());
        }
        break;

      case 'url':
        $valueForType = preg_replace(
          array(';http://([^/]+)/$;', ';http://;'), 
          array('\1',                 ''), $value);
        break;
        
      case 'phone':
        // add the local area code if missing
        if (preg_match('/^\d{3}-\d{4}/', $value)) {
          $valueForType = $this->getSiteVar('LOCAL_AREA_CODE').$value;
        }
        $valueForType = str_replace('-', '-&shy;', str_replace('.', '-', $value));
        break;
      
      case 'email':
        $valueForType = str_replace('@', '@&shy;', $value);
        break;
    }
    
    return $valueForType;
  }
  
  private function urlForType($type, $value) {
    $urlForType = null;
  
    switch ($type) {
      case 'url':
        $urlForType = str_replace("http://http://", "http://", $value);
        if (strlen($urlForType) && !preg_match('/^http\:\/\//', $urlForType)) {
          $urlForType = 'http://'.$urlForType;
        }
        break;
        
      case 'phone':
        // add the local area code if missing
        if (preg_match('/^\d{3}-\d{4}/', $value)) {
          $urlForType = $this->getSiteVar('LOCAL_AREA_CODE').$value;
        }
    
        // remove all non-word characters from the number
        $urlForType = 'tel:1'.preg_replace('/\W/', '', $value);
        break;
        
      case 'email':
        $urlForType = "mailto:$value";
        break;
    }
    
    return $urlForType;
  }

  private function timeText($event, $timeOnly=false) {
    if ($timeOnly) {
      $sameAMPM = date('a', $event->get_start()) == date('a', $event->get_end());
    
      $timeString = date(' g:i', $event->get_start());
      if (!$sameAMPM) {
        $timeString .= date('a', $event->get_start());
      }
      $timeString .= ($sameAMPM ? '-' : ' - ').date("g:ia", $event->get_end());
      
      return $timeString;
    } else {
      return strval($event->get_range());
    }
  }
  
  private function detailURL($event, $addBreadcrumb=true, $noBreadcrumbs=false) {
    $args = array(
      'eventId' => $event->get_uid(),
      'start'   => $event->get_start()
    );
  
    if ($noBreadcrumbs) {
      return $this->buildURL('detail', $args);
    } else {
      return $this->buildBreadcrumbURL('detail', $args, $addBreadcrumb);
    }
  }

  private function titleForAttendeeCount($event) {
    $attendeeCount = $this->schedule->getAttendeeCountForEvent($event);
    if ($this->schedule->isRegisteredForEvent($event)) {
      $otherCount = $attendeeCount - 1;
      return "$otherCount other".($otherCount == 1 ? '' : 's').' attending';
    }

    // We're not attending, just these people
    return "$attendeeCount ".($attendeeCount == 1 ? 'person' : 'people').' attending';
  }


  protected function initialize() {
    $this->user = $this->getUser('HarvardReunionUser');
    $this->schedule = new Schedule($this->user);
  }

  protected function initializeForPage() {    
    $scheduleId = $this->schedule->getScheduleId();

    switch ($this->page) {
      case 'help':
        break;

      case 'index':
        $categories = array(
          'mine' => 'My Schedule'
        );
        $categories = array_merge(
          array('mine' => 'My Schedule'), 
          $this->schedule->getEventCategories()
        );
        $category = $this->getCategory($categories);
        
        if ($category == 'mine') {
          $events = $this->schedule->getEvents();
          foreach ($events as $i => $event) {
            if (!$this->hasBookmark($event->get_uid()) &&
                !$this->schedule->isRegisteredForEvent($event)) {
              unset($events[$i]);
            }
          }
        } else {
          $events = $this->schedule->getEvents($category);
        }
        
        $eventDays = array();
        foreach($events as $event) {
          $date = date('Y-m-d', $event->get_start());
          
          if (!isset($eventDays[$date])) {
            $eventDays[$date] = array(
              'title'  => date('l, F j, Y', $event->get_start()),
              'events' => array(),
            );
          }
          
          $eventInfo = array(
            'url'      => $this->detailURL($event),
            'title'    => $event->get_summary(),
            'subtitle' => $this->timeText($event, true),
          );
          if ($this->hasBookmark($event->get_uid())) {
            $eventInfo['class'] = 'bookmarked';
          }
          
          if ($this->schedule->isRegisteredForEvent($event)) {
            $eventInfo['class'] = 'bookmarked';
          }
          
          $eventDays[$date]['events'][] = $eventInfo;
        }
        
        $this->assign('category',   $category);        
        $this->assign('categories', $categories);        
        $this->assign('eventDays',  $eventDays);        
        break;
              
      case 'detail':
        $eventId    = $this->getArg('eventId');
        $start      = $this->getArg('start', time());
                
        $event = $this->schedule->getEvent($eventId, $start);
        if (!$event) {
          throw new Exception("Event not found");
        }
        
        $this->generateBookmarkOptions($event->get_uid());

        //error_log(print_r($event, true));
        $info = $this->schedule->getEventInfo($event);
        $registered = false;
        $requiresRegistration = false;
        //error_log(print_r($info, true));

        $sections = array();
        
        // Info
        $locationSection = array();
        if ($info['location']) {
          $location = array(
            'title' => self::argVal($info['location'], 'title', ''),
          );
          if (strtoupper($location['title']) == 'TBA') {
            $location['title'] = 'Location '.$location['title'];
          }
          if (isset($info['location']['address'])) {
            $parts = array();
            if (isset($info['location']['address']['street'])) {
              $parts[] = $info['location']['address']['street'];
            }
            if (isset($info['location']['address']['city'])) {
              $parts[] = $info['location']['address']['city'];
            }
            if (isset($info['location']['address']['state'])) {
              $parts[] = $info['location']['address']['state'];
            }
            if ($parts) {
              $location['subtitle'] = implode(', ', $parts);
            }
          }
          if (isset($info['location']['building']) || isset($info['location']['latlon'])) {
            $location['url'] = $this->buildURLForModule('map', 'detail', array(
              'eventId' => $eventId,
              'start'   => $start,
            ));
            $location['class'] = 'map';
          }
          $locationSection[] = $location;
        }
        if ($locationSection) {
          $sections['location'] = $locationSection; 
        }
        
        $registrationSection = array();
        if ($info['registration']) {
          $requiresRegistration = true;
          $registration = array(
            'title' => 'Registration Required',
            'class' => 'external register',
          );
          
          if ($info['registration']['registered']) {
            $registered = true;
            
            if ($this->pagetype == 'basic') {
              $registration['title'] = '<img src="/common/images/badge-confirmed.gif"/> Registration Confirmed';
            } else {
              // No <a> tag so we need to wrap in a div
              $registration['title'] = '<div class="register confirmed"><div class="icon"></div>Registration Confirmed</div>';
            }
          } else {
            if ($this->pagetype == 'basic') {
              $registration['label'] = '<img src="/common/images/badge-register.gif"/> ';
            } else {
              $registration['title'] = '<div class="icon"></div>'.$registration['title'];
            }

            if (isset($info['registration']['url'])) {
              $printableURL = preg_replace(
                array(';http://([^/]+)/$;', ';http://;'), 
                array('\1',                 ''), $info['registration']['url']);
    
              $registration['url'] = $info['registration']['url'];
              $registration['linkTarget'] = 'reunionAlumni';
              $registration['subtitle'] = 'Register online at '.$printableURL;
            }
            if (isset($info['registration']['fee'])) {
              $registration['title'] .= ' ('.$info['registration']['fee'].')';
            }
          }
          $registrationSection[] = $registration;
        }

        if (isset($info['attendees']) && count($info['attendees'])) {
          $registrationSection[] = array(
            'title' => $this->titleForAttendeeCount($event),
            'url'   => $this->buildBreadcrumbURL('attendees', array(
              'eventId' => $eventId,
              'start'   => $start,
            )),
          );
        }
        if ($registrationSection) {
          $sections['registration'] = $registrationSection; 
        }
        
        // Other fields
        $fieldConfig = $this->loadPageConfigFile('detail', 'detailFields');
        foreach ($fieldConfig as $key => $fieldInfo) {
          if (isset($info[$key])) {
            $type       = self::argVal($fieldInfo, 'type', 'text');
            $section    = self::argVal($fieldInfo, 'section', 'misc');
            $label      = self::argVal($fieldInfo, 'label', '');
            $class      = self::argVal($fieldInfo, 'class', '');
            $linkTarget = self::argVal($fieldInfo, 'linkTarget', '');
            
            $title = $this->valueForType($type, $info[$key]);
            $url = $this->urlForType($type, $info[$key]);

            $item = array();

            if ($label) {
              $item['title'] = $label;
              $item['subtitle'] = $title;
            } else {
              $item['title'] = $title;
            }

            if ($url) {
              $item['url'] = $url;
            }

            if ($linkTarget) {
              $item['linkTarget'] = $linkTarget;
            }

            if ($class) {
              $item['class'] = $class;
            }
            
            if (!isset($sections[$section])) {
              $sections[$section] = array();
            }
            $sections[$section][] = $item;
          }          
        }
        //error_log(print_r($sections, true));
        
        $latitude = 0;
        $longitude = 0;
        if (isset($info['location']['latlon'])) {
          list($latitude, $longitude) = $info['location']['latlon'];
        }
        
        // Checkins
        $checkedIn = false;
        $checkinThresholdStart = $event->get_start() - 60*15;
        $checkinThresholdEnd = $event->get_end() + 60*15;
        
        // debugging:
        $checkinThresholdStart = time() - ($event->get_end() - $event->get_start()) - 60*15;
        $checkinThresholdEnd = $checkinThresholdStart + ($event->get_end() - $event->get_start()) + 60*15;
        
        $checkedIn = false;
        if (isset($info['location'], $info['location']['foursquareId'])) {        
          $foursquare = $this->schedule->getFoursquareFeed();
          if (!$foursquare->needsLogin()) {
            $checkedIn = $foursquare->isCheckedIn($info['location']['foursquareId'], $checkinThresholdStart);
          }
          
          if ($checkedIn) { 
            $this->assign('checkedIn', true);
          }

          $this->assign('checkinURL', $this->buildBreadcrumbURL('checkin', array(
            'eventURL'   => FULL_URL_PREFIX.ltrim(
              $this->buildBreadcrumbURL($this->page, $this->args, false), '/'),
            'eventTitle' => $info['title'],
            'venue'      => $info['location']['foursquareId'],
            'latitude'   => $latitude, 
            'longitude'  => $longitude
          )));
        }
        
        $this->assign('eventId',              $eventId);
        $this->assign('eventTitle',           $info['title']);
        $this->assign('eventDate',            $this->valueForType('datetime', $info['datetime']));
        $this->assign('sections',             $sections);
        $this->assign('registered',           $registered);
        $this->assign('requiresRegistration', $requiresRegistration);
        //error_log(print_r($sections, true));
        break;
        
      case 'attendees':
        $eventId = $this->getArg('eventId');
        $start   = $this->getArg('start', time());
        $range = $this->getArg('range', null);

        $event = $this->schedule->getEvent($eventId, $start);
        if (!$event) {
          throw new Exception("Event not found");
        }
        //error_log(print_r($event, true));
        $info = $this->schedule->getEventInfo($event);
        //error_log(print_r($info, true));
        
        $allAttendees = $info['attendees'];
      
        $letterGroups = $this->schedule->getAttendeeFirstLetterGroups($allAttendees);
        if (!$letterGroups || $range) {
          $filtered = $allAttendees;
          if ($range) {
            $printableRange = implode(' - ', explode('-', $range));
            $this->setPageTitle($this->getPageTitle()." ($printableRange)");
            $this->setBreadcrumbTitle($this->getBreadcrumbTitle()." ($printableRange)");
            $this->setBreadcrumbLongTitle($this->getBreadcrumbLongTitle()." ($printableRange)");
            $filtered = $this->schedule->getAttendeesForLetterRange($allAttendees, $range);
          }
          
          $attendees = array();
          foreach ($filtered as $attendee) {
            if ($attendee['display_name']) {
              $attendees[] = array(
                'title' => $attendee['display_name'],
              );
            }
          }
          $this->assign('attendees',  $attendees);
           
        } else {
          $args = $this->args;
        
          $groups = array();
          foreach ($letterGroups as $range => $rangeInfo) {
            $args['range'] = $range;
            
            $rangeInfo['url'] = $this->buildBreadcrumbURL('attendees', $args);
            $groups[] = $rangeInfo;
          }
          
          $this->assign('groups',  $groups);
        }
        
        $this->assign('eventTitle', $info['title']);
        $this->assign('eventDate',  $this->valueForType('datetime', $info['datetime']));        
        $this->assign('authority',  $this->user->getAuthenticationAuthorityIndex());
        break;
        
      case 'checkin':
        $venue = $this->getArg('venue');
        $foursquare = $this->schedule->getFoursquareFeed();
        
        $venueCheckinState = $foursquare->getVenueCheckinState($venue);
        if ($venueCheckinState) {
          $this->assign('state', $venueCheckinState);
        }
        
        $this->addOnLoad('initCheckins();');        
        $this->addInlineJavascript(
          'var CHECKIN_CONTENT_URL = "'.URL_PREFIX.$this->id.'/checkinContent?'.
            http_build_query(array('venue' => $venue)).'"');
      
        $this->assign('eventTitle', $this->getArg('eventTitle'));
        $this->assign('hiddenArgs', array(
          'venue'     => $venue,
          'latitude'  => $this->getArg('latitude'),
          'longitude' => $this->getArg('longitude'),
          'eventURL'  => $this->getArg('eventURL'),
        ));
        break;
        
      case 'checkinContent':
        $venue = $this->getArg('venue');
        $foursquare = $this->schedule->getFoursquareFeed();
        
        $venueCheckinState = $foursquare->getVenueCheckinState($venue);
        if ($venueCheckinState) {
          $this->assign('state', $venueCheckinState);
        }
        break;
      
      case 'addCheckin':
        $venue     = $this->getArg('venue');
        $message   = $this->getArg('message');
        $latitude  = $this->getArg('latitude');
        $longitude = $this->getArg('longitude');
        $eventURL  = $this->getArg('eventURL');
        
        if ($latitude && $longitude) {
          $foursquare = $this->schedule->getFoursquareFeed();
          $foursquare->addCheckin($venue, $message, array($latitude, $longitude));
        }
        
        if ($eventURL) {
          header("Location: $eventURL");
          exit();
        } else {
          $this->redirectTo('index');
        }
        break;
    }
  }
  
}
