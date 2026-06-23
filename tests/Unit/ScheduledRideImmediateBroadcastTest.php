<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ScheduledRideImmediateBroadcastTest extends TestCase
{
    // Reads a project file for source-level regression assertions.
    private function fileContents(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path);
    }

    // Verifies scheduled_at is persisted and exposed in ride API resources.
    public function test_scheduled_at_is_persisted_and_exposed(): void
    {
        $repository = $this->fileContents('Modules/TripManagement/Repositories/TripRequestRepository.php');
        $resource = $this->fileContents('Modules/TripManagement/Transformers/TripRequestResource.php');

        $this->assertStringContainsString('$trip->scheduled_at = $attributes[\'scheduled_at\'] ?? null;', $repository);
        $this->assertStringContainsString('\'scheduled_at\' => $this->scheduled_at,', $resource);
    }

    // Verifies scheduled rides use the normal immediate nearest-driver search path.
    public function test_scheduled_rides_are_not_deferred_in_trip_request_service(): void
    {
        $service = $this->fileContents('Modules/TripManagement/Service/TripRequestService.php');

        $this->assertStringNotContainsString('ProcessScheduledTripJob::dispatch', $service);
        $this->assertStringNotContainsString('SendCustomerScheduledTripReminderJob::dispatch', $service);
        $this->assertStringContainsString('\'scheduled_at\' => $save_trip->scheduled_at?->toDateTimeString(),', $service);
    }

    // Verifies the live customer create endpoint accepts scheduled rides.
    public function test_live_customer_create_validates_and_broadcasts_scheduled_at(): void
    {
        $controller = $this->fileContents('Modules/TripManagement/Http/Controllers/Api/Customer/TripRequestController.php');
        $event = $this->fileContents('app/Events/CustomerTripRequestEvent.php');

        $this->assertStringContainsString('\'scheduled_at\' => \'sometimes|nullable|date|after:now\',', $controller);
        $this->assertStringContainsString('\'scheduled_at\' => $final->scheduled_at?->toDateTimeString(),', $controller);
        $this->assertStringContainsString('\'scheduled_at\'=>$this->tripRequest->scheduled_at?->toDateTimeString(),', $event);
    }

    // Verifies driver acceptance queues the thirty-minute scheduled ride reminder.
    public function test_driver_acceptance_queues_scheduled_ride_reminder(): void
    {
        $controller = $this->fileContents('Modules/TripManagement/Http/Controllers/Api/Driver/TripRequestController.php');

        $this->assertStringContainsString('use App\Jobs\SendScheduledTripReminderJob;', $controller);
        $this->assertStringContainsString('Carbon::parse($trip->scheduled_at)->subMinutes(30)', $controller);
        $this->assertStringContainsString('SendScheduledTripReminderJob::dispatch($trip->id)->delay($reminderTime);', $controller);
    }

    // Verifies the reminder notification seed is registered with the default seeder.
    public function test_scheduled_reminder_notification_seeder_is_registered(): void
    {
        $seeder = $this->fileContents('database/seeders/DatabaseSeeder.php');

        $this->assertStringContainsString('$this->call(ScheduledTripReminderNotificationSeeder::class);', $seeder);
    }
}
