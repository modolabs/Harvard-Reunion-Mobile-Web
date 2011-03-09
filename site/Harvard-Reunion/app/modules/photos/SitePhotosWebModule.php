<?php
/**
  * @package Module
  * @subpackage Schedule
  */
/**
  */
includePackage('Authentication');
  
class SitePhotosWebModule extends WebModule {
  protected $id = 'photos';
  protected $schedule = null;

  protected function initializeForPage() {
    $user = $this->getUser();
    $session = $user->getSessionData();
    
    $this->schedule = new Schedule();
    $facebook = new FacebookGroup($this->schedule->getFacebookGroupId(), $session['fb_access_token']);
    
    switch ($this->page) {
      case 'help':
        break;

      case 'index':
        $photos = $facebook->getGroupPhotos();
        foreach ($photos as $i => $photo) {
          $photos[$i]['url'] = $this->buildBreadcrumbURL('detail', array( 
            'id' => $photo['id'],
          ));
        }

        $this->assign('user',      $user->getFullName());
        $this->assign('logoutURL', self::buildURLForModule('login', 'logout', array(
          'authority' => 'facebook'
        )));

        $this->assign('title',     $facebook->getGroupFullName());
        $this->assign('photos',    $photos);
        break;
              
      case 'detail':
        $this->assign('photo', $facebook->getPhotoPostDetails($this->getArg('id')));
        break;
    }
  }
}
