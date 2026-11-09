<?php

final class AphlictDropdownDataQuery extends Phobject {

  private $viewer;
  private $notificationData;

  public function setViewer(PhabricatorUser $viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function getViewer() {
    return $this->viewer;
  }

  private function setNotificationData(array $data) {
    $this->notificationData = $data;
    return $this;
  }

  public function getNotificationData() {
    if ($this->notificationData === null) {
      throw new Exception(pht('You must %s first!', 'execute()'));
    }
    return $this->notificationData;
  }

  public function execute() {
    $viewer = $this->getViewer();

    $notification_app = 'PhabricatorNotificationsApplication';
    $is_n_installed = PhabricatorApplication::isClassInstalledForViewer(
      $notification_app,
      $viewer);
    if ($is_n_installed) {
      $raw_notification_count_number = $viewer->getUnreadNotificationCount();
      $notification_count_number = $this->formatNumber(
        $raw_notification_count_number);
    } else {
      $notification_count_number = null;
      $raw_notification_count_number = null;
    }

    $notification_data = array(
      'isInstalled' => $is_n_installed,
      'countType' => 'notifications',
      'count' => $notification_count_number,
      'rawCount' => $raw_notification_count_number,
    );
    $this->setNotificationData($notification_data);

    return array(
      $notification_app => $this->getNotificationData(),
    );
  }

  private function formatNumber($number) {
    $formatted = $number;
    if ($number > 999) {
      $formatted = "\xE2\x88\x9E";
    }
    return $formatted;
  }

}
