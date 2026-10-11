<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes the shipped dashboard script with controlled fetch responses
 * and browser events. No HTTP server or browser installation is needed.
 */
final class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        if (trim((string) @shell_exec('command -v node')) === '') {
            self::markTestSkipped('Node.js is not on PATH. Install Node.js to run the dashboard behavior tests.');
        }
    }

    public function testOnlyTheLatestAnswerCanReplaceTheDashboard(): void
    {
        $this->scenario('latest answer');
    }

    public function testAnOlderErrorCannotCloseANewerSuccessfulDashboard(): void
    {
        $this->scenario('older error');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queueTabs(): iterable
    {
        foreach (['overview', 'pending', 'processing', 'delayed', 'recent', 'failed', 'unknown'] as $tab) {
            yield $tab => [$tab];
        }
    }

    #[DataProvider('queueTabs')]
    public function testAQueueAddressOpensItsSubpageAndRequestsOnlyTheNeededList(string $tab): void
    {
        $this->scenario('initial route', $tab);
    }

    public function testOpeningAQueueListFetchesItImmediatelyAndResetsItsPageSize(): void
    {
        $this->scenario('navigation');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listedStates(): iterable
    {
        foreach (['pending', 'processing', 'delayed'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('listedStates')]
    public function testAQueueListShowsItsTimingAndExpandsAndPaginatesJobs(string $state): void
    {
        $this->scenario('job list', $state);
    }

    public function testARecentJobStaysExpandedWhenANewerTryIsInserted(): void
    {
        $this->scenario('recent identity');
    }

    public function testAnExceptionStaysExpandedWhenTheListOrderChanges(): void
    {
        $this->scenario('exception identity');
    }

    public function testACombinedWorkerGroupCountsForEverySupervisorItServes(): void
    {
        $this->scenario('worker groups');
    }

    public function testTheQueueOverviewLinksToItsListsAndCountsOutstandingFailures(): void
    {
        $this->scenario('queue overview');
    }

    public function testTheQueueVerdictNamesEachProblemAndLinksToFailedJobs(): void
    {
        $this->scenario('queue verdict');
    }

    public function testTheRecentSubpageFiltersOutcomesAndTheFailedSubpageOffersActions(): void
    {
        $this->scenario('recent and failed');
    }

    /**
     * Node is used only as a JavaScript runtime. The harness evaluates the
     * actual resource in memory and reports behavioral assertion failures
     * back to PHPUnit, which remains the package's single test command.
     */
    private function scenario(string $name, string $argument = ''): void
    {
        $process = proc_open(['node', __DIR__ . '/dashboard-test.js', dirname(__DIR__) . '/resources/dashboard.html', $name, $argument], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'Install Node.js to run the dashboard behavior tests.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame("passed\n", $output);
    }
}
