<?php

use App\Models\Creation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CostMeter;
use App\Services\Billing\Credits;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:make-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("User {$email} not found. Register first.");

        return 1;
    }

    $user->forceFill(['is_admin' => true])->save();
    $this->info("{$email} is now an admin.");
})->purpose('Grant admin rights to a registered user');

Artisan::command('pricing:report', function (CostMeter $meter) {
    $usd = fn (float $v) => '$'.number_format($v, 2);
    $mnt = fn (int $v) => number_format($v).'₮';

    $this->info('Price book (most expensive models; 1 credit = '.$mnt(config('pricing.credit_mnt')).')');
    $this->table(
        ['Job', 'Typical (Fable + media)', 'Worst (Fable + media)', 'Media budget', 'Credits', 'Revenue', '× typical', '× worst'],
        array_map(fn ($r) => [
            $r['label'],
            $usd($r['typical']['total']).' ('.$usd($r['typical']['claude']).' + '.$usd($r['typical']['media']).')',
            $usd($r['worst']['total']).' ('.$usd($r['worst']['claude']).' + '.$usd($r['worst']['media']).')',
            $usd($r['budget_usd']),
            $r['credits'],
            $mnt($r['revenue_mnt']),
            '×'.$r['margin_typical'],
            '×'.$r['margin_worst'],
        ], $meter->priceBook()),
    );

    $actuals = $meter->actuals();
    $this->info('Real cost of finished jobs (recorded by CostMeter)');
    $actuals
        ? $this->table(['Job', 'Jobs', 'Avg', 'Avg Fable', 'Avg media', 'Max', '× real'], array_map(fn ($a) => [
            $a['label'], $a['jobs'], $usd($a['avg_usd']), $usd($a['avg_claude_usd']), $usd($a['avg_media_usd']), $usd($a['max_usd']), $a['margin'] === null ? '—' : '×'.$a['margin'],
        ], $actuals))
        : $this->line('  No finished jobs yet.');
})->purpose('Show credit prices derived from unit costs, and real job costs');

// Fail creations whose worker died (job timeout + margin) so users are not left waiting.
Schedule::call(function () {
    Creation::whereIn('status', Creation::ACTIVE)
        ->where('updated_at', '<', now()->subSeconds(config('creations.job_timeout') + 600))
        ->each(function (Creation $creation) {
            $creation->update(['status' => Creation::FAILED, 'error_detail' => 'Stale: worker did not finish.', 'finished_at' => now()]);
            app(Credits::class)->refund($creation);
        });

    Payment::where('status', Payment::PENDING)
        ->where('created_at', '<', now()->subHours(config('qpay.invoice_ttl_hours')))
        ->update(['status' => Payment::EXPIRED]);
})->everyTenMinutes()->name('cleanup-stale');
