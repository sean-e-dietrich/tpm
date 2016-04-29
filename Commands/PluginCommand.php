<?php

namespace Terminus\Commands;

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Utils;

const DEFAULT_URL = 'https://terminus-plugins.firebaseio.com/';
const DEFAULT_PATH = '/plugins';

/**
 * Manage Terminus plugins
 *
 * @command plugin
 */
class PluginCommand extends TerminusCommand {

  /**
   * Object constructor
   *
   * @param array $options Elements as follow:
   * @return PluginCommand
   */
  public function __construct(array $options = []) {
    parent::__construct($options);
  }

  /**
   * Install plugin(s)
   *
   * @param array $args A list of one or more named plugins from well-known repositories
   *   or URLs to custom plugin Git repositories
   *
   * @subcommand install
   * @alias add
   */
  public function install($args = array()) {
    if (empty($args)) {
      $message = "Usage: terminus plugin install | add plugin-name-1 |";
      $message .= " <URL to plugin Git repository 1> [plugin-name-2 |";
      $message .= " <URL to plugin Git repository 2>] ...";
      $this->failure($message);
    }

    $plugins_dir = $this->getPluginDir();

    foreach ($args as $arg) {
      $is_url = (filter_var($arg, FILTER_VALIDATE_URL) !== false);
      if ($is_url) {
        $parts = parse_url($arg);
        $path = explode('/', $parts['path']);
        $plugin = array_pop($path);
        $repository = $parts['scheme'] . '://' . $parts['host'] . implode('/', $path);
        if (!$this->isValidPlugin($repository, $plugin)) {
          $message = "$arg is not a valid plugin Git repository.";
          $this->log()->error($message);
        } else {
          if (is_dir($plugins_dir . $plugin)) {
            $message = "$plugin plugin already installed.";
            $this->log()->notice($message);
          } else {
            $this->addRepository($repository);
            exec("cd \"$plugins_dir\" && git clone $arg", $output);
            foreach ($output as $message) {
              $this->log()->notice($message);
            }
          }
        }
      } else {
        $plugins = $this->searchRepositories((array)$arg);
        if (empty($plugins)) {
          $message = "No plugins found matching $arg.";
          $this->log()->error($message);
        } else {
          foreach ($plugins as $plugin => $repository) {
            if (is_dir($plugins_dir . $plugin)) {
              $message = "$plugin plugin already installed.";
              $this->log()->notice($message);
            } else {
              $repo = $repository . '/' . $plugin;
              exec("cd \"$plugins_dir\" && git clone $repo", $output);
              foreach ($output as $message) {
                $this->log()->notice($message);
              }
            }
          }
        }
      }
    }
  }

  /**
   * List all installed plugins
   *
   * @subcommand show
   * @alias list
   */
  public function show() {
    $plugins_dir = $this->getPluginDir();
    exec("ls \"$plugins_dir\"", $output);
    if (empty($output[0])) {
      $message = "No plugins installed.";
      $this->log()->notice($message);
    } else {
      $repositories = $this->listRepositories();
      $message = "Plugins are installed in $plugins_dir.";
      $this->log()->notice($message);
      $message = "The following plugins are installed:";
      $this->log()->notice($message);
      foreach ($output as $plugin) {
        if (is_dir($plugins_dir . $plugin)) {
          foreach ($repositories as $repository) {
            $url = $repository . '/' . $plugin;
            if ($this->isValidUrl($url)) {
              if ($title = $this->isValidPlugin($repository, $plugin)) {
                $this->log()->notice($title);
              } else {
                $this->log()->notice($plugin);
              }
              break;
            }
          }
        }
      }
      $message = "Use 'terminus plugin search' to find more plugins.";
      $this->log()->notice($message);
      $message = "Use 'terminus plugin install' to add more plugins.";
      $this->log()->notice($message);
    }
  }

