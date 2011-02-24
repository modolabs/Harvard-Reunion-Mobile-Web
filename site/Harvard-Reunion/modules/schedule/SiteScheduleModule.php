<?php
/**
  * @package Module
  * @subpackage Schedule
  */

/**
  * @package Module
  * @subpackage Schedule
  */
  
define('SCHEDULE_BOOKMARKS_COOKIE_PREFIX', 'ScheduleBookmarks');
define('SCHEDULE_BOOKMARKS_COOKIE_DURATION', 160 * 24 * 60 * 60);

class SiteScheduleModule extends Module {
  protected $id = 'schedule';
  protected $feeds = null;
  protected $schedule = null;
  protected $bookmarks = array();

  private function getCookieNameForEvent($event) {
    return SCHEDULE_BOOKMARKS_COOKIE_PREFIX.$event;
  }

  private function getBookmarks($scheduleId) {
    $cookieName = $this->getCookieNameForEvent($scheduleId);
    
    if (!isset($this->bookmarks[$cookieName])) {
      if (isset($_COOKIE[$cookieName])) {
        $this->bookmarks[$cookieName] = array_unique(explode(',', $_COOKIE[$cookieName]));
      } else {
        $this->bookmarks[$cookieName] = array();
      }
    }
    
    return $this->bookmarks[$cookieName];
  }
  
  private function setBookmarks($scheduleId, $bookmarks) {
    $cookieName = $this->getCookieNameForEvent($scheduleId);
    
    setcookie($cookieName, implode(',', array_unique($bookmarks)), 
      time() + SCHEDULE_BOOKMARKS_COOKIE_DURATION, COOKIE_PATH);

    $this->bookmarks[$cookieName] = $bookmarks;
  }

  private function isBookmarked($scheduleId, $eventId) {
    return in_array($eventId, $this->getBookmarks($scheduleId));
  }
  
  private function checkToggleBookmark($scheduleId, $eventId) {
    if ($this->getArg('toggleBookmark')) {
      $bookmarks = array_fill_keys($this->getBookmarks($scheduleId), true);
    
      if (isset($bookmarks[$eventId])) {
        unset($bookmarks[$eventId]);
      } else {
        $bookmarks[$eventId] = true;
      }
      $this->setBookmarks($scheduleId, array_keys($bookmarks));
        
      $args = $this->args;
      unset($args['toggleBookmark']);
      $this->redirectTo($this->page, $args);
    }
  }
  
