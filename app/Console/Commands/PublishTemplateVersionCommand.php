<?php

namespace App\Console\Commands;

use App\Models\TemplateVersion;
use App\Services\TemplateCatalogService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class PublishTemplateVersionCommand extends Command
{
    protected $signature = 'templates:publish {version : TemplateVersion database ID} {source-checkout : Checkout of the source repository containing the pinned commit}';

    protected $description = 'Verify a pinned template definition and publish its immutable version.';

    public function handle(TemplateCatalogService $catalog): int
    {
        /** @var TemplateVersion|null $version */
        $version = TemplateVersion::query()->with('template')->find($this->argument('version'));
        if ($version === null) {
            $this->error('Template version was not found.');

            return self::FAILURE;
        }

        $checkout = realpath((string) $this->argument('source-checkout'));
        if ($checkout === false || ! is_dir($checkout)) {
            $this->error('Source checkout directory does not exist.');

            return self::FAILURE;
        }

        $rootProcess = new Process(['git', '-C', $checkout, 'rev-parse', '--show-toplevel']);
        $rootProcess->run();
        if (! $rootProcess->isSuccessful()) {
            $this->error('Source directory is not inside a Git checkout.');

            return self::FAILURE;
        }
        $repositoryRoot = trim($rootProcess->getOutput());

        $remoteProcess = new Process(['git', '-C', $repositoryRoot, 'remote', 'get-url', 'origin']);
        $remoteProcess->run();
        if (! $remoteProcess->isSuccessful() || ! $this->remoteMatchesRepository(trim($remoteProcess->getOutput()), $version->source_repository)) {
            $this->error('Checkout origin does not match the template version source repository.');

            return self::FAILURE;
        }

        $process = new Process(['git', '-C', $repositoryRoot, 'show', $version->source_revision.':'.$version->definition_path]);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->error('The pinned definition is unavailable at its recorded source revision/path.');

            return self::FAILURE;
        }

        try {
            $catalog->publish($version, $process->getOutput());
        } catch (Throwable $exception) {
            $this->error('Template version was not published: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Published template [{$version->template?->key}] version {$version->version} after source integrity and contract validation.");

        return self::SUCCESS;
    }

    private function remoteMatchesRepository(string $remote, string $repository): bool
    {
        $remote = preg_replace('/\.git$/', '', trim($remote)) ?? trim($remote);

        return in_array($remote, [
            'https://github.com/'.$repository,
            'http://github.com/'.$repository,
            'git@github.com:'.$repository,
            'ssh://git@github.com/'.$repository,
        ], true);
    }
}