  /**
   * Update plugin(s)
   *
   * @param array $args 'all' or a list of one or more installed plugin names
   *
   * @subcommand update
   * @alias up
   */
  public function update($args = array()) {
    if (empty($args)) {
      $message = "Usage: terminus plugin update | up all | plugin-name-1";
      $message .= " [plugin-name-2] ...";
      $this->failure($message);
    }

    if ($args[0] == 'all') {
      $plugins_dir = $this->getPluginDir();
      exec("ls \"$plugins_dir\"", $output);
      if (empty($output[0])) {
        $message = "No plugins installed.";
        $this->log()->notice($message);
      } else {
        foreach ($output as $plugin) {
          $this->updatePlugin($plugin);
        }
      }
    } else {
      foreach ($args as $arg) {
        $this->updatePlugin($arg);
      }
    }
  }

  /**
   * Remove plugin(s)
   *
   * @param array $args A list of one or more installed plugin names
   *
   * @subcommand uninstall
   * @alias remove
   */
  public function uninstall($args = array()) {
    if (empty($args)) {
      $message = "Usage: terminus plugin uninstall | remove plugin-name-1";
      $message .= " [plugin-name-2] ...";
      $this->failure($message);
    }

    foreach ($args as $arg) {
      $plugin = $this->getPluginDir($arg);
      if (!is_dir("$plugin")) {
        $message = "$arg plugin is not installed.";
        $this->log()->error($message);
      } else {
        exec("rm -rf \"$plugin\"", $output);
        foreach ($output as $message) {
          $this->log()->notice($message);
        }
        $message = "$arg plugin was removed successfully.";
        $this->log()->notice($message);
      }
    }
  }

  /**
   * Search for plugins in well-known or custom repositories
   *
   * @param array $args A list of one or more partial
   *   or complete plugin names
   *
   * @subcommand search
   * @alias find
   */
  public function search($args = array()) {
    if (empty($args) || count($args) > 1) {
      $message = "Usage: terminus plugin search plugin-name";
      $this->failure($message);
    }
    $plugins = $this->searchRepositories($args);
    if (empty($plugins)) {
      $message = "No plugins were found.";
      $this->log()->notice($message);
    } else {
      $message = "The following plugin were found:";
      $this->log()->notice($message);

      $table = new \Console_Table();
      $table->setHeaders(
        array('Package', 'Title', 'Description', 'Author')
      );

      foreach($plugins AS $item){
        $table->addRow(array($item['package'], $item['title'], $item['description'], "{$item['creator']} <{$item['creator_email']}>"));
      }

      print $table->getTable();
    }
  }

  /**
   * Manage repositories
   *
   * @param array $args A subcommand followed by a list of one
   *   or more repositories
   *
   * @subcommand repository
   * @alias repo
   */
  public function repository($args = array()) {
    $usage = "Usage: terminus plugin repository | repo add | list | remove";
    $usage .= " <URL to plugin Git repository 1>";
    $usage .= " [<URL to plugin Git repository 2>] ...";
    if (empty($args)) {
      $this->failure($usage);
    }
    $cmd = array_shift($args);
    $valid_cmds = array('add', 'list', 'remove');
    if (!in_array($cmd, $valid_cmds)) {
      $this->failure($usage);
    }
    switch ($cmd) {
      case 'add':
        if (empty($args)) {
          $this->failure($usage);
        }
        foreach ($args as $arg) {
          $this->addRepository($arg);
        }
          break;
      case 'list':
        $repositories = $this->listRepositories();
        if (empty($repositories)) {
          $message = 'No plugin repositories exist.';
          $this->log()->error($message);
        } else {
          $repo_yml = $this->getRepositoriesPath();
          $message = "Plugin repositories are stored in $repo_yml.";
          $this->log()->notice($message);
          $message = "The following plugin repositories are available:";
          $this->log()->notice($message);
          foreach ($repositories as $repository) {
            $this->log()->notice($repository);
          }
          $message = "The 'terminus plugin search' command will only search in these repositories.";
          $this->log()->notice($message);
        }
          break;
      case 'remove':
        if (empty($args)) {
          $this->failure($usage);
        }
        foreach ($args as $arg) {
          $this->removeRepository($arg);
        }
          break;
    }
  }

