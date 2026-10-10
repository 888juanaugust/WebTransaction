<?php

namespace Tests\Feature\Client\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The deploy kit: the scripts parse, the service files name one place and one user, the workflow deploys the production branch after the suite. */
class DeployKitTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
    }

    public function test_the_scripts_parse_and_name_the_application_directory_and_user(): void
    {
        foreach (['deploy/provision.sh', 'deploy/deploy.sh', '.claude/hooks/session-start.sh'] as $script) {
            $process = new Process(['bash', '-n', $this->root.'/'.$script]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $script.': '.$process->getErrorOutput());
            $this->assertTrue(is_executable($this->root.'/'.$script) || $script === '.claude/hooks/session-start.sh', "{$script} is executable");
        }
        $provision = (string) file_get_contents($this->root.'/deploy/provision.sh');
        $this->assertStringContainsString('APP_DIR=/srv/central', $provision);
        $this->assertStringContainsString('APP_USER=central', $provision);
        $this->assertStringContainsString('maxmemory-policy noeviction', $provision);
        $this->assertStringContainsString('schedule:run', $provision);
        $this->assertStringNotContainsString('| bash', $provision, 'no installer piped to a shell');
        $this->assertStringNotContainsString('| php', $provision, 'no installer piped to php');
        $this->assertStringContainsString('installer.sig', $provision);
        $this->assertStringContainsString('signed-by=/etc/apt/keyrings/nodesource.gpg', $provision);

        $deploy = (string) file_get_contents($this->root.'/deploy/deploy.sh');
        foreach (['git pull --ff-only', 'migrate --force', 'storage:link', 'queue:restart', 'central:launch-check', 'rm -f bootstrap/cache/config.php', 'manifest.json'] as $needle) {
            $this->assertStringContainsString($needle, $deploy);
        }
        $this->assertStringContainsString('php artisan down', $deploy);
        $this->assertStringContainsString('php artisan up', $deploy);
    }

    public function test_the_service_files_agree_on_the_paths(): void
    {
        $caddy = (string) file_get_contents($this->root.'/deploy/Caddyfile');
        $this->assertStringContainsString('__DOMAIN__ {', $caddy);
        $this->assertStringContainsString('root * /srv/central/public', $caddy);
        $this->assertStringNotContainsString('header ', $caddy, 'the application sends its own headers');

        $worker = (string) file_get_contents($this->root.'/deploy/supervisor/central-worker.conf');
        $this->assertStringContainsString('[program:central-worker]', $worker);
        $this->assertStringContainsString('/srv/central/artisan queue:work redis', $worker);
        $this->assertStringContainsString('user=central', $worker);
        $this->assertStringContainsString('numprocs=2', $worker);

        $logrotate = (string) file_get_contents($this->root.'/deploy/logrotate/central');
        $this->assertStringContainsString('/srv/central/storage/logs/worker.log', $logrotate);
        $this->assertStringContainsString('copytruncate', $logrotate);
    }

    public function test_the_deploy_workflow_runs_the_suite_first_and_only_from_the_production_branch(): void
    {
        $deploy = (string) file_get_contents($this->root.'/.github/workflows/deploy.yml');
        foreach ([
            "  push:\n    branches: [production]", 'workflow_dispatch:', 'uses: ./.github/workflows/laravel.yml', 'needs: tests', 'environment: production',
            'cancel-in-progress: false', 'StrictHostKeyChecking=yes', 'cd /srv/central && git pull --ff-only && bash deploy/deploy.sh', 'DEPLOY_KNOWN_HOSTS is empty',
        ] as $needle) {
            $this->assertStringContainsString($needle, $deploy);
        }
        $this->assertStringNotContainsString('pull_request', $deploy, 'a pull request never deploys');

        $tests = (string) file_get_contents($this->root.'/.github/workflows/laravel.yml');
        $this->assertStringContainsString('workflow_call:', $tests);
        $this->assertStringContainsString('deploy/deploy.sh', $tests);
    }
}
