<?php

class AttendeesWebModule extends WebModule
{
  protected $id = 'attendees';
  
  protected function initializeForPage() {
    $user = $this->getUser('HarvardReunionUser');
    $schedule = new Schedule($user);
    
    switch ($this->page) {
      case 'index':
        $attendeeNames = array();
        foreach ($schedule->getAllAttendees() as $attendee) {
          if ($attendee['display_name']) {
            $attendeeNames[] = array(
              'title' => $attendee['display_name'],
            );
          }
        }
    
        $this->assign('reunionTitle', $schedule->getReunionTitle());
        $this->assign('authority',    $user->getAuthenticationAuthorityIndex());
        $this->assign('attendees',    $attendeeNames);
        break;
    }
  }
}
