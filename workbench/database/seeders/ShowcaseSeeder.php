<?php

namespace Workbench\Database\Seeders;

use App\Jobs\ChargeSubscription;
use App\Jobs\DeliverWebhook;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\ImportCustomers;
use App\Jobs\ResizeProductImages;
use App\Jobs\SendOrderConfirmation;
use App\Jobs\SendShippingUpdate;
use App\Jobs\SyncInventory;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Justrau\Orrery\Jobs\MonitorTag;
use RuntimeException;

/**
 * Seeds the showcase mode (WorkbenchShowcase): a small shop app for the screenshots of the
 * README and the docs. Its jobs and exceptions live in workbench/showcase/app under the
 * conventional App\ namespace (autoload-dev), so the dashboard shows App\Jobs\… names.
 * It is opt-in (`composer workbench:showcase`): the default build never runs it, and
 * workbench/fixtures.md does not count it.
 *
 * Like DatabaseSeeder, it uses the package's real code paths only. Jobs go through the swapped
 * `database` queue connector and the real `queue:work` command works them; `orrery:tick` counts
 * the minutes. The day of traffic runs with Laravel's clock moved into the past
 * (Carbon::setTestNow(), which the package's clock follows), slot by slot, with a tick every hour
 * and every five minutes in the last hour, so the charts have a counted history.
 * The draws use a fixed seed; the day's curve follows the clock of the build.
 */
class ShowcaseSeeder extends Seeder
{
    /** The minutes of history: within the 24 hours that the samples are kept. */
    public const HISTORY_MINUTES = 23 * 60 + 40;

    /** The minutes of one slot of the plan. */
    public const SLOT_MINUTES = 2;

    /** The tags that the dashboard monitors. */
    public const MONITORED_TAGS = ['order:1042', 'customer:88'];

    /** The number of the newest order, so order 1042 falls in the last half hour. */
    public const LAST_ORDER = 1056;

    public const BATCH_NAME = 'Import customers';

    /** The batch members, and how many of them are done when the build ends. */
    public const BATCH_CHUNKS = 12;

    public const BATCH_DONE = 5;

    /** The minutes before the end of the history at which the batch starts. */
    public const BATCH_STARTED_BEFORE = 10;

    /** The seconds that each open batch member takes in a showcase worker. */
    public const BATCH_CHUNK_SECONDS = 45;

    /** Every queue of the shop, in the order that the seeding worker reads them. */
    private const QUEUES = 'payments,emails,webhooks,default,exports,notifications';

    /**
     * The failures, by minutes before the end of the history: a payment gateway that times out
     * now and then, a short SMTP outage, and a partner whose webhook endpoint is gone.
     */
    private const PAYMENT_FAILURES = [340, 255, 170, 96, 41, 12];

    private const SMTP_FAILURES = [52, 50, 49, 47];

    private const WEBHOOK_FAILURES = [175, 88, 23];

    public function run(): void
    {
        mt_srand(1042);

        $end = Carbon::now()->startOfMinute();
        $start = $end->copy()->subMinutes(self::HISTORY_MINUTES);

        // 1. Monitor the tags, and set the frontier of the counts at the start of the history,
        //    so the ticks below count every minute of the day.
        $this->at($start->copy()->subMinute(), function (): void {
            foreach (self::MONITORED_TAGS as $tag) {
                dispatch_sync(new MonitorTag($tag));
            }

            $this->artisan('orrery:tick');
        });
        $this->step('Monitoring '.implode(' and ', self::MONITORED_TAGS).'; the counts start at '.$start->toDateTimeString());

        // 2. A day of traffic, slot by slot.
        //    The batch "Import customers" starts BATCH_STARTED_BEFORE minutes before the end:
        //    its first BATCH_DONE chunks are done at once, and the others wait until the end of
        //    the history (a delay), so no slot works them. At the real time they are ready on
        //    `exports` for the showcase worker app-3, which takes BATCH_CHUNK_SECONDS for each,
        //    so `exports` is over its wait limit (one gentle warning) while the batch moves on.
        $plan = $this->plan($start, $end);
        $jobs = 0;
        $batch = false;

        foreach ($plan as $slot) {
            $this->at($slot['time'], function () use ($slot, &$jobs, &$batch, $end): void {
                if (! $batch && $slot['time']->diffInMinutes($end, true) <= self::BATCH_STARTED_BEFORE) {
                    $chunks = [];

                    for ($chunk = 1; $chunk <= self::BATCH_CHUNKS; $chunk++) {
                        $chunks[] = $chunk <= self::BATCH_DONE
                            ? new ImportCustomers($chunk)
                            : (new ImportCustomers($chunk, self::BATCH_CHUNK_SECONDS))->delay($end);
                    }

                    Bus::batch($chunks)->name(self::BATCH_NAME)->onConnection('database')->onQueue('exports')->allowFailures()->dispatch();
                    $batch = true;
                }

                $jobs += $this->push($slot);
                $this->work(self::QUEUES);

                $minutesBefore = (int) $slot['time']->diffInMinutes($end, true);

                if ($slot['time']->minute < self::SLOT_MINUTES || ($minutesBefore <= 60 && $slot['time']->minute % 5 < self::SLOT_MINUTES)) {
                    $this->artisan('orrery:tick');
                }
            });
        }

        $this->step(sprintf('Worked %d jobs over %d slots', $jobs, count($plan)));

        if (! $batch) {
            throw new RuntimeException('The showcase history ended before the batch started.');
        }

        $this->step(sprintf('Started the batch "%s": %d of %d chunks done', self::BATCH_NAME, self::BATCH_DONE, self::BATCH_CHUNKS));

        // 3. A tick at the real time.
        $this->artisan('orrery:tick');
        $this->step('Ran orrery:tick');
    }

