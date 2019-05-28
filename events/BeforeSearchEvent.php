<?php
namespace x51\yii2\modules\indexer\events;

use \yii\base\Event;

class BeforeSearchEvent extends Event
{
    public $module;
    public $origSearchStr;
    public $preparedSearchStr;
	public $role = '';
} // end class
