<?php


class SiteScheduleAPIModule extends APIModule {
  protected $id = 'schedule';
  
  private function formatEventResponse($schedule, $event) {
    $info = $schedule->getEventInfo($event);
    
    $info['start']  = $event->get_start();
    $info['end']    = $event->get_end();
    $info['allday'] = $event->get_attribute('datetime') instanceOf DayRange;
    unset($info['datetime']);
    
    return $info;
  }
  
  public function initializeForCommand() {
    $user = $this->getUser('HarvardReunionUser');
    $schedule = new Schedule($user);

    switch ($this->command) {
      case 'categories':
        $response = array();

        $categories = $schedule->getEventCategories();
        foreach ($categories as $id => $title) {
          $response[] = array(
            'id'    => $id,
            'title' => $title,
          );
        }

        $this->setResponse($response);
        $this->setResponseVersion(1);
        break;
    
      case 'events':
        $response = array();
        $category  = $this->getArg('category', $schedule->getDefaultCategory());
        
        $feed = $schedule->getEventFeed();
        $events = $feed->items(0);

        foreach($events as $event) {
          if ($schedule->eventMatchesCategory($event, $category)) {
            $response[] = $this->formatEventResponse($schedule, $event);
          }
        }
        
        $this->setResponse($response);
        $this->setResponseVersion(1);
        break;

      case 'detail':
        $response = array();
      
        $id    = $this->getArg('id');
        $start = $this->getArg('start', time());
        
        $feed = $schedule->getEventFeed();      
        $event = $feed->getItem($id, $start);
        if (!$event) {
          $error = new KurogoError(
            5,
            'Invalid Request',
            'Invalid id parameter supplied');
          $this->throwError($error);
        }
        
        $response = $this->formatEventResponse($schedule, $event);
        
        $this->setResponse($response);
        $this->setResponseVersion(1);
        break;

      case 'attendees':
        $this->setResponse($schedule->getAllAttendees());
        $this->setResponseVersion(1);
        break;


      default:
        $this->invalidCommand();
        break;
    }
  }
}