    /**
     * The slots of the history: the time, the orders and the other work of each one. Traffic
     * follows a day: quiet at night, a peak before noon and a smaller one in the evening.
     *
     * @return list<array{time: Carbon, orders: list<array{order: int, customer: int, smtp: bool, webhook: bool, webhook_fails: bool, shipping: bool}>, charges: list<array{subscription: int, customer: int, fails: bool}>, syncs: list<int>, images: list<int>}>
     */
    private function plan(Carbon $start, Carbon $end): array
    {
        $slots = [];

        for ($time = $start->copy(); $time->lessThan($end); $time->addMinutes(self::SLOT_MINUTES)) {
            $hour = $time->hour + $time->minute / 60;
            $load = 0.12 + exp(-(($hour - 11) ** 2) / 8) + 0.7 * exp(-(($hour - 19) ** 2) / 4);
            $slots[] = ['time' => $time->copy(), 'load' => $load, 'before' => (int) $time->diffInMinutes($end, true)];
        }

        $orders = array_map(fn (array $slot): int => $this->draw(2.2 * $slot['load']), $slots);
        $number = self::LAST_ORDER - array_sum($orders);
        $subscription = 5000;
        $plan = [];

        foreach ($slots as $index => $slot) {
            $entry = ['time' => $slot['time'], 'orders' => [], 'charges' => [], 'syncs' => [], 'images' => []];

            for ($i = 0; $i < $orders[$index]; $i++) {
                $number++;
                $entry['orders'][] = [
                    'order' => $number,
                    'customer' => $number === 1042 || $number % 97 === 0 ? 88 : mt_rand(1, 140),
                    'smtp' => false,
                    'webhook' => mt_rand(1, 10) <= 6,
                    'webhook_fails' => false,
                    'shipping' => mt_rand(1, 10) <= 4,
                ];
            }

            for ($i = $this->draw(0.9 * $slot['load']); $i > 0; $i--) {
                $entry['charges'][] = ['subscription' => ++$subscription, 'customer' => mt_rand(1, 140), 'fails' => false];
            }

            for ($i = $this->draw(1.6 * $slot['load']); $i > 0; $i--) {
                $entry['syncs'][] = mt_rand(100, 480);
            }

            for ($i = $this->draw(0.6 * $slot['load']); $i > 0; $i--) {
                $entry['images'][] = mt_rand(100, 480);
            }

            $entry = $this->failures($entry, $slot['before']);
            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * Marks the failures that fall in a slot, adding the job when the slot has none to mark.
     *
     * @param  array{time: Carbon, orders: list<array{order: int, customer: int, smtp: bool, webhook: bool, webhook_fails: bool, shipping: bool}>, charges: list<array{subscription: int, customer: int, fails: bool}>, syncs: list<int>, images: list<int>}  $entry
     * @return array{time: Carbon, orders: list<array{order: int, customer: int, smtp: bool, webhook: bool, webhook_fails: bool, shipping: bool}>, charges: list<array{subscription: int, customer: int, fails: bool}>, syncs: list<int>, images: list<int>}
     */
    private function failures(array $entry, int $before): array
    {
        $in = static fn (array $minutes): int => count(array_filter($minutes, static fn (int $minute): bool => $minute <= $before && $minute > $before - self::SLOT_MINUTES));

        for ($i = $in(self::PAYMENT_FAILURES); $i > 0; $i--) {
            $entry['charges'][] = ['subscription' => 4900 + $before, 'customer' => mt_rand(1, 140), 'fails' => true];
        }

        foreach (['smtp' => self::SMTP_FAILURES, 'webhook_fails' => self::WEBHOOK_FAILURES] as $flag => $minutes) {
            for ($i = $in($minutes); $i > 0; $i--) {
                $orders = $entry['orders'];
                $index = null;

                foreach ($orders as $key => $order) {
                    if (! $order[$flag]) {
                        $index = $key;

                        break;
                    }
                }

                // A quiet slot without an order to mark gets one more order of the same customer range.
                $order = $index === null
                    ? ['order' => ($orders === [] ? self::LAST_ORDER : $orders[array_key_last($orders)]['order']), 'customer' => mt_rand(1, 140), 'smtp' => false, 'webhook' => false, 'webhook_fails' => false, 'shipping' => false]
                    : $orders[$index];

                if ($flag === 'smtp') {
                    $order['smtp'] = true;
                } else {
                    $order['webhook'] = true;
                    $order['webhook_fails'] = true;
                }

                if ($index === null) {
                    $orders[] = $order;
                } else {
                    $orders[$index] = $order;
                }

                $entry['orders'] = $orders;
            }
        }

        return $entry;
    }

    /**
     * Pushes the jobs of one slot; returns how many.
     *
     * @param  array{time: Carbon, orders: list<array{order: int, customer: int, smtp: bool, webhook: bool, webhook_fails: bool, shipping: bool}>, charges: list<array{subscription: int, customer: int, fails: bool}>, syncs: list<int>, images: list<int>}  $slot
     */
    private function push(array $slot): int
    {
        $count = 0;

        foreach ($slot['orders'] as $order) {
            SendOrderConfirmation::dispatch($order['order'], $order['customer'], $order['smtp'])->onConnection('database')->onQueue('emails');
            GenerateInvoicePdf::dispatch($order['order'], $order['customer'])->onConnection('database')->onQueue('exports');
            $count += 2;

            if ($order['webhook']) {
                DeliverWebhook::dispatch($order['order'], 'order.paid', $order['webhook_fails'])->onConnection('database')->onQueue('webhooks');
                $count++;
            }

            if ($order['shipping']) {
                SendShippingUpdate::dispatch($order['order'], $order['customer'])->onConnection('database')->onQueue('notifications');
                $count++;
            }
        }

        foreach ($slot['charges'] as $charge) {
            ChargeSubscription::dispatch($charge['subscription'], $charge['customer'], $charge['fails'])->onConnection('database')->onQueue('payments');
            $count++;
        }

        foreach ($slot['syncs'] as $product) {
            SyncInventory::dispatch($product)->onConnection('database')->onQueue('default');
            $count++;
        }

        foreach ($slot['images'] as $product) {
            ResizeProductImages::dispatch($product)->onConnection('database')->onQueue('default');
            $count++;
        }

        return $count;
    }

    /** A whole number around $mean (a cheap Poisson-like draw). */
    private function draw(float $mean): int
    {
        $whole = (int) floor($mean);

        return $whole + (mt_rand() / mt_getrandmax() < $mean - $whole ? 1 : 0) + (mt_rand(1, 6) === 1 ? 1 : 0) - (mt_rand(1, 6) === 1 && $whole > 0 ? 1 : 0);
    }

    /**
     * Run $work with Laravel's clock (and so the package's clock) at $time.
     */
    private function at(Carbon $time, Closure $work): void
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($time);

        try {
            $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * Run the real `queue:work` command until the queues are empty, or with the given limits.
     *
     * @param  array<string, int|bool>  $limits
     */
    private function work(string $queues, array $limits = []): void
    {
        $status = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--memory' => 1024,
        ] + $limits);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('queue:work --queue=%s exited with %d: %s', $queues, $status, Artisan::output()));
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisan(string $command, array $arguments = []): void
    {
        $status = Artisan::call($command, $arguments);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('%s exited with %d: %s', $command, $status, Artisan::output()));
        }
    }

    private function step(string $message): void
    {
        $this->command?->info('Showcase seeder: '.$message.'.');
    }
}
