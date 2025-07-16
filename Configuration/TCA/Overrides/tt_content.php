<?php

/*
 * This file is part of the package jweiland/events2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

if (!defined('TYPO3')) {
    die('Access denied.');
}

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::registerPlugin(
    'events2_extended',
    'Calendar',
    'Großer Kalender',
    'ext-events2-wizard-icon',
    'plugins',
    '',
);

ExtensionManagementUtility::addToAllTCAtypes('tt_content', 'pages', 'events2extended_calendar', 'after:header');



ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;Configuration,pi_flexform',
    'events2extended_calendar',
    'after:subheader',
);
ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:events2_extended/Configuration/FlexForms/Calendar.xml',
    'events2extended_calendar',
);
