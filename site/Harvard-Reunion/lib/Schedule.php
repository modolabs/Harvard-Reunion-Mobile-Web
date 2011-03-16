<?php

/**
  */
includePackage('Calendar');

class Schedule {
  private $scheduleId = '';
  private $scheduleConfig = array();
  private $startDate = null;
  private $endDate = null;
  private $attendee = null;
  private $timezone = null;

  static private function getScheduleConfig() {
    $configFile = realpath_exists(SITE_CONFIG_DIR.'/schedule/feeds.ini');
    
    return $configFile ? parse_ini_file($configFile, true) : array();
  }

  static public function getAllReunionYears() {
    $scheduleConfigs = self::getScheduleConfig();
    
    $reunionYears = array();
    foreach ($scheduleConfigs as $year => $config) {
      $reunionYears[] = array(
        'year'     => $year,
        'number'   => $config['REUNION_NUMBER'],
        'separate' => is_array($config['REUNION_TITLE']),
      );
    }
    
    return $reunionYears;
  }

  function __construct($user) {
    $this->timezone = new DateTimeZone(
      $GLOBALS['siteConfig']->getVar('LOCAL_TIMEZONE', Config::LOG_ERRORS | Config::EXPAND_VALUE));
  
    $scheduleConfigs = self::getScheduleConfig();
    
    $this->attendee = $user;
    $this->scheduleId = $this->attendee->getGraduationClass();
    
    if (isset($scheduleConfigs[$this->scheduleId])) {
      $this->scheduleConfig = $scheduleConfigs[$this->scheduleId];
      
      $collegeIndex = $this->attendee->getCollegeIndex();
      foreach ($this->scheduleConfig as $key => $value) {
        if (is_array($value) && isset($value[$collegeIndex])) {
          $this->scheduleConfig[$key] = $value[$collegeIndex];
        }
      }
      
      $this->startDate = $this->getDateTimeForDate($this->getConfigValue('START_DATE', ''));
      $this->endDate   = $this->getDateTimeForDate($this->getConfigValue('END_DATE', ''));
    }
  }
  
  private function getDateTimeForDate($date) {
    return new DateTime($date.' 00:00:00', $this->timezone);
  }
  
  private function getConfigValue($key, $default=null) {
    return isset($this->scheduleConfig[$key]) ? $this->scheduleConfig[$key] : $default;
  }

  public function getScheduleId() {
    return $this->scheduleId;
  }
  
  public function getAttendee() {
    return $this->attendee;
  }
  
  public function getReunionNumber() {
    return $this->getConfigValue('REUNION_NUMBER', '0');
  }
  
  public function getReunionTitle() {
    return $this->getConfigValue('REUNION_TITLE', '');
  }
  
  public function getFacebookGroupName() {
    return $this->getConfigValue('FACEBOOK_GROUP_NAME', '');
  }
  public function getFacebookGroupId() {
    return $this->getConfigValue('FACEBOOK_GROUP_ID', '');
  }
  
  public function getTwitterHashTag() {
    return $this->getConfigValue('TWITTER_HASHTAG', '');
  }
  public function getStartDate() {
    return $this->startDate;
  }
  public function getEndDate() {
    return $this->endDate;
  }
  
  public function getDateDescription() {
    if (isset($this->startDate, $this->endDate)) {
      $startMonth = $this->startDate->format('M');
      $startDay   = $this->startDate->format('j');
      $endMonth   = $this->endDate->format('M');
      $endDay     = $this->endDate->format('j');
      
      if ($startMonth == $endMonth) {
        return "$startMonth $startDay-$endDay";
      } else {
        return "$startMonth $startDay-$endMonth $endDay";
      }
    } else {
      return '';
    }
  }
  
  public function getEventFeed() {
    $controllerClass = $this->getConfigValue('CONTROLLER_CLASS', 'CalendarDataController');
    
    $controller = CalendarDataController::factory($controllerClass, $this->scheduleConfig);
    $controller->setDebugMode($GLOBALS['siteConfig']->getVar('DATA_DEBUG', Config::LOG_ERRORS | Config::EXPAND_VALUE));

    $endDate = new DateTime($this->endDate->format('Y-m-d').' 00:00:00 +1 day', $this->timezone);
    $controller->setStartDate($this->startDate);
    $controller->setEndDate($endDate);
    
    return $controller;
  }
}
