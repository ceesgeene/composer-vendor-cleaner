<?php

namespace Liborm85\ComposerVendorCleaner;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    const DEV_FILES_KEY = 'dev-files';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Cleaner
     */
    private $cleaner;

    /**
     * @var string
     */
    private $binDir;

    /**
     * @var bool
     */
    private $isCleanedPackages = false;

    /**
     * @var array
     */
    private $changedPackages = [];

    /**
     * @var bool
     */
    private $actionIsDumpAutoload = true;

    /**
     * @var bool
     */
    private $isCleaningFinished = false;

    public function __destruct()
    {
        if (!$this->cleaner && !$this->isCleaningFinished) {
            $this->cleaner->finishCleanup();
        }
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'cleanup',
            ScriptEvents::PRE_UPDATE_CMD => 'preInstall',
            ScriptEvents::PRE_INSTALL_CMD => 'preInstall',
            ScriptEvents::POST_UPDATE_CMD => 'cleanup',
            ScriptEvents::POST_INSTALL_CMD => 'cleanup',
            PackageEvents::POST_PACKAGE_INSTALL => 'addPackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'addPackage',
        ];
    }

    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;

        $package = $this->composer->getPackage();
        $extra = $package->getExtra();
        $devFiles = isset($extra[self::DEV_FILES_KEY]) ? $extra[self::DEV_FILES_KEY] : null;
        if ($devFiles) {
            $this->binDir = $this->composer->getConfig()->get('bin-dir');
            $pluginConfig = $this->composer->getConfig()->get(self::DEV_FILES_KEY);
            $matchCase = isset($pluginConfig['match-case']) ? (bool)$pluginConfig['match-case'] : true;
            $removeEmptyDirs = isset($pluginConfig['remove-empty-dirs']) ? (bool)$pluginConfig['remove-empty-dirs'] : true;

            $this->cleaner = new Cleaner($io, new Filesystem(), $devFiles, $matchCase, $removeEmptyDirs);
        }
    }

    public function preInstall(Event $event)
    {
        // Not triggered when this plugin is installing. Solves the method addPackage.

        $this->actionIsDumpAutoload = false;
    }

    public function addPackage(PackageEvent $event)
    {
        // If this plugin is installing here is set install/update mode (solves not triggered the method preInstall).
        if ($this->actionIsDumpAutoload) {
            $this->actionIsDumpAutoload = false;
        }

        /** @var InstallOperation|UpdateOperation $operation */
        $operation = $event->getOperation();

        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        }

        if (isset($package)) {
            $this->changedPackages[] = $package->getPrettyName();
        }
    }

    public function cleanup(Event $event)
    {
        if (!$this->cleaner) { // cleaner not enabled/configured in project
            return;
        }

        if (!$this->isCleanedPackages) {
            $this->cleaner->cleanupPackages($this->getPackages());
        }

        if ($this->actionIsDumpAutoload || $this->isCleanedPackages) {
            $this->cleaner->cleanupBinary($this->binDir);
            $this->cleaner->finishCleanup();

            $this->isCleaningFinished = true;
        }

        $this->isCleanedPackages = true;
    }

    /**
     * @return Package[]
     */
    private function getPackages()
    {
        $packages = [];
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $this->composer->getInstallationManager();
        foreach ($localRepository->getPackages() as $repositoryPackage) {
            $package = new Package(
                $repositoryPackage,
                $installationManager,
                in_array($repositoryPackage->getPrettyName(), $this->changedPackages)
            );
            $packages[] = $package;
        }

        return $packages;
    }

}