  private function valueForType($type, $value) {
    $valueForType = $value;
  
    switch ($type) {
      case 'datetime':
        if ($value instanceOf DayRange) {
          $valueForType = strval($value);
        } else {
          $valueForType = date("D M j", $value->get_start());
          if ($value->get_end() && $value->get_end()!=$value->get_start()) {
            if (date('Ymd', $value->get_start()) != date('Ymd', $value->get_end())) {
              $valueForType .= date(' g:i', $value->get_start());
              if (date('a', $value->get_start()) != date('a', $value->get_end())) {
                $valueForType .= date(' a', $value->get_start());
              }
        
              $valueForType .= date(" - D M j g:i a", $value->get_end());
            } else {
              $valueForType .= "<br/>" . date('g:i', $value->get_start()) . date("-g:i a", $value->get_end());
            }
          } else {
            $valueForType .= "<br/>" . date('g:i a', $value->get_start());
          }
        }
        
        break;

      case 'url':
        $valueForType = str_replace("http://http://", "http://", $value);
        if (strlen($valueForType) && !preg_match('/^http\:\/\//', $valueForType)) {
          $valueForType = 'http://'.$valueForType;
        }
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

  private function timeText($iCalEvent, $timeOnly=false) {
    if ($timeOnly) {
      if ($iCalEvent->get_end() - $iCalEvent->get_start() == -1) {
        return date('g:ia', $iCalEvent->get_start());
      } else {
        return date('g:ia', $iCalEvent->get_start()).' - '.date('g:ia', $iCalEvent->get_end());
      }
    } else {
      return strval($iCalEvent->get_range());
    }
  }
  
  private function detailURL($scheduleId, $iCalEvent, $addBreadcrumb=true, $noBreadcrumbs=false) {
    $args = array(
      'scheduleId' => $scheduleId,
      'eventId'    => $iCalEvent->get_uid(),
      'start'      => $iCalEvent->get_start()
    );
  
    if ($noBreadcrumbs) {
      return $this->buildURL('detail', $args);
    } else {
      return $this->buildBreadcrumbURL('detail', $args, $addBreadcrumb);
    }
  }

  protected function initialize() {
    $this->schedule = new Schedule();
  }

  protected function initializeForPage() {
    switch ($this->page) {
      case 'help':
        break;

      case 'index':
        $day  = $this->getArg('day', 'all');
        $scheduleId = $this->getArg('scheduleId', $this->schedule->getScheduleId());
        
        $feed = $this->schedule->getEventFeed();
        
        $iCalEvents = $feed->items(0);
        
        $eventDays = array();
        $days = array();
        foreach($iCalEvents as $iCalEvent) {
          $date = date('Y-m-d', $iCalEvent->get_start());
          $showThisDate = $day == 'all' || $day == $date;
          
          if (!isset($eventDays[$date])) {
            $dateString = date('l, F j, Y', $iCalEvent->get_start());
            
            if ($showThisDate) {
              $eventDays[$date] = array(
                'title'  => $dateString,
                'events' => array(),
              );
            }
            $days[$date] = $dateString;
          }
          
          if ($showThisDate) {
            $subtitle = $this->timeText($iCalEvent, true);
            if ($briefLocation = $iCalEvent->get_location()) {
              $subtitle .= " | $briefLocation";
            }
            
            $bookmarked = true;

            $event = array(
              'url'      => $this->detailURL($scheduleId, $iCalEvent),
              'title'    => $iCalEvent->get_summary(),
              'subtitle' => $subtitle,
            );
            if ($this->isBookmarked($scheduleId, $iCalEvent->get_uid())) {
              $event['class'] = 'bookmarked';
            }            
            
            $eventDays[$date]['events'][] = $event;
          }
        }
        
        $this->assign('day',       $day);        
        $this->assign('days',      $days);        
        $this->assign('eventDays', $eventDays);        
        break;
              
      case 'detail':
        $scheduleId = $this->getArg('scheduleId', $this->schedule->getScheduleId());
        $eventId    = $this->getArg('eventId');
        $start      = $this->getArg('start', time());
        
        $this->checkToggleBookmark($scheduleId, $eventId);
        
        $itemInfo = array(
          'bookmarked'     => $this->isBookmarked($scheduleId, $eventId),
          'eventId'        => $eventId,
          'cookie'         => $this->getCookieNameForEvent($scheduleId),
          'cookieDuration' => SCHEDULE_BOOKMARKS_COOKIE_DURATION,
        );
        
        $feed = $this->schedule->getEventFeed();       
        $event = $feed->getItem($eventId, $start);
        if (!$event) {
          throw new Exception("Event not found");
        }
        //error_log(print_r($event, true));
        
        // build the list of attributes
        $fieldConfig = $this->loadWebAppConfigFile('schedule-detail', 'detailFields');
        $allKeys = array_keys($fieldConfig);
        $sections = array();
        foreach ($fieldConfig as $key => $info) {
          $field = array(
            'key' => $key,
          );
          
          $value = $event->get_attribute($key);
          if (empty($value)) { continue; }

          if (isset($info['label'])) {
            $field['label'] = $info['label'];
          }
          
          if (isset($info['class'])) {
            $field['class'] = $info['class'];
          }
          
          if (is_array($value)) {		
            $fieldValues = array();
            foreach ($value as $item) {
              $fieldValue = '';
              $fieldValueUrl = null;
              
              if (isset($info['type'])) {
                $fieldValue  = $this->valueForType($info['type'], $item);
                $fieldValueUrl = $this->urlForType($info['type'], $item);
              } else {
                $fieldValue = $item;
              }
              
              if (isset($fieldValueUrl)) {
                $fieldValue = '<a href="'.$fieldValueUrl.'">'.$fieldValue.'</a>';
              }
              
              $fieldValues[] = $fieldValue;
            }
            $field['title'] = implode(', ', $fieldValues);
          
          } else {
            if (isset($info['type'])) {
              $field['title'] = $this->valueForType($info['type'], $value);
              $field['url']   = $this->urlForType($info['type'], $value);
            } else {
              $field['title'] = nl2br($value);
            }
          }
          
          if (!isset($sections[$info['section']])) {
            $sections[$info['section']] = array();
          }
          
          $sections[$info['section']][] = $field;
        }

        $this->assign('itemInfo', $itemInfo);
        $this->assign('sections', $sections);
        //error_log(print_r($sections, true));
        break;
    }
  }
}
