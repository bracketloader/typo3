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

namespace TYPO3\CMS\Core\Composer;

use Composer\Repository\PlatformRepository;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Composer\Plugin\Config;
use TYPO3\CMS\Composer\Plugin\Core\InstallerScript;
use TYPO3\CMS\Composer\Plugin\Util\ExtensionKeyResolver;
use TYPO3\CMS\Core\Package\Cache\ComposerPackageArtifact;
use TYPO3\CMS\Core\Package\Exception\InvalidPackageKeyException;
use TYPO3\CMS\Core\Package\Exception\InvalidPackageManifestException;
use TYPO3\CMS\Core\Package\Exception\InvalidPackagePathException;
use TYPO3\CMS\Core\Package\Exception\InvalidPackageStateException;
use TYPO3\CMS\Core\Package\Package;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Service\DependencyOrderingService;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * The builder is a subclass of PackageManager as it shares much of its functionality.
 * It evaluates the installed Composer packages for applicable TYPO3 extensions.
 * All Composer packages will be discovered, that have an extra.typo3/cms definition in their composer.json.
 * All ext_emconf.php files will be completely ignored in this context, which means all extensions
 * are required to have a composer.json file, which works out naturally with a Composer setup.
 *
 * @internal This class is an implementation detail and does not represent public API
 */
class PackageArtifactBuilder extends PackageManager implements InstallerScript
{
    /**
     * Array of package keys that are installed by Composer but have no relation to TYPO3 extension API
     * @var array
     */
    private $availableComposerPackageKeys = [];

    public function __construct()
    {
        // Disable path determination with Environment class, which is not initialized here
        parent::__construct(new DependencyOrderingService(), '', '');
    }

    protected function isComposerDependency(string $packageName): bool
    {
        return PlatformRepository::isPlatformPackage($packageName) || in_array($packageName, $this->availableComposerPackageKeys, true);
    }

    /**
     * Entry method called in Composer post-dump-autoload hook
     *
     * @param Event $event
     * @return bool
     * @throws InvalidPackageKeyException
     * @throws InvalidPackageManifestException
     * @throws InvalidPackagePathException
     * @throws InvalidPackageStateException
     */
    public function run(Event $event): bool
    {
        $composer = $event->getComposer();
        $ignoreRootPackage = Config::load($composer)->get('ignore-as-root') ?? true;
        $rootPackage = $composer->getPackage();
        $basePath = getenv('TYPO3_PATH_COMPOSER_ROOT');
        $this->packagesBasePath = $basePath . '/';
        foreach ($this->extractPackageMapFromComposer($event) as [$composerPackage, $path, $extensionKey]) {
            if ($composerPackage === $rootPackage && $ignoreRootPackage) {
                continue;
            }
            $packagePath = PathUtility::sanitizeTrailingSeparator($path ?: $basePath);
            $package = new Package($this, $extensionKey, $packagePath, true);
            $package->makePathRelative(new Filesystem());
            $package->getPackageMetaData()->setVersion($composerPackage->getPrettyVersion());
            $this->registerPackage($package);
        }
        $this->sortPackagesAndConfiguration();
        $cacheIdentifier = md5(serialize($composer->getLocker()->getLockData()) . ((string)(int)$ignoreRootPackage));
        $this->setPackageCache(new ComposerPackageArtifact(getenv('TYPO3_PATH_APP') . '/var', new Filesystem(), $cacheIdentifier));
        $this->saveToPackageCache();

        return true;
    }

    /**
     * Sorts all TYPO3 extension packages by dependency defined in composer.json file
     */
    private function sortPackagesAndConfiguration(): void
    {
        $packagesWithDependencies = $this->resolvePackageDependencies($this->packages);
        // Sort the packages by key at first, so we get a stable sorting of "equivalent" packages afterwards
        ksort($packagesWithDependencies);
        $sortedPackageKeys = $this->sortPackageStatesConfigurationByDependency($packagesWithDependencies);
        $this->packageStatesConfiguration = [];
        $sortedPackages = [];
        foreach ($sortedPackageKeys as $packageKey) {
            $sortedPackages[$packageKey] = $this->packages[$packageKey];
            // The artifact does not need path information, so it is kept empty
            // The keys must be present, though because the PackageManager implies than a
            // package is active by this configuration array
            $this->packageStatesConfiguration['packages'][$packageKey] = [];
        }
        $this->packages = $sortedPackages;
        $this->packageStatesConfiguration['version'] = 5;
    }