  /**
   * Get the plugin directory
   *
   * @param string $arg Plugin name
   * @return string Plugin directory
   */
  private function getPluginDir($arg = '') {
    $plugins_dir = getenv('TERMINUS_PLUGINS_DIR');
    $windows = Utils\isWindows();
    if (!$plugins_dir) {
      // Determine the correct $plugins_dir based on the operating system
      $home = getenv('HOME');
      if ($windows) {
        $system = '';
        if (getenv('MSYSTEM') !== null) {
          $system = strtoupper(substr(getenv('MSYSTEM'), 0, 4));
        }
        if ($system != 'MING') {
          $home = getenv('HOMEPATH');
        }
        $home = str_replace('\\', '\\\\', $home);
        $plugins_dir = $home . '\\\\terminus\\\\plugins\\\\';
      } else {
        $plugins_dir = $home . '/terminus/plugins/';
      }
    } else {
      // Make sure the proper trailing slash(es) exist
      if ($windows) {
        $slash = '\\\\';
        $chars = 2;
      } else {
        $slash = '/';
        $chars = 1;
      }
      if (substr("$plugins_dir", -$chars) != $slash) {
        $plugins_dir .= $slash;
      }
    }
    // Create the directory if it doesn't already exist
    if (!is_dir("$plugins_dir")) {
      mkdir("$plugins_dir", 0755, true);
    }
    return $plugins_dir . $arg;
  }

  /**
   * Update a specific plugin
   *
   * @param string $arg Plugin name
   */
  private function updatePlugin($arg) {
    $plugin = $this->getPluginDir($arg);
    if (is_dir("$plugin")) {
      $windows = Utils\isWindows();
      if ($windows) {
        $slash = '\\\\';
      } else {
        $slash = '/';
      }
      $git_dir = $plugin . $slash . '.git';
      $message = "Updating $arg plugin...";
      $this->log()->notice($message);
      if (!is_dir("$git_dir")) {
        $messages = array();
        $message = "Unable to update $arg plugin.";
        $message .= "  Git repository does not exist.";
        $messages[] = $message;
        $message = "The recommended way to install plugins";
        $message .= " is git clone <URL to plugin Git repository>.";
        $messages[] = $message;
        $message = "See https://github.com/pantheon-systems/terminus/";
        $message .= "wiki/Plugins.";
        $messages[] = $message;
        foreach ($messages as $message) {
          $this->log()->error($message);
        }
      } else {
        exec("cd \"$plugin\" && git pull", $output);
        foreach ($output as $message) {
          $this->log()->notice($message);
        }
      }
    }
  }

  /**
   * Get repositories
   *
   * @return array Parsed Yaml from the repositories.yml file
   */
  private function getRepositories() {
    $repo_yml = $this->getRepositoriesPath();
    $header = $this->getRepositoriesHeader();
    if (file_exists($repo_yml)) {
      $repo_data = @file_get_contents($repo_yml);
      if ($repo_data != $header) {
        return Yaml::parse($repo_data);
      }
    } else {
      $handle = fopen($repo_yml, "w");
      fwrite($handle, $header);
      fclose($handle);
    }
    return array();
  }

  /**
   * Get repositories.yml path
   *
   * @return string The full path to the repositories.yml file
   */
  private function getRepositoriesPath() {
    $plugin_dir = $this->getPluginDir();
    return $plugin_dir . 'repositories.yml';
  }

  /**
   * Get repositories.yml header
   *
   * @return string repositories.yml header
   */
  private function getRepositoriesHeader() {
    return <<<YML
# Terminus plugin repositories
#
# List of well-known or custom plugin Git repositories
---
YML;
  }

  /**
   * Add repository
   *
   * @param string $repo Repository URL
   */
  private function addRepository($repo = '') {
    if (!$this->isValidUrl($repo)) {
      $message = "$repo is not a valid URL.";
      $this->failure($message);
    }
    $repo_exists = false;
    $repositories = $this->listRepositories();
    foreach ($repositories as $repository) {
      if ($repository == $repo) {
        $message = "Unable to add $repo.  Repository already added.";
        $this->log()->error($message);
        $repo_exists = true;
        break;
      }
    }
    if (!$repo_exists) {
      $parts = parse_url($repo);
      if (isset($parts['path']) && ($parts['path'] != '/')) {
        $host = $parts['scheme'] . '://' . $parts['host'];
        $path = substr($parts['path'], 1);
        $repositories = $this->getRepositories();
        $repositories[$host][] = $path;
        $this->saveRepositories($repositories);
      }
    }
  }

