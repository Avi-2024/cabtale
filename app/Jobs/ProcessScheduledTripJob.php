<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\TripManagement\Interfaces\TripRequestInterfaces;
use Modules\TripManagement\Interfaces\TempTripNotificationInterface;
use Modules\TripManagement\Lib\CommonTrait;
use Exception;

class ProcessScheduledTripJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, CommonTrait;

    public int $timeout = 120;

    public function __construct(public string|int $tripRequestId) {}

    public function handle(
        TripRequestInterfaces $trip,
        TempTripNotificationInterface $temp_notification,
    ): void {

        $id = is_numeric($this->tripRequestId) ? (int) $this->tripRequestId : (string) $this->tripRequestId;

        $final = $trip->getBy(column: 'id', value: $id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
        if (!$final) return;

        if (in_array(($final->current_status ?? $final->status) ?? '', ['cancelled', 'completed'], true)) return;
        if (!empty($final->driver_id)) return;

        if ($final->scheduled_at && Carbon::parse($final->scheduled_at)->isFuture()) {
            $this->release(Carbon::parse($final->scheduled_at)->diffInSeconds());
            return;
        }

        $lock = Cache::lock('process-scheduled-trip-'.$final->id, 30);
        if (!$lock->get()) return;

        try {
            $search_radius = (double) get_cache('search_radius') ?? 5;

            $find_drivers = $this->findNearestDriver(
                latitude: $final->coordinate->pickup_coordinates->latitude,
                longitude: $final->coordinate->pickup_coordinates->longitude,
                zone_id: $final->zone_id,
                radius: $search_radius,
                vehicleCategoryId: $final->vehicle_category_id,
                requestType: $final->type,
                parcelWeight: $final->weight ?? null
            );

            // Notify candidate drivers
            if (!empty($find_drivers)) {
                $notify = [];
                foreach ($find_drivers as $key => $value) {
                    if ($value->user?->fcm_token) {
                        $notify[$key]['user_id'] = $value->user->id;
                        $notify[$key]['trip_request_id'] = $final->id;
                    }
                }

                $push = getNotification('new_' . $final->type);
                $notification = [
                    'title' => translate($push['title']),
                    'description' => translate($push['description']),
                    'ride_request_id' => $final->id,
                    'type' => $final->type,
                    'action' => 'new_ride_request_notification',
                    'scheduled_at' => $final->scheduled_at?->toDateTimeString(),
                ];

                if (!empty($notify)) {
                    \App\Jobs\SendPushNotificationJob::dispatch($notification, $find_drivers)->onQueue('high');
                    $temp_notification->store(['data' => $notify]);
                }

                foreach ($find_drivers as $value) {
                    try {
                        checkPusherConnection(\App\Events\CustomerTripRequestEvent::broadcast($value->user, $final));
                    } catch (Exception $e) {
                        // swallow
                    }
                }
            }

            if (!is_null(businessConfig('server_key', NOTIFICATION_SETTINGS))) {
                sendTopicNotification(
                    'admin_notification',
                    translate('new_request_notification'),
                    translate('new_request_has_been_placed'),
                    'null',
                    $final->id,
                    $final->type
                );
            }
        } finally {
            optional($lock)->release();
        }
    }
}
