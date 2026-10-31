<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Doctor\DoctorRunner;
use MadeByClowd\Documentable\Doctor\ProbeStatus;
use Throwable;

/**
 * Runs diagnostics against a configured storage disk and bucket permissions.
 */
class DocumentsDoctorCommand extends Command
{
    protected $signature = 'documents:doctor
        {disk : The filesystem disk to test}';

    protected $description = 'Run diagnostics against a storage disk to test permissions and compatibility.';

    public function handle(DoctorRunner $runner): int
    {
        $diskArgument = $this->argument('disk');
        $disk = is_string($diskArgument) ? $diskArgument : '';

        $this->info("Running diagnostics for disk [{$disk}]...");

        try {
            $results = $runner->run($disk, fn (string $message) => $this->warn($message));
        } catch (Throwable $e) {
            $this->error("Doctor run failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($results as $result) {
            $tag = match ($result->status) {
                ProbeStatus::Pass => '<info>[PASS]</info>',
                ProbeStatus::Fail => '<error>[FAIL]</error>',
                ProbeStatus::Skipped => '<comment>[SKIPPED]</comment>',
            };

            $this->line("{$tag} {$result->name}: {$result->message}");

            if ($result->status === ProbeStatus::Fail) {
                $failed = true;
                if ($result->fix !== null && $result->fix !== '') {
                    $this->line("       <fg=gray>Fix: {$result->fix}</>");
                }
            } elseif ($result->status === ProbeStatus::Skipped && $result->fix !== null && $result->fix !== '') {
                $this->line("       <fg=gray>Note: {$result->fix}</>");
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('One or more doctor probes failed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All diagnostics passed (or skipped).');

        return self::SUCCESS;
    }
}