    /**
     * Fetch a map of all installed packages and filter them, when they apply
     * for TYPO3.
     *
     * @param Event $event
     * @return array
     */
    private function extractPackageMapFromComposer(Event $event): array
    {
        $composer = $event->getComposer();
        $mainPackage = $composer->getPackage();
        $autoLoadGenerator = $composer->getAutoloadGenerator();
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();

        $installedTypo3Packages = array_map(
            static function (array $packageAndPath) {
                [$composerPackage, $packagePath] = $packageAndPath;
                try {
                    $extensionKey = ExtensionKeyResolver::resolve($composerPackage);
                } catch (\Throwable $e) {
                    // In case we can not determine the extension key, we take the composer name
                    $extensionKey = $composerPackage->getName();
                }
                // Add extension key to the package map for later reference
                return [$composerPackage, $packagePath, $extensionKey];
            },
            array_filter(
                $autoLoadGenerator->buildPackageMap($composer->getInstallationManager(), $mainPackage, $localRepo->getCanonicalPackages()),
                function (array $packageAndPath) {
                    [$composerPackage,] = $packageAndPath;
                    // Filter all Composer packages without typo3/cms definition, but keep all
                    // package names, to be able to ignore Composer only dependencies when ordering the packages
                    $this->availableComposerPackageKeys[] = $composerPackage->getName();
                    return isset($composerPackage->getExtra()['typo3/cms']);
                }
            )
        );

        $installedTypo3Packages = $this->amendWithLocallyAvailableExtensions($event, $installedTypo3Packages);

        return $installedTypo3Packages;
    }

    /**
     * Add extensions, that are located in typo3conf/ext, but are not installed by Composer
     * to the list of known packages. This is now deprecated and will be removed with TYPO3 12.
     * From then on all Extension for Composer enabled TYPO3 projects must be installed with Composer.
     *
     * @deprecated Will be removed with TYPO3 12
     * @param Event $event
     * @param array $installedTypo3Packages
     * @return array
     */
    private function amendWithLocallyAvailableExtensions(Event $event, array $installedTypo3Packages): array
    {
        $installedThirdPartyExtensionKeys = array_map(
            static function (array $packageAndPathAndKey) {
                [, , $extensionKey] = $packageAndPathAndKey;
                return $extensionKey;
            },
            array_filter(
                $installedTypo3Packages,
                static function (array $packageAndPathAndKey) {
                    [, $packagePath,] = $packageAndPathAndKey;
                    return strpos($packagePath, 'typo3conf/ext') !== false;
                }
            )
        );

        foreach ($this->scanForRootExtensions($installedThirdPartyExtensionKeys) as [$composerPackage, $path, $extensionKey]) {
            $event->getIO()->warning(sprintf('Extension "%s" not installed with Composer. This is deprecated and will not work any more with TYPO3 12.', $extensionKey));
            $installedTypo3Packages[] = [$composerPackage, $path, $extensionKey];
        }

        return $installedTypo3Packages;
    }

    /**
     * Scans typo3conf/ext folder for extensions, that have not been installed by Composer
     *
     * @param array $installedThirdPartyExtensionKeys
     * @return array
     */
    private function scanForRootExtensions(array $installedThirdPartyExtensionKeys): array
    {
        $thirdPartyExtensionDir = getenv('TYPO3_PATH_ROOT') . '/typo3conf/ext';
        if (!is_dir($thirdPartyExtensionDir) || !$this->hasSubDirectories($thirdPartyExtensionDir)) {
            return [];
        }
        $rootExtensionPackages = [];
        $finder = new Finder();
        $finder
            ->name('composer.json')
            ->followLinks()
            ->depth(0)
            ->ignoreUnreadableDirs()
            ->in($thirdPartyExtensionDir . '/*/');

        foreach ($finder as $splFileInfo) {
            $foundExtensionKey = basename($splFileInfo->getPath());
            if (in_array($foundExtensionKey, $installedThirdPartyExtensionKeys, true)) {
                // Found the extension to be installed with Composer, so no need to register it again
                continue;
            }
            $composerJson = json_decode($splFileInfo->getContents(), true);
            $extPackage = new \Composer\Package\Package($composerJson['name'], '1.0.0', '1.0.0.0');
            $extPackage->setExtra($composerJson['extra'] ?? []);
            $extPackage->setType($composerJson['type'] ?? 'typo3-cms-extension');
            $rootExtensionPackages[] = [$extPackage, $splFileInfo->getPath(), $foundExtensionKey];
        }

        return $rootExtensionPackages;
    }
}