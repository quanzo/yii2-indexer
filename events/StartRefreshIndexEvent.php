<?php
namespace x51\yii2\modules\indexer\events;

use \yii\base\Event;

class StartRefreshIndexEvent extends Event
{
    public $module;
    public $url;
    public $title;
    public $content;
    public $isValid = true;
} // end class
