<?php

namespace App\Console\Commands;

use App\Services\TemplateCatalogService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class RegisterTemplatesCommand extends Command
{
    private const SOURCE_REPOSITORY = 'deputy-proxy/cr8or-media';

    protected $signature = 'templates:register {path : The cr8or-media data/templates directory} {--source-revision= : Immutable source commit SHA that must match checkout HEAD}';

    protected $description = 'Register immutable template definition versions from a pinned source checkout.';

    public function handle(TemplateCatalogService $catalog, \App\Services\TemplateDefinitionAdapter $adapter): int
    {
        $root = realpath((string) $this->argument('path'));
        if ($root === false || ! is_dir($root)) {
            $this->error('Template definition directory does not exist.');

            return self::FAILURE;
        }

        $rootProcess = new Process(['git', '-C', $root, 'rev-parse', '--show-toplevel']);
        $rootProcess->run();
        if (! $rootProcess->isSuccessful()) {
            $this->error('The catalogue directory must be inside a Git checkout so repository-relative definition paths can be recorded.');

            return self::FAILURE;
        }
        $repositoryRoot = trim($rootProcess->getOutput());

        if (! str_starts_with($root, $repositoryRoot.DIRECTORY_SEPARATOR)) {
            $this->error('The catalogue directory must be inside the source repository checkout.');

            return self::FAILURE;
        }
        $relativeCatalogPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($root, strlen($repositoryRoot) + 1));
        if ($relativeCatalogPath !== 'data/templates') {
            $this->error('Only the canonical repository data/templates catalogue directory can be registered.');

            return self::FAILURE;
        }

        $sourceRepository = self::SOURCE_REPOSITORY;
        $remoteProcess = new Process(['git', '-C', $repositoryRoot, 'remote', 'get-url', 'origin']);
        $remoteProcess->run();
        if (! $remoteProcess->isSuccessful() || ! $this->remoteMatchesRepository(trim($remoteProcess->getOutput()), $sourceRepository)) {
            $this->error('The checkout origin is not the canonical deputy-proxy/cr8or-media repository.');

            return self::FAILURE;
        }

        $revisionProcess = new Process(['git', '-C', $repositoryRoot, 'rev-parse', 'HEAD']);
        $revisionProcess->run();
        if (! $revisionProcess->isSuccessful()) {
            $this->error('Could not resolve the source checkout commit.');

            return self::FAILURE;
        }
        $actualRevision = strtolower(trim($revisionProcess->getOutput()));
        $requestedRevision = strtolower((string) $this->option('source-revision'));
        if ($requestedRevision !== '' && ! hash_equals($actualRevision, $requestedRevision)) {
            $this->error('--source-revision must match the checkout HEAD commit.');

            return self::FAILURE;
        }
        $revision = $actualRevision;
        if (! preg_match('/^[a-f0-9]{40,64}$/', $revision)) {
            $this->error('Source revision must be a full Git commit SHA.');

            return self::FAILURE;
        }

        $cleanProcess = new Process(['git', '-C', $repositoryRoot, 'status', '--porcelain', '--untracked-files=all', '--', 'data/templates']);
        $cleanProcess->run();
        if (! $cleanProcess->isSuccessful() || trim($cleanProcess->getOutput()) !== '') {
            $this->error('The template catalogue has uncommitted changes. Commit the definitions before registering them.');

            return self::FAILURE;
        }
        $files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)));
        $jsonFiles = array_values(array_filter($files, fn (\SplFileInfo $file): bool => $file->isFile() && strtolower($file->getExtension()) === 'json'));
        usort($jsonFiles, fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));
        if ($jsonFiles === []) {
            $this->error('No JSON definition files were found. Nothing was registered.');

            return self::FAILURE;
        }

        $pending = [];
        $seenKeys = [];
        try {
            foreach ($jsonFiles as $file) {
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    throw new \RuntimeException('Could not read '.$file->getFilename().'.');
                }
                $definition = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($definition)) {
                    throw new \RuntimeException('Definition '.$file->getFilename().' must contain a JSON object.');
                }
                $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($repositoryRoot) + 1));
                $metadata = $adapter->normalize($definition, $path);
                $key = (string) $metadata['key'];
                if (isset($seenKeys[$key])) {
                    throw new \RuntimeException("Multiple definitions declare the same stable template key [{$key}].");
                }
                $seenKeys[$key] = true;
                $pending[] = [$definition, $sourceRepository, $revision, $path, $contents];
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($pending, $catalog): void {
                foreach ($pending as [$definition, $repository, $revision, $path, $contents]) {
                    $catalog->registerDefinition($definition, $repository, $revision, $path, $contents);
                }
            });
        } catch (Throwable $exception) {
            $this->error('Registration failed; no batch changes were committed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Validated and registered '.count($pending).' definition(s) from '.$revision.'. New versions remain drafts until explicitly published.');

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
