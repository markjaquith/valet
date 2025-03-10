<?php

use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Valet\Drivers\ValetDriver;
use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\warning;
use function Valet\writer;

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
} elseif (file_exists(__DIR__.'/../../../autoload.php')) {
    require_once __DIR__.'/../../../autoload.php';
} else {
    require_once getenv('HOME').'/.composer/vendor/autoload.php';
}

/**
 * Relocate config dir to ~/.config/valet/ if found in old location.
 */
if (is_dir(VALET_LEGACY_HOME_PATH) && ! is_dir(VALET_HOME_PATH)) {
    Configuration::createConfigurationDirectory();
}

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = '3.2.1';

$app = new Application('Laravel Valet', $version);

$dispatcher = new EventDispatcher();
$app->setDispatcher($dispatcher);

$dispatcher->addListener(
    ConsoleEvents::COMMAND,
    function (ConsoleCommandEvent $event) {
        writer($event->getOutput());
    });

/**
 * Prune missing directories and symbolic links on every command.
 */
if (is_dir(VALET_HOME_PATH)) {
    Configuration::prune();

    Site::pruneLinks();
}

/**
 * Install Valet and any required services.
 */
$app->command('install', function (OutputInterface $output) {
    Nginx::stop();

    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::read()['tld']);
    Nginx::restart();
    Valet::symlinkToUsersBin();

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services');

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Upgrade helper: ensure the tld config exists or the loopback config exists.
     */
    if (empty(Configuration::read()['tld']) || empty(Configuration::read()['loopback'])) {
        Configuration::writeBaseConfiguration();
    }

    /**
     * Get or set the TLD currently being used by Valet.
     */
    $app->command('tld [tld]', function (InputInterface $input, OutputInterface $output, $tld = null) {
        if ($tld === null) {
            return output(Configuration::read()['tld']);
        }

        $helper = $this->getHelperSet()->get('question');
        $question = new ConfirmationQuestion(
            'Using a custom TLD is no longer officially supported and may lead to unexpected behavior. Do you wish to proceed? [y/N]',
            false
        );

        if (false === $helper->ask($input, $output, $question)) {
            return warning('No new Valet tld was set.');
        }

        DnsMasq::updateTld(
            $oldTld = Configuration::read()['tld'],
            $tld = trim($tld, '.')
        );

        Configuration::updateKey('tld', $tld);

        Site::resecureForNewConfiguration(['tld' => $oldTld], ['tld' => $tld]);
        PhpFpm::restart();
        Nginx::restart();

        info('Your Valet TLD has been updated to ['.$tld.'].');
    }, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

    /**
     * Get or set the loopback address currently being used by Valet.
     */
    $app->command('loopback [loopback]', function (InputInterface $input, OutputInterface $output, $loopback = null) {
        if ($loopback === null) {
            return output(Configuration::read()['loopback']);
        }

        if (filter_var($loopback, FILTER_VALIDATE_IP) === false) {
            return warning('['.$loopback.'] is not a valid IP address');
        }

        $oldLoopback = Configuration::read()['loopback'];

        Configuration::updateKey('loopback', $loopback);

        DnsMasq::refreshConfiguration();
        Site::aliasLoopback($oldLoopback, $loopback);
        Site::resecureForNewConfiguration(['loopback' => $oldLoopback], ['loopback' => $loopback]);
        PhpFpm::restart();
        Nginx::installServer();
        Nginx::restart();

        info('Your valet loopback address has been updated to ['.$loopback.']');
    })->descriptions('Get or set the loopback address used for Valet sites');

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function (OutputInterface $output, $path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been added to Valet's paths.", $output);
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Get all the current sites within paths parked with 'park {path}'.
     */
    $app->command('parked', function (OutputInterface $output) {
        $parked = Site::parked();

        table(['Site', 'SSL', 'URL', 'Path'], $parked->all());
    })->descriptions('Display all the current sites within parked paths');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function (OutputInterface $output, $path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been removed from Valet's paths.");
    }, ['unpark'])->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name] [--secure]', function (OutputInterface $output, $name, $secure) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');

        if ($secure) {
            $this->runCommand('secure '.$name);
        }
    })->descriptions('Link the current working directory to Valet');

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function (OutputInterface $output) {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path', 'PHP Version'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function (OutputInterface $output, $name) {
        info('The ['.Site::unlink($name).'] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain] [--expireIn=]', function (OutputInterface $output, $domain = null, $expireIn = 368) {
        $url = Site::domain($domain);

        Site::secure($url, null, $expireIn);

        Nginx::restart();

        info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate', [
        '--expireIn' => 'The amount of days the self signed certificate is valid for. Default is set to "368"',
    ]);

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain] [--all]', function (OutputInterface $output, $domain = null, $all = null) {
        if ($all) {
            Site::unsecureAll();

            return;
        }

        $url = Site::domain($domain);

        Site::unsecure($url);

        Nginx::restart();

        info('The ['.$url.'] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Display all of the currently secured sites.
     */
    $app->command('secured', function (OutputInterface $output) {
        $sites = collect(Site::secured())->map(function ($url) {
            return ['Site' => $url];
        });

        table(['Site'], $sites->all());
    })->descriptions('Display all of the currently secured sites');

    /**
     * Create an Nginx proxy config for the specified domain.
     */
    $app->command('proxy domain host [--secure]', function (OutputInterface $output, $domain, $host, $secure) {
        Site::proxyCreate($domain, $host, $secure);
        Nginx::restart();
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, mailhog etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete an Nginx proxy config.
     */
    $app->command('unproxy domain', function (OutputInterface $output, $domain) {
        Site::proxyDelete($domain);
        Nginx::restart();
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all of the sites that are proxies.
     */
    $app->command('proxies', function (OutputInterface $output) {
        $proxies = Site::proxies();

        table(['Site', 'SSL', 'URL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function (OutputInterface $output) {
        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by ['.get_class($driver).'].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function (OutputInterface $output) {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            output(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            info('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current or given directory in the browser.
     */
    $app->command('open [domain]', function (OutputInterface $output, $domain = null) {
        $url = 'http://'.Site::domain($domain);
        CommandLine::runAsUser('open '.escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function (OutputInterface $output) {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url [domain]', function (OutputInterface $output, $domain = null) {
        output(Ngrok::currentTunnelUrl(Site::domain($domain)));
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Start the daemon services.
     */
    $app->command('start [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                DnsMasq::restart();
                PhpFpm::restart();
                Nginx::restart();

                return info('Valet services have been started.');
            case 'dnsmasq':
                DnsMasq::restart();

                return info('dnsmasq has been started.');
            case 'nginx':
                Nginx::restart();

                return info('Nginx has been started.');
            case 'php':
                PhpFpm::restart();

                return info('PHP has been started.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                DnsMasq::restart();
                PhpFpm::restart();
                Nginx::restart();

                return info('Valet services have been restarted.');
            case 'dnsmasq':
                DnsMasq::restart();

                return info('dnsmasq has been restarted.');
            case 'nginx':
                Nginx::restart();

                return info('Nginx has been restarted.');
            case 'php':
                PhpFpm::restart();

                return info('PHP has been restarted.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                PhpFpm::stopRunning();
                Nginx::stop();

                return info('Valet services have been stopped.');
            case 'nginx':
                Nginx::stop();

                return info('Nginx has been stopped.');
            case 'php':
                PhpFpm::stopRunning();

                return info('PHP has been stopped.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely. Requires --force to actually remove; otherwise manual instructions are displayed.
     */
    $app->command('uninstall [--force]', function (InputInterface $input, OutputInterface $output, $force) {
        if ($force) {
            warning('YOU ARE ABOUT TO UNINSTALL Nginx, PHP, Dnsmasq and all Valet configs and logs.');
            $helper = $this->getHelperSet()->get('question');
            $question = new ConfirmationQuestion('Are you sure you want to proceed? ', false);
            if (false === $helper->ask($input, $output, $question)) {
                return warning('Uninstall aborted.');
            }
            info('Removing certificates for all Secured sites...');
            Site::unsecureAll();
            info('Removing Nginx and configs...');
            Nginx::uninstall();
            info('Removing Dnsmasq and configs...');
            DnsMasq::uninstall();
            info('Removing loopback customization...');
            Site::uninstallLoopback();
            info('Removing Valet configs and customizations...');
            Configuration::uninstall();
            info('Removing PHP versions and configs...');
            PhpFpm::uninstall();
            info('Attempting to unlink Valet from bin path...');
            Valet::unlinkFromUsersBin();
            info('Removing sudoers entries...');
            Brew::removeSudoersEntry();
            Valet::removeSudoersEntry();

            return output('<fg=red>NOTE:</>
<comment>Valet has attempted to uninstall itself, but there are some steps you need to do manually:</comment>
Run <info>php -v</info> to see what PHP version you are now really using.
Run <info>composer global update</info> to update your globally-installed Composer packages to work with your default PHP.
NOTE: Composer may have other dependencies for other global apps you have installed, and those may not be compatible with your default PHP.
Thus, you may need to delete things from your <info>~/.composer/composer.json</info> file before running <info>composer global update</info> successfully.
Then to finish removing any Composer fragments of Valet:
Run <info>composer global remove laravel/valet</info>
and then <info>rm '.BREW_PREFIX.'/bin/valet</info> to remove the Valet bin link if it still exists.
Optional:
- <info>brew list --formula</info> will show any other Homebrew services installed, in case you want to make changes to those as well.
- <info>brew doctor</info> can indicate if there might be any broken things left behind.
- <info>brew cleanup</info> can purge old cached Homebrew downloads.
<fg=red>If you had customized your Mac DNS settings in System Preferences->Network, you will need to remove 127.0.0.1 from that list.</>
Additionally you might also want to open Keychain Access and search for <comment>valet</comment> to remove any leftover trust certificates.
');
        }

        output('WAIT! Before you uninstall things, consider cleaning things up in the following order. (Or skip to the bottom for troubleshooting suggestions.):
<info>You did not pass the <fg=red>--force</> parameter so we are NOT ACTUALLY uninstalling anything.</info>
A --force removal WILL delete your custom configuration information, so you will want to make backups first.

IF YOU WANT TO UNINSTALL VALET MANUALLY, DO THE FOLLOWING...

<info>1. Valet Keychain Certificates</info>
Before removing Valet configuration files, we recommend that you run <comment>valet unsecure --all</comment> to clean up the certificates that Valet inserted into your Keychain.
Alternatively you can do a search for <comment>@laravel.valet</comment> in Keychain Access and delete those certificates there manually.
You may also run <comment>valet parked</comment> to see a list of all sites Valet could serve.

<info>2. Valet Configuration Files</info>
<fg=red>You may remove your user-specific Valet config files by running:</>  <comment>rm -rf ~/.config/valet</comment>

<info>3. Remove Valet package</info>
You can run <comment>composer global remove laravel/valet</comment> to uninstall the Valet package.

<info>4. Homebrew Services</info>
<fg=red>You may remove the core services (php, nginx, dnsmasq) by running:</> <comment>brew uninstall --force php nginx dnsmasq</comment>
<fg=red>You can then remove selected leftover configurations for these services manually</> in both <comment>'.BREW_PREFIX.'/etc/</comment> and <comment>'.BREW_PREFIX.'/logs/</comment>.
(If you have other PHP versions installed, run <info>brew list --formula | grep php</info> to see which versions you should also uninstall manually.)

<error>BEWARE:</error> Uninstalling PHP via Homebrew will leave your Mac with its original PHP version, which may not be compatible with other Composer dependencies you have installed. Thus you may get unexpected errors.

Some additional services which you may have installed (but which Valet does not directly configure or manage) include: <comment>mariadb mysql mailhog</comment>.
If you wish to also remove them, you may manually run <comment>brew uninstall SERVICENAME</comment> and clean up their configurations in '.BREW_PREFIX.'/etc if necessary.

You can discover more Homebrew services by running: <comment>brew services list</comment> and <comment>brew list --formula</comment>

<fg=red>If you have customized your Mac DNS settings in System Preferences->Network, you may need to add or remove 127.0.0.1 from the top of that list.</>

<info>5. GENERAL TROUBLESHOOTING</info>
If your reasons for considering an uninstall are more for troubleshooting purposes, consider running <comment>brew doctor</comment> and/or <comment>brew cleanup</comment> to see if any problems exist there.
Also consider running <comment>sudo nginx -t</comment> to test your nginx configs in case there are failures/errors there preventing nginx from running.
Most of the nginx configs used by Valet are in your ~/.config/valet/Nginx directory.

You might also want to investigate your global Composer configs. Helpful commands include:
<comment>composer global update</comment> to apply updates to packages
<comment>composer global outdated</comment> to identify outdated packages
<comment>composer global diagnose</comment> to run diagnostics
');
        // Stopping PHP so the ~/.config/valet/valet.sock file is released so the directory can be deleted if desired
        PhpFpm::stopRunning();
        Nginx::stop();
    })->descriptions('Uninstall the Valet services', ['--force' => 'Do a forceful uninstall of Valet and related Homebrew pkgs']);

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('on-latest-version', function (OutputInterface $output) use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('Yes');
        } else {
            output(sprintf('Your version of Valet (%s) is not the latest version available.', $version));
            output('Upgrade instructions can be found in the docs: https://laravel.com/docs/valet#upgrading-valet');
        }
    }, ['latest'])->descriptions('Determine if this is the latest version of Valet');

    /**
     * Install the sudoers.d entries so password is no longer required.
     */
    $app->command('trust [--off]', function (OutputInterface $output, $off) {
        if ($off) {
            Brew::removeSudoersEntry();
            Valet::removeSudoersEntry();

            return info('Sudoers entries have been removed for Brew and Valet.');
        }

        Brew::createSudoersEntry();
        Valet::createSudoersEntry();

        info('Sudoers entries have been added for Brew and Valet.');
    })->descriptions('Add sudoers files for Brew and Valet to make Valet commands run without passwords', [
        '--off' => 'Remove the sudoers files so normal sudo password prompts are required.',
    ]);

    /**
     * Allow the user to change the version of php Valet uses.
     */
    $app->command('use [phpVersion] [--force]', function (OutputInterface $output, $phpVersion, $force) {
        if (! $phpVersion) {
            $site = basename(getcwd());
            $linkedVersion = Brew::linkedPhp();

            if ($phpVersion = Site::phpRcVersion($site)) {
                info("Found '{$site}/.valetphprc' specifying version: {$phpVersion}");
            } else {
                $domain = $site.'.'.data_get(Configuration::read(), 'tld');
                if ($phpVersion = PhpFpm::normalizePhpVersion(Site::customPhpVersion($domain))) {
                    info("Found isolated site '{$domain}' specifying version: {$phpVersion}");
                }
            }

            if (! $phpVersion) {
                return info("Valet is using {$linkedVersion}.");
            }

            if ($linkedVersion == $phpVersion && ! $force) {
                return info("Valet is already using {$linkedVersion}.");
            }
        }

        PhpFpm::useVersion($phpVersion, $force);
    })->descriptions('Change the version of PHP used by Valet', [
        'phpVersion' => 'The PHP version you want to use, e.g php@7.3',
    ]);

    /**
     * Allow the user to change the version of PHP Valet uses to serve the current site.
     */
    $app->command('isolate [phpVersion] [--site=]', function (OutputInterface $output, $phpVersion, $site = null) {
        if (! $site) {
            $site = basename(getcwd());
        }

        if (is_null($phpVersion) && $phpVersion = Site::phpRcVersion($site)) {
            info("Found '{$site}/.valetphprc' specifying version: {$phpVersion}");
        }

        PhpFpm::isolateDirectory($site, $phpVersion);
    })->descriptions('Change the version of PHP used by Valet to serve the current working directory', [
        'phpVersion' => 'The PHP version you want to use; e.g php@8.1',
        '--site' => 'Specify the site to isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Allow the user to un-do specifying the version of PHP Valet uses to serve the current site.
     */
    $app->command('unisolate [--site=]', function (OutputInterface $output, $site = null) {
        if (! $site) {
            $site = basename(getcwd());
        }

        PhpFpm::unIsolateDirectory($site);
    })->descriptions('Stop customizing the version of PHP used by Valet to serve the current working directory', [
        '--site' => 'Specify the site to un-isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * List isolated sites.
     */
    $app->command('isolated', function (OutputInterface $output) {
        $sites = PhpFpm::isolatedDirectories();

        table(['Path', 'PHP Version'], $sites->all());
    })->descriptions('List all sites using isolated versions of PHP.');

    /**
     * Get the PHP executable path for a site.
     */
    $app->command('which-php [site]', function (OutputInterface $output, $site) {
        $phpVersion = Site::customPhpVersion(
            Site::host($site ?: getcwd()).'.'.Configuration::read()['tld']
        );

        if (! $phpVersion) {
            $phpVersion = Site::phpRcVersion($site ?: basename(getcwd()));
        }

        return output(Brew::getPhpExecutablePath($phpVersion));
    })->descriptions('Get the PHP executable path for a given site', [
        'site' => 'The site to get the PHP executable path for',
    ]);

    /**
     * Proxy commands through to an isolated site's version of PHP.
     */
    $app->command('php [command]', function (OutputInterface $output, $command) {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');
    })->descriptions("Proxy PHP commands with isolated site's PHP executable", [
        'command' => "Command to run with isolated site's PHP executable",
    ]);

    /**
     * Proxy commands through to an isolated site's version of Composer.
     */
    $app->command('composer [command]', function (OutputInterface $output, $command) {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');
    })->descriptions("Proxy Composer commands with isolated site's PHP executable", [
        'command' => "Composer command to run with isolated site's PHP executable",
    ]);

    /**
     * Tail log file.
     */
    $app->command('log [-f|--follow] [-l|--lines=] [key]', function (OutputInterface $output, $follow, $lines, $key = null) {
        $defaultLogs = [
            'php-fpm' => BREW_PREFIX.'/var/log/php-fpm.log',
            'nginx' => VALET_HOME_PATH.'/Log/nginx-error.log',
            'mailhog' => BREW_PREFIX.'/var/log/mailhog.log',
            'redis' => BREW_PREFIX.'/var/log/redis.log',
        ];

        $configLogs = data_get(Configuration::read(), 'logs');
        if (! is_array($configLogs)) {
            $configLogs = [];
        }

        $logs = array_merge($defaultLogs, $configLogs);
        ksort($logs);

        if (! $key) {
            info(implode(PHP_EOL, [
                'In order to tail a log, pass the relevant log key (e.g. "nginx")',
                'along with any optional tail parameters (e.g. "-f" for follow).',
                null,
                'For example: "valet log nginx -f --lines=3"',
                null,
                'Here are the logs you might be interested in.',
                null,
            ]));

            table(
                ['Keys', 'Files'],
                collect($logs)->map(function ($file, $key) {
                    return [$key, $file];
                })->toArray()
            );

            info(implode(PHP_EOL, [
                null,
                'Tip: Set custom logs by adding a "logs" key/file object',
                'to your "'.Configuration::path().'" file.',
            ]));

            exit;
        }

        if (! isset($logs[$key])) {
            return warning('No logs found for ['.$key.'].');
        }

        $file = $logs[$key];
        if (! file_exists($file)) {
            return warning('Log path ['.$file.'] does not (yet) exist.');
        }

        $options = [];
        if ($follow) {
            $options[] = '-f';
        }
        if ((int) $lines) {
            $options[] = '-n '.(int) $lines;
        }

        $command = implode(' ', array_merge(['tail'], $options, [$file]));

        passthru($command);
    })->descriptions('Tail log file');

    /**
     * Configure or display the directory-listing setting.
     */
    $app->command('directory-listing [status]', function (OutputInterface $output, $status = null) {
        $key = 'directory-listing';
        $config = Configuration::read();

        if (in_array($status, ['on', 'off'])) {
            $config[$key] = $status;
            Configuration::write($config);

            return output('Directory listing setting is now: '.$status);
        }

        $current = isset($config[$key]) ? $config[$key] : 'off';
        output('Directory listing is '.$current);
    })->descriptions('Determine directory-listing behavior. Default is off, which means a 404 will display.', [
        'status' => 'on or off. (default=off) will show a 404 page; [on] will display a listing if project folder exists but requested URI not found',
    ]);

    /**
     * Output diagnostics to aid in debugging Valet.
     */
    $app->command('diagnose [-p|--print] [--plain]', function (OutputInterface $output, $print, $plain) {
        info('Running diagnostics... (this may take a while)');

        Diagnose::run($print, $plain);

        info('Diagnostics output has been copied to your clipboard.');
    })->descriptions('Output diagnostics to aid in debugging Valet.', [
        '--print' => 'print diagnostics output while running',
        '--plain' => 'format clipboard output as plain text',
    ]);
}

/**
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

return $app;
