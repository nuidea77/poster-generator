<?php

use App\Models\Creation;
use App\Models\Payment;
use App\Models\User;
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

// Fail creations whose worker died (job timeout + margin) so users are not left waiting.
Schedule::call(function () {
    Creation::whereIn('status', Creation::ACTIVE)
        ->where('updated_at', '<', now()->subSeconds(config('creations.job_timeout') + 600))
        ->update(['status' => Creation::FAILED, 'error_detail' => 'Stale: worker did not finish.', 'finished_at' => now()]);

    Payment::where('status', Payment::PENDING)
        ->where('created_at', '<', now()->subHours(config('qpay.invoice_ttl_hours')))
        ->update(['status' => Payment::EXPIRED]);
})->everyTenMinutes()->name('cleanup-stale');
