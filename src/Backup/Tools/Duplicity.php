<?php

namespace Backup\Tools;

use Backup\Binary;

/**
 * Class wrapper for duplicity command.
 * Currently only support backup to a directory(file://).
 *
 * @author Ioannis Botis
 * @date 23/9/2016
 * @version: Duplicity.php 8:26 pm
 * @since 23/9/2016
 */
class Duplicity implements Command
{
    /**
     * @var array options of duplicity command.
     */
    private $_options = array(
        '--no-encryption' => array(
            'since' => '0.1',
            'use' => true,
        ),
        '--dry-run' => array(
            'since' => '0.1',
            'use' => false,
        ),
    );

    /**
     * @var string optional passphrase specified.
     */
    private $_passphrase;
    /**
     * @var string the main directory to backup.
     */
    private $_main_directory;
    /**
     * @var string[] subdirectories of the main directory we want to exclude, eg cache, temp.
     */
    private $_excluded_directories;

    private $_destination;

    /**
     * @var Binary
     */
    private $_binary;

    private static $_version;

    private $_output;

    /**
     * Duplicity constructor.
     *
     * @param string $directory the path to the directory to backup.
     * @param string $destination the path to the directory to keep the backup files.
     * @param Binary $binary
     */
    public function __construct($directory, $destination, Binary $binary)
    {
        $this->_binary = $binary;
        $this->_setMainDirectory($directory);
        $this->_destination = $destination;
    }

    private function _setMainDirectory($directory)
    {
        if (!$this->isInstalled()) {
            throw new \Backup\Exception\BinaryNotFoundException('Duplicity not installed');
        }
        if (!$this->directoryExists($directory)) {
            throw new \Backup\Exception\InvalidArgumentException('Directory path is invalid');
        }
        $this->_main_directory = $directory;
    }

    /**
     * @param string $directory
     * @return bool
     */
    protected function directoryExists($directory)
    {
        return is_dir($directory);
    }

    /**
     * Check if duplicity is installed.
     * @return bool
     */
    public function isInstalled()
    {
        $exitCode = $this->_binary->run(' -V');
        if ($exitCode) {
            return false;
        }
        return true;
    }

    /**
     * Returns the version of duplicity.
     * @return string
     */
    public function getVersion()
    {
        if (isset(self::$_version)) {
            return self::$_version;
        }
        $this->_binary->run(' -V');
        $output = implode('', $this->_binary->getOutput());
        return self::$_version = trim(str_replace('duplicity', '', $output));
    }

    public function setPassPhrase($passphrase)
    {
        if (!is_string($passphrase)) {
            throw new \Backup\Exception\InvalidArgumentException('Passphrase should be a string');
        }
        $this->_passphrase = $passphrase;
        $this->_options['--no-encryption']['use'] = false;
    }

    /**
     * Exclude subdirectories from backup.
     * Multiple level paths supported eg. ["sudir1", "subdir2/dir"].
     * Not full path, but relative paths.
     * If a subdirectory does not exist, it will be ignored.
     *
     * @param array $subDirs an array of subdirectories to exclude.
     */
    public function setExludedSubDirectories(array $subDirs)
    {
        $this->_excluded_directories = $subDirs;
    }

    protected function getEnvironmentVars()
    {
        $vars = array();
        if (isset($this->_passphrase)) {
            $vars['PASSPHRASE'] = $this->_passphrase;
        }
        return $vars;
    }

    /**
     * Verify backup, test that the backup is not corrupted and it can be restored.
     * When compare data is used, it compares files between source and destination location and exits with a non zero code.
     * Please note that the behaviour is different between versions <0.7 and >=0.7.
     * Versions <0.7 actually will compare data even if compare-data option is not used.
     * more info can be found here: https://bugs.launchpad.net/duplicity/+bug/1354880.
     *
     * @param bool $compare_data whether to compare data between source and destination for changes.
     * @return mixed
     */
    public function verify($compare_data = true)
    {
        $exitCode = $this->_binary->run(
            $this->_getOptions() . $this->_getExcludedPaths() . ' verify ' .
            ($compare_data ? '--compare-data file://' : '') . $this->_destination . ' ' .
            $this->_main_directory,
            $this->getEnvironmentVars()
        );

        $this->_output = $this->_binary->getOutput();

        if ($exitCode == 0) {
            return self::NO_CHANGES;
        } elseif ($exitCode == 1) {
            return self::IS_CHANGED;
        } elseif ($exitCode == 30) {
            return self::NO_BACKUP_FOUND;
        }
        return self::CORRUPT_DATA;
    }

    public function execute($full = false)
    {
        $exitCode = $this->_binary->run(
            $this->_getOptions() . $this->_getExcludedPaths() . ' ' .
            ($full ? 'full ' : '') . $this->_main_directory . ' file://' . $this->_destination,
            $this->getEnvironmentVars()
        );
        $this->_output = $this->_binary->getOutput();
        return $exitCode;
    }

    protected function getCollectionStatus()
    {
        $exitCode = $this->_binary->run(
            $this->_getOptions() . $this->_getExcludedPaths() . ' collection-status file://' . $this->_destination,
            $this->getEnvironmentVars()
        );
        $this->_output = $this->_binary->getOutput();
        return $exitCode;
    }

    public function getAllBackups()
    {
        $exitCode = $this->getCollectionStatus();
        if ($exitCode != 0) {
            return array();
        }
        $backups = array();
        foreach ($this->_output as $line) {
            if (preg_match("/(Full|Incremental)[\s]+(.*)[\s]{10}/", $line, $results)) {
                $backups[] = self::_getUnixTimestamp(trim($results[2]));
            }
        }
        return $backups;
    }

    private static function _getUnixTimestamp($time)
    {
        $d = new \DateTime($time);
        return $d->getTimestamp();
    }

    public function restore($time, $directory)
    {
        $d = new \DateTime();
        $d->setTimestamp($time);
        $time = $d->format(\DateTime::W3C);

        if (!$this->directoryExists($directory)) {
            throw new \Backup\Exception\InvalidArgumentException('Directory path is invalid');
        }
        $is_empty = $this->isDirEmpty($directory);
        if ($is_empty === null) {
            throw new \Backup\Exception\InvalidArgumentException('Directory path is not readable');
        }
        if ($is_empty === false) {
            throw new \Backup\Exception\InvalidArgumentException('Directory path should be empty');
        }
        $exitCode = $this->_binary->run(
            $this->_getOptions() . $this->_getExcludedPaths() . ' restore file://' . $this->_destination . ' ' .
            $directory . ' --time=' . $time,
            $this->getEnvironmentVars()
        );
        $this->_output = $this->_binary->getOutput();
        return $exitCode;
    }

    private function _getExcludedPaths()
    {
        if (empty($this->_excluded_directories)) {
            return '';
        } else {
            return ' --exclude **' . implode(' --exclude **', $this->_excluded_directories) . ' ';
        }
    }

    private function _getOptions()
    {
        $options = array();

        foreach ($this->_options as $option => $settings) {
            if ($this->_isSupported($settings['since'])) {
                if ($settings['use']) {
                    $options[] = $option;
                }
            } else {
                trigger_error('Option ' . $option . ' is supported since ' . $settings['since'] . ',not in your local version');
            }
        }
        return implode(' ', $options);
    }

    private function _isSupported($since)
    {
        $version = $this->getVersion();
        return version_compare($version, $since, '>=');
    }

    protected function isDirEmpty($dir)
    {
        if (!is_readable($dir)) {
            return null;
        }
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return false;
            }
        }
        return true;
    }


    public function getOutput()
    {
        return $this->_output;
    }
}