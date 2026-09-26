<?php

namespace App\Console\Commands;

use App\Services\Release\ReleaseManifestVerifier;
use Illuminate\Console\Command;

class VerifyReleaseManifest extends Command
{
    protected $signature = 'release:verify {manifest} {--environment=}';

    protected $description = 'Verify tenant, account, data and asset release conditions without exposing business content.';

    public function handle(ReleaseManifestVerifier $verifier): int
    {
        try {
            $environment = trim((string) $this->option('environment'));
            if ($environment === '') {
                throw new \RuntimeException('Explicit environment label is required.');
            }
            $result = $verifier->verify((string) $this->argument('manifest'), $environment);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['result'] === 'PASS' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Release verification stopped: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
