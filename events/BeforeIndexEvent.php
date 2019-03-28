<?php
namespace x51\yii2\modules\indexer\events;

use \yii\base\Event;

class BeforeIndexEvent extends Event
{
    public $module;
    public $model;
    public $isValid = true;
} // end class
