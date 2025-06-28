<?php

declare(strict_types=1);


namespace WapplerSystems\Events2Extended\Controller;

use JWeiland\Events2\Controller\AbstractController;
use JWeiland\Events2\Traits\InjectCalendarHelperTrait;
use JWeiland\Events2\Traits\Typo3RequestTrait;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class CalendarController extends AbstractController
{
    use InjectCalendarHelperTrait;
    use Typo3RequestTrait;

    public function showAction(): ResponseInterface
    {
        $frameworkConfiguration = $this->getMergedFrameworkConfiguration();

        $calendarVariables = $this->calendarHelper->getCalendarVariables();
        $calendarVariables['settings'] = $this->settings;
        $calendarVariables['storagePids'] = $frameworkConfiguration['persistence']['storagePid'];
        $calendarVariables['pidOfListPage'] = $this->settings['pidOfListPage'];
        if (!$calendarVariables['pidOfListPage']) {
            $calendarVariables['pidOfListPage'] = $this->getTypoScriptFrontendController($this->request)->id;
        }

        $this->postProcessAndAssignFluidVariables([
            'environment' => $calendarVariables,
        ]);

        return $this->htmlResponse();
    }

    /**
     * Returns the merged (TypoScript + FlexForm) plugin configuration
     */
    protected function getMergedFrameworkConfiguration(): array
    {
        return $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
        );
    }
}
