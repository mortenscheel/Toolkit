<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use function addslashes;
use function basename;
use function collect;
use function php_ini_loaded_file;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_replace;

class XdebugCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'xdebug  {--cli : Modify php-cli.ini}
                                    {--force : Generate ini file if not exists} 
                                    {--enable : Enable Xdebug}
                                    {--disable : Disable Xdebug}
                                    {--toggle : Toggle Xdebug}
                                    {--status : Show Xdebug status}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Handy tools to manage Xdebug';
    /**
     * @var bool
     */
    public $cli;
    /**
     * @var string
     */
    public $ini_path;
    /**
     * @var string
     */
    public $ini_content;
    /**
     * @var bool
     */
    public $xdebug_enabled;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->parseIni()) {
            return 1;
        }
        $actions = collect(['enable', 'disable', 'toggle', 'status'])
            ->filter(function ($action) {
                return $this->option($action);
            });
        if ($actions->isEmpty()) {
            $action = 'status';
        } else if ($actions->count() > 1) {
            $this->error('Only one command is allowed');
            return 1;
        } else {
            $action = $actions->first();
        }
        switch ($action) {
            case 'toggle':
                $this->toggle();
                $this->restartValetIfAppropriate();
                break;
            case 'enable':
                $this->enable();
                $this->restartValetIfAppropriate();
                break;
            case 'disable':
                $this->disable();
                $this->restartValetIfAppropriate();
                break;
            case 'status':
                $this->status();
                break;
        }
        return 0;
    }

    public function enable(): void
    {
        $this->modifyIni('^;zend_extension="xdebug.so"', 'zend_extension="xdebug.so"');
        $this->status();
    }

    public function disable(): void
    {
        $this->modifyIni('^zend_extension="xdebug.so"', ';zend_extension="xdebug.so"');
        $this->status();
    }

    public function toggle(): void
    {
        if ($this->xdebug_enabled) {
            $this->disable();
        } else {
            $this->enable();
        }
    }

    public function status(): void
    {
        $this->info(sprintf(
                'Xdebug is <fg=%s;options=bold>%s</> <fg=white>(%s)</>',
                $this->xdebug_enabled ? 'green' : 'red',
                $this->xdebug_enabled ? 'enabled' : 'disabled',
                basename($this->ini_path))
        );
    }

    /**
     * Define the command's schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    public function parseIni(): bool
    {
        $ini_path = null;
        $cli = $this->option('cli');
        $current_ini_path = php_ini_loaded_file();
        $current_is_cli = (bool)preg_match('/cli\.ini$/', $current_ini_path);
        if ($cli) {
            if ($current_is_cli) {
                $ini_path = $current_ini_path;
            } else {
                $cli_path = str_replace('php.ini', 'php-cli.ini', $current_ini_path);
                if (!File::exists($cli_path) && $this->option('force')) {
                    File::copy($current_ini_path, $cli_path);
                    $this->info('Generated new CLI ini file at ' . $cli_path);
                }
                $ini_path = $cli_path;
            }
        } else {
            if (!$current_is_cli) {
                $ini_path = $current_ini_path;
            } else {
                $non_cli_path = str_replace('php-cli.ini', 'php.ini', $current_ini_path);
                if (!File::exists($non_cli_path) && $this->option('force')) {
                    File::copy($current_ini_path, $non_cli_path);
                    $this->info('Generated new default ini file at ' . $non_cli_path);
                }
                $ini_path = $non_cli_path;
            }
        }
        if (!File::exists($ini_path)) {
            $this->error('Ini file not found in ' . $ini_path);
            $this->comment('Use --force to generate');
            return false;
        }
        $this->cli = $cli;
        $this->ini_path = $ini_path;
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->ini_content = File::get($ini_path);
        $this->xdebug_enabled = $this->getXdebugStatus();
        return true;
    }

    /**
     * @return bool
     */
    public function getXdebugStatus(): bool
    {
        return (bool)preg_match('/^zend_extension="xdebug.so"/m', $this->ini_content);
    }

    public function modifyIni(string $search, string $replace, bool $reload = true): void
    {
        $pattern = sprintf('/%s/m', addslashes($search));
        File::put($this->ini_path, preg_replace($pattern, $replace, $this->ini_content));
        if ($reload) {
            $this->parseIni();
        }
    }

    public function restartValetIfAppropriate(): void
    {
        if (!$this->cli && Config::get('toolkit.xdebug.restart_valet')) {
            $this->task('Restarting valet', function () {
                (new Process(['valet', 'restart']))->run();
            }, 'restarting...');
        }
    }
}
