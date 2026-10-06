<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Fakes the johnny CLI (Garage passthrough) with real Garage 2.4 output from tests/Fixtures.
 */
trait FakesJohnnyCli
{
    /** Panel key id in the fixtures (johnny-default). */
    protected const PANEL_KEY_ID = 'GK1f8592d58bb63acea45ff5c7';

    protected const WEB_APP_KEY_ID = 'GK5d510a5dc69f28e6b744a688';

    protected function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/garage-2.4/{$name}.txt"));
    }

    /**
     * @param  array<string, string|ProcessResult>  $responses  "args prefix" => stdout or result; first match wins.
     */
    protected function fakeJohnny(array $responses = []): void
    {
        $responses += [
            'key list' => $this->fixture('key-list'),
            'bucket list' => $this->fixture('bucket-list'),
            'bucket info' => $this->fixture('bucket-info'),
            'key create' => $this->fixture('key-create'),
        ];

        Process::fake(function (PendingProcess $process) use ($responses) {
            $line = implode(' ', $this->johnnyArgs($process));

            foreach ($responses as $prefix => $result) {
                if ($line === $prefix || str_starts_with($line, $prefix.' ')) {
                    return is_string($result) ? Process::result($result) : $result;
                }
            }

            return Process::result();
        });
    }

    /**
     * Arguments after the johnny binary, e.g. ['bucket', 'create', 'media'].
     *
     * @return list<string>
     */
    protected function johnnyArgs(PendingProcess $process): array
    {
        $command = (array) $process->command;
        $index = array_search(config('services.johnny.binary'), $command, true);

        return $index === false ? $command : array_values(array_slice($command, $index + 1));
    }

    /**
     * @param  list<string>  $args
     */
    protected function assertJohnnyRan(array $args): void
    {
        Process::assertRan(fn (PendingProcess $p) => $this->johnnyArgs($p) === $args);
    }

    /**
     * @param  list<string>  $prefix
     */
    protected function assertJohnnyDidntRun(array $prefix): void
    {
        Process::assertDidntRun(
            fn (PendingProcess $p) => array_slice($this->johnnyArgs($p), 0, count($prefix)) === $prefix
        );
    }
}
