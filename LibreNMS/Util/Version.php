<?php
/**
 * Version.php
 *
 * Get version info about LibreNMS and various components/dependencies
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Util;

use DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LibreNMS\Config;
use LibreNMS\DB\Eloquent;
use Symfony\Component\Process\Process;

class Version
{
    // Update this on release
    public const VERSION = '22.6.0';

    /**
     * @var bool
     */
    protected $is_git_install = false;

    public function __construct()
    {
        $this->is_git_install = Git::repoPresent() && Git::binaryExists();
    }

    public static function get(): Version
    {
        return new static;
    }

    public function local(): string
    {
        if ($this->is_git_install && $version = $this->fromGit()) {
            return $version;
        }

        return self::VERSION;
    }

    /**
     * Compiles local commit data
     *
     * @return array with keys sha, date, and branch
     */
    public function localCommit(): array
    {
        if ($this->is_git_install) {
            $install_dir = base_path();
            $version_process = new Process(['git', 'show', '-q', '--pretty=%H|%ct'], $install_dir);
            $version_process->run();

            // failed due to permissions issue
            if ($version_process->getExitCode() == 128 && Str::startsWith($version_process->getErrorOutput(), 'fatal: unsafe repository')) {
                (new Process(['git', 'config', '--global', '--add', 'safe.directory', $install_dir]))->run();
                $version_process->run();
            }

            [$local_sha, $local_date] = array_pad(explode('|', rtrim($version_process->getOutput())), 2, '');

            $branch_process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $install_dir);
            $branch_process->run();
            $branch = rtrim($branch_process->getOutput());

            return [
                'sha' => $local_sha,
                'date' => $local_date,
                'branch' => $branch,
            ];
        }

        return ['sha' => null, 'date' => null, 'branch' => null];
    }

    /**
     * Fetches the remote commit from the github api if on the daily release channel
     *
     * @return array
     */
    public function remoteCommit(): array
    {
        if ($this->is_git_install && Config::get('update_channel') == 'master') {
            try {
                $github = \Http::withOptions(['proxy' => Proxy::forGuzzle()])->get(Config::get('github_api') . 'commits/master');

                return $github->json();
            } catch (ConnectionException $e) {
            }
        }

        return [];
    }

    public function databaseServer(): string
    {
        return Eloquent::isConnected() ? Arr::first(DB::selectOne('select version()')) : 'Not Connected';
    }

    public function database(): array
    {
        if (Eloquent::isConnected()) {
            try {
                $query = Eloquent::DB()->table('migrations');

                return [
                    'last' => $query->orderBy('id', 'desc')->value('migration'),
                    'total' => $query->count(),
                ];
            } catch (\Exception $e) {
                return ['last' => 'No Schema', 'total' => 0];
            }
        }

        return ['last' => 'Not Connected', 'total' => 0];
    }

    private function fromGit(): string
    {
        return rtrim(shell_exec('git describe --tags 2>/dev/null'));
    }

    public function gitChangelog(): string
    {
        return $this->is_git_install
            ? rtrim(shell_exec('git log -10'))
            : '';
    }

    public function gitDate(): string
    {
        return $this->is_git_install
            ? rtrim(shell_exec("git show --pretty='%ct' -s HEAD"))
            : '';
    }

    public function python(): string
    {
        $proc = new Process(['python3', '--version']);
        $proc->run();

        if ($proc->getExitCode() !== 0) {
            return '';
        }

        return explode(' ', rtrim($proc->getOutput()), 2)[1] ?? '';
    }

    public function rrdtool(): string
    {
        $process = new Process([Config::get('rrdtool', 'rrdtool'), '--version']);
        $process->run();
        preg_match('/^RRDtool ([\w.]+) /', $process->getOutput(), $matches);

        return str_replace('1.7.01.7.0', '1.7.0', $matches[1] ?? '');
    }

    public function netSnmp(): string
    {
        $process = new Process([Config::get('snmpget', 'snmpget'), '-V']);
        $process->run();
        preg_match('/[\w.]+$/', $process->getErrorOutput(), $matches);

        return $matches[0] ?? '';
    }
}
