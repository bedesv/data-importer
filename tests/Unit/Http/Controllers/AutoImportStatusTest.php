<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Console\AutoImports;
use App\Enums\ExitCode;
use App\Exceptions\ImporterErrorException;
use App\Http\Controllers\AutoImportController;
use App\Models\ImportJob;
use ReflectionMethod;
use Tests\TestCase;

class AutoImportStatusTest extends TestCase
{
    public function test_http_response_distinguishes_success_empty_directory_and_failure(): void
    {
        $controller = new AutoImportController();
        $response = new ReflectionMethod($controller, 'importResponse');
        $this->assertSame(422, $response->invoke($controller, [])->getStatusCode());
        $this->assertSame(200, $response->invoke($controller, ['first' => 0, 'second' => 73])->getStatusCode());
        $this->assertSame(500, $response->invoke($controller, ['first' => 0, 'second' => 1])->getStatusCode());
    }

    public function test_directory_import_distinguishes_empty_results_from_failed_conversions(): void
    {
        $runner = new FakeAutoImportRunner();
        $result = $runner->runFiles('/tmp', [
            'config' => ['success', 'empty', 'conversion-error', 'exception'],
        ]);
        $this->assertSame([
            'success' => 0,
            'empty' => ExitCode::NOTHING_WAS_IMPORTED->value,
            'conversion-error' => ExitCode::GENERAL_ERROR->value,
            'exception' => 1,
        ], $result);
    }
}

class FakeAutoImportRunner
{
    use AutoImports {
        importFiles as public runFiles;
    }

    private function importFileAsImportJob(string $jsonFile, string $importableFile): int
    {
        if ('exception' === $importableFile) {
            throw new ImporterErrorException('Test failure');
        }
        $this->importJob = ImportJob::createNew();
        if ('conversion-error' === $importableFile) {
            $this->importJob->conversionStatus->addError(0, 'Conversion failed');
        }

        return 'success' === $importableFile ? ExitCode::SUCCESS->value : ExitCode::NOTHING_WAS_IMPORTED->value;
    }
}