  /**
   * List repositories
   *
   * @return array List of fully qualified domain repository URLs
   */
  private function listRepositories() {
    $repo_urls = array();
    $repositories = $this->getRepositories();
    foreach ($repositories as $host => $repository) {
      foreach ($repository as $path) {
        $repo_urls[] = $host . '/' . $path;
      }
    }
    return $repo_urls;
  }

  /**
   * Remove repository
   *
   * @param string $repo Repository URL
   */
  private function removeRepository($repo = '') {
    $exists = false;
    $repositories = $this->listRepositories();
    foreach ($repositories as $repository) {
      if ($repository == $repo) {
        $exists = true;
        break;
      }
    }
    if (!$exists) {
      $message = "Unable to remove $repo.  Repository does not exist.";
      $this->log()->error($message);
    } else {
      $parts = parse_url($repo);
      $host = $parts['scheme'] . '://' . $parts['host'];
      $path = substr($parts['path'], 1);
      $repositories = $this->getRepositories();
      foreach ($repositories as $repo_host => $repository) {
        if ($repo_host == $host) {
          foreach ($repository as $key => $repo_url) {
            if ($repo_url == $path) {
              unset($repositories[$host][$key]);
              $this->saveRepositories($repositories, 'remove');
              break;
            }
          }
          break;
        }
      }
    }
  }

  /**
   * Save repositories
   *
   * @param array $repos A list of plugin repositories
   */
  private function saveRepositories($repos = array(), $op = 'add') {
    $repo_yml = $this->getRepositoriesPath();
    $header = $this->getRepositoriesHeader();
    $repo_data = "$header\n" . Yaml::dump($repos);
    try {
      $handle = fopen($repo_yml, "w");
      fwrite($handle, $repo_data);
      fclose($handle);
    } catch (Exception $e) {
      $messages = array();
      $messages[] = "Unable to $op plugin repository.";
      $messages[] = $e->getMessage();
      $message = implode("\n", $messages);
      $this->failure($message);
    }
    if ($op == 'add') {
      $oped = 'added';
    } else {
      $oped = 'removed';
    }
    $message = "Plugin repository was $oped successfully.";
    $this->log()->notice($message);
  }

  /**
   * Search repositories
   *
   * @param string $args A list of partial or complete plugin names
   * @return array List of plugin names found
   */
  private function searchRepositories($args = array()) {
    $this->database = new \Firebase\FirebaseLib(DEFAULT_URL);
    $results = $this->database->get(DEFAULT_PATH);
    $results = json_decode($results, true);

    $plugin_search = preg_grep("/{$args[0]}/", array_keys($results));
    foreach($plugin_search as $plugin_id){
      $item = $results[$plugin_id];
      $plugins[$item['repo']] = $item;
    }

    return $plugins;
  }

  /**
   * Check whether a plugin is valid
   *
   * @param string Repository URL
   * @param string Plugin name
   * @return string Plugin title, if found
   */
  private function isValidPlugin($repository, $plugin) {
    // Make sure the URL is valid
    $is_url = (filter_var($repository, FILTER_VALIDATE_URL) !== false);
    if (!$is_url) {
      return '';
    }
    // Make sure a subpath exists
    $parts = parse_url($repository);
    if (!isset($parts['path']) || ($parts['path'] == '/')) {
      return '';
    }
    // Search for a plugin title
    $plugin_data = @file_get_contents($repository . '/' . $plugin);
    if (!empty($plugin_data)) {
      preg_match('|<title>(.*)</title>|', $plugin_data, $match);
      if (isset($match[1])) {
        $title = $match[1];
        if (stripos($title, 'terminus') && stripos($title, 'plugin')) {
          return $title;
        }
        return '';
      }
      return '';
    }
    return '';
  }

  /**
   * Check whether a URL is valid
   *
   * @param string $url The URL to check
   * @return bool True if the URL returns a 200 status
   */
  private function isValidUrl($url = '') {
    if (!$url) {
      return false;
    }
    $headers = @get_headers($url);
    if (!isset($headers[0])) {
      return false;
    }
    return (strpos($headers[0], '200') !== false);
  }

}
