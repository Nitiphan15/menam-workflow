<?php

namespace Tests\Unit;

use App\Support\FormFcPeriod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FormFcPeriodConsistencyTest extends TestCase
{
    public function test_every_formfc_surface_uses_the_shared_four_month_period(): void
    {
        $this->assertSame(4, FormFcPeriod::MONTHS);

        $files = array_merge(
            $this->filesUnder(app_path('Http/Controllers/FormFC'), ['php']),
            $this->filesUnder(resource_path('views/formfc'), ['php'])
        );

        $forbiddenPatterns = [
            '/range\(1,\s*6\)/',
            '/range\(0,\s*5\)/',
            '/subMonths\(6\)/',
            '/subMonths\(5\)/',
            '/slice\(-6\)/',
            '/take\(6\)/',
            '/\*\s*6\b/',
            '/Avg\s*6M/i',
            '/Forecast\s*6M/i',
            '/6\s*\x{0E40}\x{0E14}\x{0E37}\x{0E2D}\x{0E19}/u',
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    $file . ' still contains a hard-coded six-month Form FC period.'
                );
            }
        }
    }

    private function filesUnder(string $directory, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
