<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Tests\Acceptance\Application\InstallTool;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Argon2iPasswordHash;
use TYPO3\CMS\Core\Tests\Acceptance\Support\ApplicationTester;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Service\EnableFileService;

class AbstractCest
{
    private const ADDITIONAL_CONFIGURATION_FILEPATH = 'typo3conf/system/additional.php';
    protected const INSTALL_TOOL_PASSWORD = 'temporary password';

    public function _before(ApplicationTester $I): void
    {
        $I->amOnPage('typo3/install.php');
    }

    public function _after(ApplicationTester $I): void
    {
        $I->click('Logout');
        // Make sure logout has finished
        $I->waitForText('The Install Tool is locked', 20);

        $I->amGoingTo('clean up created files');
        unlink(Environment::getProjectPath() . '/' . self::ADDITIONAL_CONFIGURATION_FILEPATH);

        $I->dontSeeFileFound($this->getEnableInstallToolFilePath());
        $I->dontSeeFileFound(Environment::getProjectPath() . '/' . self::ADDITIONAL_CONFIGURATION_FILEPATH);
    }

    protected function getEnableInstallToolFilePath(): string
    {
        return Environment::getVarPath() . '/transient/' . EnableFileService::INSTALL_TOOL_ENABLE_FILE_PATH;
    }

    protected function logIntoInstallTool(ApplicationTester $I): void
    {
        $password = $this->setInstallToolPassword($I);

        $I->writeToFile($this->getEnableInstallToolFilePath(), '');
        $I->reloadPage();
        $I->waitForElementVisible('#t3-install-form-password');
        $I->fillField('#t3-install-form-password', $password);
        $I->click('Login');
        $I->waitForText('Maintenance');
    }

    private function setInstallToolPassword(ApplicationTester $I): string
    {
        $hashMethod = GeneralUtility::makeInstance(Argon2iPasswordHash::class);
        $password = self::INSTALL_TOOL_PASSWORD;
        $hashedPassword = $hashMethod->getHashedPassword($password);
        $I->writeToFile(
            self::ADDITIONAL_CONFIGURATION_FILEPATH,
            '<?php' . PHP_EOL . '$GLOBALS[\'TYPO3_CONF_VARS\'][\'BE\'][\'installToolPassword\'] = \'' . $hashedPassword . '\';'
        );
        return $password;
    }
}
