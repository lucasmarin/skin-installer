<?php

namespace Roundcube\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\Version\VersionParser;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\ProcessExecutor;

/**
 * @category Plugins
 * @package  SkinInstaller
 * @author   Till Klampaeckel <till@php.net>
 * @author   Thomas Bruederli <thomas@roundcube.net>
 * @author   Lucas Stevanelli Marin <lucasmarin@gmail.com>
 * @license  GPL-3.0+
 * @version  GIT: <git_id>
 * @link     http://github.com/lucasmarin/skin-installer
 */
class SkinInstaller extends LibraryInstaller
{
    const INSTALLER_TYPE = 'roundcube-skin';

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        static $vendorDir;
        if ($vendorDir === null) {
            $vendorDir = $this->getVendorDir();
        }

        return sprintf('%s/%s', $vendorDir, $this->getSkinName($package));
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->rcubeVersionCheck($package);
        parent::install($repo, $package);

        // post-install: activate plugin in Roundcube config
        $config_file = $this->rcubeConfigFile();
        $extra = $package->getExtra();
        if (is_writeable($config_file) && php_sapi_name() == 'cli') {
            $plugin_name = $this->getPluginName($package);
            $answer = $this->io->askConfirmation("Do you want to activate the plugin $plugin_name? [N|y] ", false);
            if (true === $answer) {
                $this->rcubeAlterConfig($plugin_name);
            }
        }

        // run post-install script
        if (!empty($extra['roundcube']['post-install-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-install-script'], $package);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->rcubeVersionCheck($target);
        parent::update($repo, $initial, $target);

        $extra = $target->getExtra();

        // run post-update script
        if (!empty($extra['roundcube']['post-update-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-update-script'], $target);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        // run post-uninstall script
        $extra = $package->getExtra();
        if (!empty($extra['roundcube']['post-uninstall-script'])) {
            $this->rcubeRunScript($extra['roundcube']['post-uninstall-script'], $package);
        }
    }

    /**
     * Update the given skin in the Roundcube config.
     */
    private function rcubeAlterConfig($skin_name)
    {
        $config_file = $this->rcubeConfigFile();
        @include($config_file);
        $success = false;
        $varname = '$config';
        if (empty($config) && !empty($rcmail_config)) {
            $config = $rcmail_config;
            $varname = '$rcmail_config';
        }
        if (is_array($config) && is_writeable($config_file)) {
            $config_templ = @file_get_contents($config_file);
            $active_skin = $config['skin'];
            if ($active_skin != $skin_name) {
                $active_skin = $skin_name;
            }
            if ($active_skin != $config['skin']) {
                $var_export = "array(\n\t'" . join("',\n\t'", $active_skin) . "',\n);";
                $new_config = preg_replace(
                    "/(\\$varname\['skin'\])\s+=\s+(.+);/Uims",
                    "\\1 = " . $var_export,
                    $config_templ);
                $success = file_put_contents($config_file, $new_config);
            }
        }
        if ($success && php_sapi_name() == 'cli') {
            $this->io->write("<info>Updated local config at $config_file</info>");
        }
        return $success;
    }
    /**
     * Helper method to get an absolute path to the local Roundcube config file
     */
    private function rcubeConfigFile()
    {
        return realpath(getcwd() . '/config/config.inc.php');
    }


    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === self::INSTALLER_TYPE;
    }

    /**
     * Setup vendor directory to one of these two:
     *  ./skinss
     *
     * @return string
     */
    public function getVendorDir()
    {
        $skinsDir  = getcwd();
        $skinsDir .= '/skins';

        return $skinsDir;
    }

    /**
     * Extract the (valid) skins name from the package object
     */
    private function getSkinName(PackageInterface $package)
    {
        @list($vendor, $skinsName) = explode('/', $package->getPrettyName());

        return strtr($skinsName, '-', '_');
    }

    /**
     * Check version requirements from the "extra" block of a package
     * against the local Roundcube version
     */
    private function rcubeVersionCheck($package)
    {
        $parser = new VersionParser;

        // read rcube version from iniset
        $rootdir = getcwd();
        $iniset = @file_get_contents($rootdir . '/program/include/iniset.php');
        if (preg_match('/define\(.RCMAIL_VERSION.,\s*.([0-9.]+[a-z-]*)?/', $iniset, $m)) {
            $rcubeVersion = $parser->normalize(str_replace('-git', '.999', $m[1]));
        } else {
            throw new \Exception("Unable to find a Roundcube installation in $rootdir");
        }

        $extra = $package->getExtra();

        if (!empty($extra['roundcube'])) {
            foreach (array('min-version' => '>=', 'max-version' => '<=') as $key => $operator) {
                if (!empty($extra['roundcube'][$key])) {
                    $version = $parser->normalize(str_replace('-git', '.999', $extra['roundcube'][$key]));
                    $constraint = new VersionConstraint($version, $operator);
                    if (!$constraint->versionCompare($rcubeVersion, $version, $operator)) {
                        throw new \Exception("Version check failed! " . $package->getName() . " requires Roundcube version $operator $version, $rcubeVersion was detected.");
                    }
                }
            }
        }
    }

    /**
     * Run the given script file
     */
    private function rcubeRunScript($script, PackageInterface $package)
    {
        @list($vendor, $skin_name) = explode('/', $package->getPrettyName());

        // run executable shell script
        if (($scriptfile = realpath($this->getVendorDir() . "/$skin_name/$script")) && is_executable($scriptfile)) {
            system($scriptfile, $res);
        }
        // run PHP script in Roundcube context
        else if ($scriptfile && preg_match('/\.php$/', $scriptfile)) {
            $incdir = realpath(getcwd() . '/program/include');
            include_once($incdir . '/iniset.php');
            include($scriptfile);
        }
        // attempt to execute the given string as shell commands
        else {
            $process = new ProcessExecutor();
            $exitCode = $process->execute($script);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Error executing script: '. $process->getErrorOutput(), $exitCode);
            }
        }
    }
}
