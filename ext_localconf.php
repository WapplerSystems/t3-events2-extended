<?php

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use WapplerSystems\Events2Extended\Controller\CalendarController;

if (!defined('TYPO3')) {
    die('Access denied.');
}


call_user_func(static function (): void {

    ExtensionUtility::configurePlugin(
        'events2_extended',
        'Calendar',
        [
            CalendarController::class => 'show',
        ],
        [],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
    );

});
