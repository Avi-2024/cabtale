<?php

namespace Modules\TripManagement\Http\Controllers\Api\Customer;

use App\Broadcasting\CustomerTripCanceledAfterOngoingChannel;
use App\Events\CustomerCouponAppliedEvent;
use App\Events\CustomerCouponRemovedEvent;
use App\Events\CustomerTripCancelledAfterOngoingEvent;
use App\Events\CustomerTripCancelledEvent;
use App\Events\CustomerTripRequestEvent;
use App\Jobs\SendPushNotificationJob;
use Carbon\Carbon;
use Exception;
use Illuminate\Validation\Rule;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\FareManagement\Interfaces\ParcelFareInterface;
use Modules\FareManagement\Interfaces\TripFareInterface;
use Modules\Gateways\Traits\Payment;
use Modules\ParcelManagement\Repositories\ParcelWeightRepository;
use Modules\PromotionManagement\Interfaces\CoupounInterface;
use Modules\TripManagement\Entities\FareBidding;
use Modules\TripManagement\Entities\TripRequest;
use Modules\TripManagement\Entities\TripRequestCoordinate;
use Modules\TripManagement\Entities\TripRequestTime;
use Modules\TripManagement\Interfaces\FareBiddingInterface;
use Modules\TripManagement\Interfaces\FareBiddingLogInterface;
use Modules\TripManagement\Interfaces\RecentAddressInterface;
use Modules\TripManagement\Interfaces\RejectedDriverRequestInterface;
use Modules\TripManagement\Interfaces\TempTripNotificationInterface;
use Modules\TripManagement\Interfaces\TripRequestInterfaces;
use Modules\TripManagement\Lib\CommonTrait;
use Modules\TripManagement\Lib\CouponCalculationTrait;
use Modules\TripManagement\Lib\DiscountCalculationTrait;
use Modules\TripManagement\Transformers\FareBiddingResource;
use Modules\TripManagement\Transformers\TripRequestResource;
use Modules\UserManagement\Interfaces\DriverDetailsInterface;
use Modules\UserManagement\Interfaces\DriverInterface;
use Modules\UserManagement\Interfaces\UserLastLocationInterface;
use Modules\UserManagement\Lib\LevelUpdateCheckerTrait;
use Modules\UserManagement\Transformers\LastLocationResource;
use Modules\ZoneManagement\Interfaces\ZoneInterface;
use Modules\TransactionManagement\Traits\TransactionTrait;

class TripRequestController extends Controller
{
    use CommonTrait, TransactionTrait, Payment, CouponCalculationTrait, DiscountCalculationTrait, LevelUpdateCheckerTrait;

    public function __construct(
        private TripRequestInterfaces          $trip,
        private TripFareInterface              $tripFare,
        private ZoneInterface                  $zone,
        private RecentAddressInterface         $address,
        private FareBiddingInterface           $bidding,
        private UserLastLocationInterface      $lastLocation,
        private DriverInterface                $driver,
        private DriverDetailsInterface         $driver_details,
        private RejectedDriverRequestInterface $rejected_request,
        private FareBiddingLogInterface        $bidding_log,
        private TempTripNotificationInterface  $temp_notification,
        private ParcelFareInterface            $parcel_fare,
        private ParcelWeightRepository         $parcel_weight,
        private CoupounInterface               $coupon
    )
    {
    }

    /**
     * Summary of rideResumeStatus
     * @return JsonResponse
     */
    public function rideResumeStatus(): JsonResponse
    {
        $trip = $this->getResumeRide();
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        $trip = TripRequestResource::make($trip);

        return response()->json(responseFormatter(constant: DEFAULT_200, content: $trip));
    }

    /**
     * Show estimate fare calculation trip and parcel.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEstimatedFare(Request $request): JsonResponse
    {

        $trip = $this->getResumeRide();
        if ($request->type == "ride_request" && $trip) {
            return response()->json(responseFormatter(INCOMPLETE_RIDE_403), 403);
        }
        $validator = Validator::make($request->all(), [
            'pickup_coordinates' => 'required',
            'destination_coordinates' => 'required',
            'pickup_address' => 'required',
            'destination_address' => 'required',
            'type' => 'required|in:parcel,ride_request',
            'parcel_weight' => 'required_if:type,parcel',
        ]);
        if ($validator->fails()) {
            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $zone_id = $request->header('zoneId');
        $areas = $this->zone->getBy(column: 'id', value: $zone_id);
        if (!$areas) {

            return response()->json(responseFormatter(ZONE_404), 403);
        }

        $user = auth('api')->user();

        $pickup_coordinates = json_decode($request->pickup_coordinates, true);
        $destination_coordinates = json_decode($request->destination_coordinates, true);


        $intermediate_coordinates = [];
        if (!is_null($request['intermediate_coordinates'])) {
            $intermediate_coordinates = json_decode($request->intermediate_coordinates, true);
            $maximum_intermediate_point = 2;
            if (count($intermediate_coordinates) > $maximum_intermediate_point) {

                return response()->json(responseFormatter(MAXIMUM_INTERMEDIATE_POINTS_403), 403);
            }
        }

        $pickup_coordinates_points = new Point($pickup_coordinates[0], $pickup_coordinates[1]);
        $pickup_location_coverage = $this->zone->getByPoints($pickup_coordinates_points)->whereId($zone_id)->first();
        $destination_coordinates_points = new Point($destination_coordinates[0], $destination_coordinates[1]);
        $destination_location_coverage = $this->zone->getByPoints($destination_coordinates_points)->whereId($zone_id)->first();

        if (!$pickup_location_coverage || !$destination_location_coverage) {
            return response()->json(responseFormatter(ZONE_RESOURCE_404), 403);
        }

        if ($request->type == 'ride_request') {
            $trip_fare = $this->tripFare->get(limit: 1000, offset: 1, attributes: [
                'query' => 'zone_id',
                'value' => $zone_id
            ], relations: ['zone', 'vehicleCategory']);

            //Get to know in zone's vehicle category car and motorcycle available or not
            $available_categories = $trip_fare->map(function ($query) {
                return $query->vehicleCategory->type;
            })->unique()
                ->toArray();

            if (empty($available_categories)) {

                return response()->json(responseFormatter(NO_ACTIVE_CATEGORY_IN_ZONE_404), 403);
            }
        }

        if ($request->type == 'parcel') {
            $parcel_weights = $this->parcel_weight->get(limit: 99999, offset: 1);
            $parcel_weight_id = null;

            $parcel_category_id = $request->parcel_category_id;

            foreach ($parcel_weights as $pw) {
                if ($request->parcel_weight >= $pw->min_weight && $request->parcel_weight <= $pw->max_weight) {
                    $parcel_weight_id = $pw['id'];
                }
            }
            if (is_null($parcel_weight_id)) {

                return response()->json(responseFormatter(PARCEL_WEIGHT_400), 403);
            }

            $trip_fare = $this->parcel_fare->getZoneFare([
                'column' => 'zone_id',
                'value' => $zone_id,
                'parcel_weight_id' => $parcel_weight_id,
                'parcel_category_id' => $parcel_category_id,
            ]);

        }

        $get_routes = getRoutes(
            originCoordinates: $pickup_coordinates,
            destinationCoordinates: $destination_coordinates,
            intermediateCoordinates: $intermediate_coordinates,
            drivingMode: $request->type == 'ride_request' ? (count($available_categories) == 2 ? ["DRIVE", 'TWO_WHEELER'] : ($available_categories[0] == 'car' ? ['DRIVE'] : ['TWO_WHEELER'])) : ['TWO_WHEELER'],
        );
        if ($get_routes[1]['status'] !== "OK") {
            return response()->json(responseFormatter(ROUTE_NOT_FOUND_404, $get_routes[1]['error_detail']), 403);
        }

        $distanceInKm = $get_routes[0]['distance'];

        // 🚨 Enforce minimum distance = 50 km
        if ($distanceInKm < 50) {
            return response()->json(responseFormatter([
                'response_code' => 'MINIMUM_DISTANCE_403',
                'message'       => 'Minimum destination distance should be 50 km',
            ]), 403);
        }

        $estimated_fare = $this->estimatedFare(
            tripRequest: $request->all(),
            routes: $get_routes,
            zone_id: $zone_id,
            tripFare: $trip_fare,
            beforeCreate: true
        );


        //Recent address store
//        $this->address->store(attributes: [
//            'user_id' => $user->id,
//            'zone_id' => $zone_id,
//            'pickup_coordinates' => new Point($pickup_coordinates[0], $pickup_coordinates[1],Srid::WGS84->value),
//            'destination_coordinates' => new Point($destination_coordinates[0], $destination_coordinates[1],Srid::WGS84->value),
//            'pickup_address' => $request->pickup_address,
//            'destination_address' => $request->destination_address,
//        ]);

        return response()->json(responseFormatter(DEFAULT_200, $estimated_fare), 200);
    }


    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return JsonResponse
     */
   // public function createRideRequest(Request $request): JsonResponse
    // {
    //     // \Log::info($request->getContent());
    //     $trip = $this->getResumeRide();
    //     if ($request->type == "ride_request" && $trip && $request->trip_request_id == null) {
    //         return response()->json(responseFormatter(INCOMPLETE_RIDE_403), 403);
    //     }
    //     $validator = Validator::make($request->all(), [
    //         'pickup_coordinates' => 'required_if:trip_request_id,==,null',
    //         'destination_coordinates' => 'required_if:trip_request_id,==,null',
    //         'customer_coordinates' => 'required_if:trip_request_id,==,null',
    //         'estimated_time' => 'required_if:trip_request_id,==,null',
    //         'estimated_distance' => 'required_if:trip_request_id,==,null',
    //         'estimated_fare' => 'required_if:trip_request_id,==,null',
    //         'actual_fare' => 'sometimes',
    //         //'scheduled_at' => 'nullable|date|after:now',
    //         'return_fee' => [
    //             Rule::requiredIf(function () use ($request) {
    //                 return ($request->type == 'parcel' && $request->trip_request_id == null);
    //             })
    //         ],
    //         'cancellation_fee' => [
    //             Rule::requiredIf(function () use ($request) {
    //                 return ($request->type == 'parcel' && $request->trip_request_id == null);
    //             })
    //         ],
    //         'vehicle_category_id' => [
    //             Rule::requiredIf(function () use ($request) {
    //                 return ($request->type == 'ride_request' && $request->trip_request_id == null);
    //             })
    //         ],
    //         'note' => 'sometimes',
    //         'pickup_address' => 'required_if:trip_request_id,==,null',
    //         'destination_address' => 'required_if:trip_request_id,==,null',
    //         'customer_request_coordinates' => 'required_if:trip_request_id,==,null',
    //         'type' => 'required|in:parcel,ride_request',
    //         'sender_name' => 'required_if:type,==,parcel',
    //         'sender_phone' => 'required_if:type,==,parcel',
    //         'sender_address' => 'required_if:type,==,parcel',
    //         'receiver_name' => 'required_if:type,==,parcel',
    //         'receiver_phone' => 'required_if:type,==,parcel',
    //         'receiver_address' => 'required_if:type,==,parcel',
    //         'parcel_category_id' => 'required_if:type,==,parcel',
    //         'weight' => 'required_if:type,==,parcel',
    //         'payer' => 'required_if:type,==,parcel'
    //     ]);

    //     if ($validator->fails()) {

    //         return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
    //     }

    //     if (empty($request->header('zoneId'))) {

    //         return response()->json(responseFormatter(ZONE_404), 403);
    //     }

    //     if ($request->trip_request_id) {
    //         $save_trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']);
    //         $save_trip->estimated_fare = $request->bid ? $request->actual_fare : $request->estimated_fare;
    //         $save_trip->actual_fare = $request->bid ? $request->actual_fare : $request->estimated_fare;
    //         $save_trip->rise_request_count = $request->bid ? 1 : 0;
    //         //$save_trip->scheduled_at = \Carbon\Carbon::parse($request->input('scheduled_at'), 'Asia/Kolkata')->setTimezone('Asia/Kolkata');  
    //         if ($save_trip->discount_id != null) {
    //             $save_trip->discount_id = null;
    //             $save_trip->discount_amount = null;
    //         }
    //         $save_trip->save();
    //     } else {
    //         $pickup_coordinates = json_decode($request['pickup_coordinates'], true);
    //         $destination_coordinates = json_decode($request['destination_coordinates'], true);
    //         $customer_coordinates = json_decode($request['customer_coordinates'], true);
    //         $pickup_point = new Point($pickup_coordinates[0], $pickup_coordinates[1]);
    //         $destination_point = new Point($destination_coordinates[0], $destination_coordinates[1]);
    //         $customer_point = new Point($customer_coordinates[0], $customer_coordinates[1]);
    //         $request->merge([
    //             'customer_id' => auth('api')->id(),
    //             'zone_id' => $request->header('zoneId'),
    //             'pickup_coordinates' => $pickup_point,
    //             'destination_coordinates' => $destination_point,
    //             'estimated_fare' => $request->bid ? $request->actual_fare : $request->estimated_fare,
    //             'actual_fare' => $request->bid ? $request->actual_fare : $request->estimated_fare,
    //             'return_fee' => $request->type == PARCEL ? $request->return_fee : 0,
    //             'cancellation_fee' => $request->type == PARCEL ? $request->cancellation_fee : 0,
    //             'customer_request_coordinates' => $customer_point,
    //             'rise_request_count' => $request->bid ? 1 : 0,
    //             //'scheduled_at' => \Carbon\Carbon::parse($request->input('scheduled_at'), 'Asia/Kolkata')->setTimezone('Asia/Kolkata'),

    //         ]);
    //         //$save_trip = $this->trip->store(attributes: $request->all());
    //         //$save_trip->scheduled_at = \Carbon\Carbon::parse($request->input('scheduled_at'), 'Asia/Kolkata')->setTimezone('Asia/Kolkata');
    //         $save_trip->save();
            
    //     }

    //     if ($request->bid) {
    //         $final = $this->trip->getBy(column: 'id', value: $save_trip->id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
    //     } else {
    //         $tripDiscount = $this->trip->getBy(column: 'id', value: $save_trip->id);
    //         $vat_percent = (double)get_cache('vat_percent') ?? 1;
    //         $estimatedAmount = $tripDiscount->actual_fare / (1 + ($vat_percent / 100));
    //         $discount = $this->getEstimatedDiscount(user: $tripDiscount->customer, zoneId: $tripDiscount->zone_id, tripType: $tripDiscount->type, vehicleCategoryId: $tripDiscount->vehicle_category_id, estimatedAmount: $estimatedAmount);
    //         if ($discount['discount_amount'] != 0) {
    //             $save_trip->discount_amount = $discount['discount_amount'];
    //             $save_trip->discount_id = $discount['discount_id'];
    //             $save_trip->save();
    //         }
    //         $final = $this->trip->getBy(column: 'id', value: $tripDiscount->id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
    //     }

    //     //----------------------------------------------------------
    //     // $isScheduled = !empty($final->scheduled_at) && Carbon::parse($final->scheduled_at)->gt(Carbon::now());

    //     // if ($isScheduled) {
    //     //     ProcessScheduledTripJob::dispatch($final->id)
    //     //         ->onQueue('high')
    //     //         ->delay(Carbon::parse($final->scheduled_at));

    //     //     $customer = auth('api')->user();
    //     //     if ($customer?->fcm_token) {
    //     //         $scheduledLocal = Carbon::parse($final->scheduled_at)
    //     //             ->timezone($customer->timezone ?? config('app.timezone'))
    //     //             ->format('M d, Y h:i A');

    //     //         $confirm = [
    //     //             'title' => translate('Ride Scheduled'),
    //     //             'description' => translate('Your ride is scheduled for ') . $scheduledLocal,
    //     //             'ride_request_id' => $final->id,
    //     //             'type' => $final->type,
    //     //             'action' => 'scheduled_ride_confirmation',
    //     //         ];
    //     //         \App\Jobs\SendPushNotificationJob::dispatch($confirm, [$customer])->onQueue('high');
    //     //     }

    //     //     $trip = new \Modules\TripManagement\Transformers\TripRequestResource($final);

    //     //     return response()->json([
    //     //         'success' => true,
    //     //         'message' => 'Your ride is scheduled. We will look for a driver at the scheduled time.',
    //     //         'data'    => $trip,
    //     //     ], 200);
    //     // }
    //     //----------------------------------------------------------

    //     $search_radius = (double)get_cache('search_radius') ?? 5;
    //     // Find drivers list based on pickup locations
    //     $find_drivers = $this->findNearestDriver(
    //         latitude: $pickup_coordinates[0] ?? $final->coordinate->pickup_coordinates->latitude,
    //         longitude: $pickup_coordinates[1] ?? $final->coordinate->pickup_coordinates->longitude,
    //         zone_id: $request->header('zoneId'),
    //         radius: $search_radius,
    //         vehicleCategoryId: $request->vehicle_category_id ?? $final->vehicle_category_id,
    //         requestType: $request->type ?? $final->type,
    //         parcelWeight: $request->weight ?? null
    //     );
    //     //Send notifications to drivers
    //     if (!empty($find_drivers)) {
    //         $notify = [];
    //         foreach ($find_drivers as $key => $value) {
    //             if ($value->user?->fcm_token) {
    //                 $notify[$key]['user_id'] = $value->user->id;
    //                 $notify[$key]['trip_request_id'] = $final->id;
    //             }

    //         }
    //         $push = getNotification('new_' . $final->type);
    //         $notification = [
    //             'title' => translate($push['title']),
    //             'description' => translate($push['description']),
    //             'ride_request_id' => $final->id,
    //             'type' => $final->type,
    //             'action' => 'new_ride_request_notification'
    //         ];
    //         if (!empty($notify)) {
                
    //             // dispatch(new SendPushNotificationJob($notification, $find_drivers))->onQueue('high');
    //             \App\Jobs\SendPushNotificationJob::dispatchSync($notification, $find_drivers);
    //             $this->temp_notification->store(['data' => $notify]);
    //         }
    //         foreach ($find_drivers as $key => $value) {
    //             try {
    //                 checkPusherConnection(CustomerTripRequestEvent::broadcast($value->user, $final));
    //             } catch (Exception $exception) {

    //             }
    //         }
    //     }
    //     //Send notifications to admins
    //     if (!is_null(businessConfig('server_key', NOTIFICATION_SETTINGS))) {
    //         sendTopicNotification(
    //             'admin_notification',
    //             translate('new_request_notification'),
    //             translate('new_request_has_been_placed'),
    //             'null',
    //             $final->id,
    //             $request->type);
    //     }
    //     //Trip API resource
    //     $trip = new TripRequestResource($final);

    //     return response()->json(responseFormatter(TRIP_REQUEST_STORE_200, $trip));
    // }
    public function createRideRequest(Request $request): JsonResponse
    {
        $trip = $this->getResumeRide();
        if ($request->type == "ride_request" && $trip && $request->trip_request_id == null) {
            return response()->json(responseFormatter(INCOMPLETE_RIDE_403), 403);
        }
        $validator = Validator::make($request->all(), [
            'pickup_coordinates' => 'required_if:trip_request_id,==,null',
            'destination_coordinates' => 'required_if:trip_request_id,==,null',
            'customer_coordinates' => 'required_if:trip_request_id,==,null',
            'estimated_time' => 'required_if:trip_request_id,==,null',
            'estimated_distance' => 'required_if:trip_request_id,==,null',
            'estimated_fare' => 'required_if:trip_request_id,==,null',
            'actual_fare' => 'sometimes',
            'return_fee' => [
                Rule::requiredIf(function () use ($request) {
                    return ($request->type == 'parcel' && $request->trip_request_id == null);
                })
            ],
            'cancellation_fee' => [
                Rule::requiredIf(function () use ($request) {
                    return ($request->type == 'parcel' && $request->trip_request_id == null);
                })
            ],
            'vehicle_category_id' => [
                Rule::requiredIf(function () use ($request) {
                    return ($request->type == 'ride_request' && $request->trip_request_id == null);
                })
            ],
            'note' => 'sometimes',
            'pickup_address' => 'required_if:trip_request_id,==,null',
            'destination_address' => 'required_if:trip_request_id,==,null',
            'customer_request_coordinates' => 'required_if:trip_request_id,==,null',
            'type' => 'required|in:parcel,ride_request',
            'sender_name' => 'required_if:type,==,parcel',
            'sender_phone' => 'required_if:type,==,parcel',
            'sender_address' => 'required_if:type,==,parcel',
            'receiver_name' => 'required_if:type,==,parcel',
            'receiver_phone' => 'required_if:type,==,parcel',
            'receiver_address' => 'required_if:type,==,parcel',
            'parcel_category_id' => 'required_if:type,==,parcel',
            'weight' => 'required_if:type,==,parcel',
            'payer' => 'required_if:type,==,parcel',
            'scheduled_at' => 'sometimes|nullable|date|after:now',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        if (empty($request->header('zoneId'))) {

            return response()->json(responseFormatter(ZONE_404), 403);
        }

        if ($request->trip_request_id) {
            $save_trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']);
            $save_trip->estimated_fare = $request->bid ? $request->actual_fare : $request->estimated_fare;
            $save_trip->actual_fare = $request->bid ? $request->actual_fare : $request->estimated_fare;
            $save_trip->rise_request_count = $request->bid ? 1 : 0;
            if ($save_trip->discount_id != null) {
                $save_trip->discount_id = null;
                $save_trip->discount_amount = null;
            }
            $save_trip->save();
        } else {
            $pickup_coordinates = json_decode($request['pickup_coordinates'], true);
            $destination_coordinates = json_decode($request['destination_coordinates'], true);
            $customer_coordinates = json_decode($request['customer_coordinates'], true);
            $pickup_point = new Point($pickup_coordinates[0], $pickup_coordinates[1]);
            $destination_point = new Point($destination_coordinates[0], $destination_coordinates[1]);
            $customer_point = new Point($customer_coordinates[0], $customer_coordinates[1]);
            $request->merge([
                'customer_id' => auth('api')->id(),
                'zone_id' => $request->header('zoneId'),
                'pickup_coordinates' => $pickup_point,
                'destination_coordinates' => $destination_point,
                'estimated_fare' => $request->bid ? $request->actual_fare : $request->estimated_fare,
                'actual_fare' => $request->bid ? $request->actual_fare : $request->estimated_fare,
                'return_fee' => $request->type == PARCEL ? $request->return_fee : 0,
                'cancellation_fee' => $request->type == PARCEL ? $request->cancellation_fee : 0,
                'customer_request_coordinates' => $customer_point,
                'rise_request_count' => $request->bid ? 1 : 0
            ]);
            $save_trip = $this->trip->store(attributes: $request->all());
        }

        if ($request->bid) {
            $final = $this->trip->getBy(column: 'id', value: $save_trip->id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
        } else {
            $tripDiscount = $this->trip->getBy(column: 'id', value: $save_trip->id);
            $vat_percent = (double)get_cache('vat_percent') ?? 1;
            $estimatedAmount = $tripDiscount->actual_fare / (1 + ($vat_percent / 100));
            $discount = $this->getEstimatedDiscount(user: $tripDiscount->customer, zoneId: $tripDiscount->zone_id, tripType: $tripDiscount->type, vehicleCategoryId: $tripDiscount->vehicle_category_id, estimatedAmount: $estimatedAmount);
            if ($discount['discount_amount'] != 0) {
                $save_trip->discount_amount = $discount['discount_amount'];
                $save_trip->discount_id = $discount['discount_id'];
                $save_trip->save();
            }
            $final = $this->trip->getBy(column: 'id', value: $tripDiscount->id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
        }

        $search_radius = (double)(get_cache('search_radius') ?? 5);
        if ($search_radius <= 0) {
            $search_radius = 5;
        }
        // Find drivers list based on pickup locations
        $find_drivers = $this->findNearestDriver(
            latitude: $pickup_coordinates[0] ?? $final->coordinate->pickup_coordinates->latitude,
            longitude: $pickup_coordinates[1] ?? $final->coordinate->pickup_coordinates->longitude,
            zone_id: $request->header('zoneId'),
            radius: $search_radius,
            vehicleCategoryId: $request->vehicle_category_id ?? $final->vehicle_category_id,
            requestType: $request->type ?? $final->type,
            parcelWeight: $request->weight ?? null
        );
        //Send notifications to drivers
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

                dispatch(new SendPushNotificationJob($notification, $find_drivers))->onQueue('high');
                $this->temp_notification->store(['data' => $notify]);
            }
            foreach ($find_drivers as $key => $value) {
                try {
                    checkPusherConnection(CustomerTripRequestEvent::broadcast($value->user, $final));
                } catch (Exception $exception) {

                }
            }
        }
        //Send notifications to admins
        if (!is_null(businessConfig('server_key', NOTIFICATION_SETTINGS))) {
            sendTopicNotification(
                'admin_notification',
                translate('new_request_notification'),
                translate('new_request_has_been_placed'),
                'null',
                $final->id,
                $request->type);
        }
        //Trip API resource
        $trip = new TripRequestResource($final);

        return response()->json(responseFormatter(TRIP_REQUEST_STORE_200, $trip));
    }

    /**
     * @param $trip_request_id
     * @param Request $request
     * @return JsonResponse
     */
    public function biddingList($trip_request_id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required|numeric',
            'offset' => 'required|numeric',
        ]);
        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        $bidding = $this->bidding->get(limit: $request['limit'], offset: $request['offset'], dynamic_page: true, attributes: [
            'trip_request_id' => $trip_request_id,
            'relations' => ['driver_last_location', 'driver', 'trip_request', 'driver.vehicle.model'],
            'withAvgRelation' => 'driverReceivedReviews',
            'withAvgColumn' => 'rating',
        ]);
        $bidding = FareBiddingResource::collection($bidding);

        return response()->json(responseFormatter(constant: DEFAULT_200, content: $bidding, limit: $request['limit'], offset: $request['offset']));
    }


    public function ignoreBidding(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bidding_id' => 'required',
        ]);
        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $bidding = $this->bidding->getBy(column: 'id', value: $request['bidding_id']);
        if (!$bidding) {

            return response()->json(responseFormatter(constant: DRIVER_BID_NOT_FOUND_403), 403);
        }

        $this->bidding->update(attributes: [
            'column' => 'id',
            'is_ignored' => 1
        ], id: $request->bidding_id);
        if ($bidding->driver_id) {
            if (!is_null($bidding->driver->fcm_token)) {
                $push = getNotification('customer_bid_rejected');
                sendDeviceNotification(fcm_token: $bidding->driver->fcm_token,
                    title: translate($push['title']),
                    description: translate(textVariableDataFormat(value: $push['description'], tripId: $bidding->trip_request->ref_id)),
                    ride_request_id: $bidding->trip_request_id,
                    type: $bidding->trip_request_id,
                    action: 'bid_rejected',
                    user_id: $bidding->driver->id
                );
            }
        }

        return response()->json(responseFormatter(constant: DEFAULT_200));
    }


    /**
     *
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function requestAction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required',
            'driver_id' => 'required',
            'action' => 'required|in:accepted,rejected',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id'], attributes: ['relations' => 'coordinate']);
        $driver = $this->driver->getBy(column: 'id', value: $request['driver_id'], attributes: ['relations' => ['vehicle', 'driverDetails', 'lastLocations']]);

        if (Cache::get($request['trip_request_id']) == ACCEPTED && $trip->driver_id == $driver->id) {

            return response()->json(responseFormatter(DEFAULT_UPDATE_200));
        }

        $user_status = $driver->driverDetails->availability_status;
        if ($user_status != 'on_bidding' && $user_status != 'available') {

            return response()->json(responseFormatter(constant: DRIVER_403), 403);
        }
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        if (!$driver->vehicle) {

            return response()->json(responseFormatter(constant: DEFAULT_404), 403);
        }
        if (get_cache('bid_on_fare') ?? 0) {
            $checkBid = $this->bidding->getBy(column: 'trip_request_id', value: $request['trip_request_id'], attributes: [
                'additionalColumn' => 'driver_id',
                'additionalValue' => $request['driver_id']
            ]);

            if (!$checkBid) {
                return response()->json(responseFormatter(constant: DRIVER_BID_NOT_FOUND_403), 403);
            }

        }

        // Force live mode - temporary fix
        $otp = rand(1000, 9999);
        $attributes = [
            'column' => 'id',
            'driver_id' => $driver->id,
            'otp' => $otp,
            'vehicle_id' => $driver->vehicle->id,
            'current_status' => ACCEPTED,
            'trip_status' => ACCEPTED,
            'vehicle_category_id' => $driver->vehicle->category_id,
        ];

        if ($request['action'] == ACCEPTED) {
            DB::beginTransaction();
            Cache::put($trip->id, ACCEPTED, now()->addHour());

            //set driver availability_status as on_trip
            $this->driver_details->update(attributes: ['column' => 'user_id', 'availability_status' => 'on_trip'], id: $driver->id);

            //deleting exiting rejected driver request for this trip
            $this->rejected_request->destroyData([
                'column' => 'trip_request_id',
                'value' => $trip->id,
            ]);
            if (get_cache('bid_on_fare') ?? 0) {
                $all_bidding = $this->bidding->get(limit: 200, offset: 1, attributes: [
                    'trip_request_id' => $request['trip_request_id'],
                ]);

                if (count($all_bidding) > 0) {
                    $actual_fare = $all_bidding
                        ->where('driver_id', $request['driver_id'])
                        ->firstWhere('trip_request_id', $request['trip_request_id'])
                        ->bid_fare;
                    $attributes['actual_fare'] = $actual_fare;
                    $attributes['estimated_fare'] = $actual_fare;
                }
            }


            $data = $this->temp_notification->get([
                'relations' => 'user',
                'trip_request_id' => $request['trip_request_id'],
                'whereNotInColumn' => 'user_id',
                'whereNotInValue' => [$driver->id]
            ]);

            $push = getNotification('driver_assigned');
            if (!empty($data)) {
                $notification['title'] = translate($push['title']);
                $notification['description'] = translate($push['description']);
                $notification['ride_request_id'] = $trip->id;
                $notification['type'] = $trip->type;
                $notification['action'] = 'ride_started';

                dispatch(new SendPushNotificationJob($notification, $data))->onQueue('high');
                $this->temp_notification->delete($trip->id);
            }
            $driver_arrival_time = getRoutes(
                originCoordinates: [
                    $trip->coordinate->pickup_coordinates->latitude,
                    $trip->coordinate->pickup_coordinates->longitude
                ],
                destinationCoordinates: [
                    $driver->lastLocations->latitude,
                    $driver->lastLocations->longitude
                ],
            );
            if ($driver_arrival_time[1]['status'] !== "OK") {
                return response()->json(responseFormatter(ROUTE_NOT_FOUND_404, $driver_arrival_time[1]['error_detail']), 403);
            }
            if ($trip->type == 'ride_request') {
                $attributes['driver_arrival_time'] = (double)($driver_arrival_time[0]['duration']) / 60;
            }

            //Trip update
            $this->trip->update(attributes: $attributes, id: $request['trip_request_id']);
            $updateTripDiscount = $this->trip->getBy(column: 'id', value: $request['trip_request_id']);
            $updateTripDiscount->discount_id = null;
            $updateTripDiscount->discount_amount = null;
            $updateTripDiscount->save();
            DB::commit();

            $push = getNotification('bid_accepted');
            sendDeviceNotification(fcm_token: $driver->fcm_token,
                title: translate($push['title']),
                description: translate(textVariableDataFormat(value: $push['description'])),
                ride_request_id: $trip->id,
                type: $trip->type,
                action: 'ride_' . $request->action,
                user_id: $driver->id);
        } else {
            if (get_cache('bid_on_fare') ?? 0) {
                $all_bidding = $this->bidding->get(limit: 200, offset: 1, attributes: [
                    'trip_request_id' => $request['trip_request_id'],
                ]);

                if (count($all_bidding) > 0) {
                    $this->bidding->destroyData([
                        'column' => 'id',
                        'ids' => $all_bidding->pluck('id')
                    ]);
                }

            }
        }

        return response()->json(responseFormatter(constant: BIDDING_ACTION_200));
    }

    public function rideStatusUpdate($trip_request_id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $trip = $this->trip->getBy(column: 'id', value: $trip_request_id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        if ($trip->current_status == CANCELLED) {
            return response()->json(responseFormatter(TRIP_STATUS_CANCELLED_403), 403);
        }
        if ($trip->current_status == COMPLETED) {
            return response()->json(responseFormatter(TRIP_STATUS_COMPLETED_403), 403);
        }
        if ($trip->current_status == RETURNING) {
            return response()->json(responseFormatter(TRIP_STATUS_RETURNING_403), 403);
        }
        $attributes = [
            'column' => 'id',
            'value' => $trip_request_id,
            'trip_status' => $request['status'],
            'trip_cancellation_reason' => $request['cancel_reason'] ?? null
        ];


        if ($request->status == 'cancelled' && ($trip->current_status == ACCEPTED || $trip->current_status == PENDING)) {
            //referral
            if ($trip->customer->referralCustomerDetails && $trip->customer->referralCustomerDetails->is_used == 0) {
                $trip->customer->referralCustomerDetails()->update([
                    'is_used' => 1
                ]);
                if ($trip->customer?->referralCustomerDetails?->ref_by_earning_amount && $trip->customer?->referralCustomerDetails?->ref_by_earning_amount > 0) {
                    $shareReferralUser = $trip->customer?->referralCustomerDetails?->shareRefferalCustomer;
                    $this->customerReferralEarningTransaction($shareReferralUser, $trip->customer?->referralCustomerDetails?->ref_by_earning_amount);

                    $push = getNotification('referral_reward_received');
                    sendDeviceNotification(fcm_token: $shareReferralUser?->fcm_token,
                        title: translate($push['title']),
                        description: translate(textVariableDataFormat(value: $push['description'], referralRewardAmount: getCurrencyFormat($trip->customer?->referralCustomerDetails?->ref_by_earning_amount))),
                        ride_request_id: $shareReferralUser?->id,
                        action: 'referral_reward_received',
                        user_id: $shareReferralUser?->id
                    );
                }
            }
            $data = $this->temp_notification->get([
                'trip_request_id' => $request['trip_request_id'],
                'relations' => 'user'
            ]);
            $push = getNotification('ride_cancelled');
            if (!empty($data)) {
                if ($trip->driver_id) {
                    if (!is_null($trip->driver->fcm_token)) {
                        sendDeviceNotification(fcm_token: $trip->driver->fcm_token,
                            title: translate($push['title']),
                            description: translate(textVariableDataFormat(value: $push['description'])),
                            ride_request_id: $request['trip_request_id'],
                            type: $trip->type,
                            action: 'ride_cancelled',
                            user_id: $trip->driver->id
                        );
                    }
                    try {
                        checkPusherConnection(CustomerTripCancelledEvent::broadcast($trip->driver, $trip));
                    } catch (Exception $exception) {

                    }
                    $this->driver_details->update(attributes: ['column' => 'user_id', 'availability_status' => 'available'], id: $trip->driver_id);
                    $attributes['driver_id'] = $trip->driver_id;
                } else {
                    $notification = [
                        'title' => translate($push['title']),
                        'description' => translate($push['description']),
                        'ride_request_id' => $trip->id,
                        'type' => $trip->type,
                        'action' => 'ride_cancelled'
                    ];
                    dispatch(new SendPushNotificationJob($notification, $data))->onQueue('high');
                    foreach ($data as $tempNotification) {
                        try {
                            checkPusherConnection(CustomerTripCancelledEvent::broadcast($tempNotification->user, $trip));
                        } catch (Exception $exception) {

                        }
                    }
                }
                $this->temp_notification->delete($trip->id);
            }
        }
        if ($trip->is_paused) {

            return response()->json(responseFormatter(TRIP_REQUEST_PAUSED_404), 403);
        }

        if ($trip->driver_id && ($request->status == 'completed' || $request->status == 'cancelled') && $trip->current_status == ONGOING) {
            if ($request->status == 'cancelled') {
                $attributes['fee']['cancelled_by'] = 'customer';
            }
            $attributes['coordinate']['drop_coordinates'] = new Point($trip->driver->lastLocations->latitude, $trip->driver->lastLocations->longitude);

            $this->driver_details->update(attributes: ['column' => 'user_id', 'availability_status' => 'available'], id: $trip->driver_id);
            if ($request->status == 'cancelled' && $trip->type == PARCEL) {
                $push = getNotification('ride_' . $request->status);
                if (!is_null($trip->driver->fcm_token)) {
                    sendDeviceNotification(fcm_token: $trip->driver->fcm_token,
                        title: translate($push['title']),
                        description: translate(textVariableDataFormat(value: $push['description'])),
                        ride_request_id: $request['trip_request_id'],
                        type: $trip->type,
                        action: 'parcel_cancelled',
                        user_id: $trip->driver->id
                    );
                }
            } else {
                //Get status wise notification message
                $push = getNotification('ride_' . $request->status);
                if (!is_null($trip->driver->fcm_token)) {
                    sendDeviceNotification(fcm_token: $trip->driver->fcm_token,
                        title: translate($push['title']),
                        description: translate(textVariableDataFormat(value: $push['description'])),
                        ride_request_id: $request['trip_request_id'],
                        type: $trip->type,
                        action: 'ride_completed',
                        user_id: $trip->driver->id
                    );
                }
                try {
                    checkPusherConnection(CustomerTripCancelledAfterOngoingEvent::broadcast($trip));
                } catch (Exception $exception) {

                }
            }

        }

        DB::beginTransaction();
        if ($request->status == 'cancelled' && $trip->driver_id && $trip->current_status == ONGOING) {
            $this->trip->updateRelationalTable($attributes);
            $this->customerLevelUpdateChecker(auth()->user());
            $this->driverLevelUpdateChecker($trip->driver);
        } elseif ($request->status == 'completed' && $trip->driver_id && $trip->current_status == ONGOING) {
            $this->trip->updateRelationalTable($attributes);
            $this->customerLevelUpdateChecker(auth()->user());
            $this->driverLevelUpdateChecker($trip->driver);
        } else {
            $this->trip->updateRelationalTable($attributes);
        }
        DB::commit();
        if ($trip->driver_id && $request->status == 'cancelled' && $trip->current_status == ONGOING && $trip->type == PARCEL) {
            // Force live mode - temporary fix
            $otp = rand(1000, 9999);
            $trip->otp = $otp;
            if ($trip?->parcel?->payer == SENDER) {
                $trip->paid_fare += $trip->return_fee;
                $trip->due_amount = $trip->return_fee;
                $trip->payment_status = PARTIAL_PAID;
            }
            $trip->current_status = RETURNING;
            $parcelReturnTimeFeeStatus = businessConfig('parcel_return_time_fee_status', PARCEL_SETTINGS)?->value ?? false;
            $time = (int)businessConfig('return_time_for_driver', PARCEL_SETTINGS)?->value;
            $timeType = businessConfig('return_time_type_for_driver', PARCEL_SETTINGS)?->value;
            if ($parcelReturnTimeFeeStatus) {
                if ($timeType === 'hour') {
                    $trip->return_time = Carbon::now()->addHours($time);
                } else {
                    $trip->return_time = Carbon::now()->addDays($time);
                }
            }
            $trip->save();
            $trip->tripStatus()->update([
                RETURNING => now()
            ]);
        }

        return response()->json(responseFormatter(DEFAULT_UPDATE_200, TripRequestResource::make($trip)));
    }

    /**
     * @param $trip_request_id
     * @return JsonResponse
     */
    public function rideDetails($trip_request_id): JsonResponse
    {
        $attributes = [
            'relations' => [
                'driver', 'vehicle.model', 'vehicleCategory', 'tripStatus',
                'coordinate', 'fee', 'time', 'parcel', 'parcelUserInfo'
            ],
            'withAvgRelation' => 'driverReceivedReviews',
            'withAvgColumn' => 'rating'
        ];

        $data = $this->trip->getBy('id', $trip_request_id, $attributes);
        if (!$data) {

            return response()->json(responseFormatter(DEFAULT_404), 403);
        }
        $resource = TripRequestResource::make($data->append('distance_wise_fare'));

        return response()->json(responseFormatter(DEFAULT_200, $resource));

    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function rideList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter' => Rule::in([TODAY, PREVIOUS_DAY, THIS_WEEK, LAST_WEEK, LAST_7_DAYS, THIS_MONTH, LAST_MONTH, THIS_YEAR, ALL_TIME, CUSTOM_DATE]),
            'status' => Rule::in([ALL, PENDING, ONGOING, COMPLETED, CANCELLED, RETURNED]),
            'start' => 'required_if:filter,==,custom_date|required_with:end',
            'end' => 'required_if:filter,==,custom_date|required_with:end',
            'limit' => 'required|numeric',
            'offset' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        if (!is_null($request->filter) && $request->filter != CUSTOM_DATE) {
            $date = getDateRange($request->filter);
        } elseif (!is_null($request->filter)) {
            $date = getDateRange([
                'start' => $request->start,
                'end' => $request->end
            ]);
        }
        $attributes = [
            'column' => 'customer_id',
            'value' => auth('api')->id(),
            'withAvgRelation' => 'driverReceivedReviews',
            'withAvgColumn' => 'rating',
        ];
        if (!empty($date)) {
            $attributes['from'] = $date['start'];
            $attributes['to'] = $date['end'];
        }
        if (!is_null($request->status) && $request->status != ALL) {
            $attributes['column_name'] = 'current_status';
            $attributes['column_value'] = [$request->status];
        }
        $relations = ['driver', 'vehicle.model', 'vehicleCategory', 'time', 'coordinate', 'fee', 'parcel.parcelCategory'];
        $data = $this->trip->get(limit: $request['limit'], offset: $request['offset'], dynamic_page: true, attributes: $attributes, relations: $relations);
        $resource = TripRequestResource::setData('distance_wise_fare')::collection($data);

        return response()->json(responseFormatter(constant: DEFAULT_200, content: $resource, limit: $request['limit'], offset: $request['offset']));
    }


    /**
     * Calculate final trip cost.
     *
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function finalFareCalculation(Request $request): JsonResponse
    {
        $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']
            , attributes: ['relations' =>
                ['vehicleCategory.tripFares', 'coupon', 'time', 'coordinate', 'fee', 'tripStatus']
            ]
        );

        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }

        if ($trip->current_status != 'completed' && $trip->current_status != 'cancelled' && $trip->type == 'ride_request') {

            return response()->json(responseFormatter(constant: TRIP_STATUS_NOT_COMPLETED_200));
        }

        if ($trip->customer_id != auth('api')->id() && $trip->driver_id != auth('api')->id()) {

            return response()->json(responseFormatter(constant: DEFAULT_404), 403);
        }
        if ($trip->discount_amount != null && $trip->discount_amount > 0 && $trip->actual_fare == $trip->discount_amount) {
            $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']
                , attributes: ['relations' => [
                    'vehicleCategory.tripFares', 'customer', 'driver', 'coupon', 'discount', 'time', 'coordinate', 'fee', 'tripStatus']
                ]
            );
            $trip = new TripRequestResource($trip->append('distance_wise_fare'));
            return response()->json(responseFormatter(constant: DEFAULT_200, content: $trip));
        }
        if ($trip->paid_fare != 0) {
            $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']
                , attributes: ['relations' => [
                    'vehicleCategory.tripFares', 'customer', 'driver', 'coupon', 'discount', 'time', 'coordinate', 'fee', 'tripStatus']
                ]
            );
            $trip = new TripRequestResource($trip->append('distance_wise_fare'));
            return response()->json(responseFormatter(constant: DEFAULT_200, content: $trip));
        }


        if ($trip->type == 'ride_request') {
            $fare = $trip->vehicleCategory->tripFares->where('zone_id', $request->header('zoneId'))->first();
            if (!$fare) {
                return response()->json(responseFormatter(ZONE_404), 403);
            }
        } else {
            $fare = null;
        }

        DB::beginTransaction();
        //final fare calculation trait
        $calculated_data = $this->calculateFinalFare($trip, $fare);

        $attributes = [
            'paid_fare' => round($calculated_data['final_fare'], 2),
            'actual_fare' => round($calculated_data['actual_fare'], 2),
            'column' => 'id',
            'actual_distance' => $calculated_data['actual_distance'],
        ];
        $this->trip->update(attributes: $attributes, id: $request['trip_request_id']);

        $bid_on_fare = FareBidding::where('trip_request_id', $request['trip_request_id'])->where('is_ignored', 0)->first();
        if (($bid_on_fare || $trip->rise_request_count > 0) && $trip->type == 'ride_request') {
            $this->finalFareCouponAutoApply($trip->customer, $request['trip_request_id']);
        } else {
            $this->finalFareDiscountAutoApply($trip->customer, $request['trip_request_id']);
            $this->finalFareCouponAutoApply($trip->customer, $request['trip_request_id']);
        }
        DB::commit();

        
        $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']
            , attributes: ['relations' =>
                ['vehicleCategory.tripFares', 'coupon', 'time', 'coordinate', 'fee', 'tripStatus']
            ]
        );
        $amount = $trip->paid_fare + $trip->return_fee;
        if ($trip->type == PARCEL && $trip->current_status == RETURNING && $trip?->parcel?->payer == "receiver") {
            $trip->paid_fare += $trip->return_fee;
            $trip->due_amount = $amount;
            $trip->save();
        }

        //referral user reward
        if ($trip->customer->referralCustomerDetails && $trip->customer->referralCustomerDetails->is_used == 0) {
            $trip->customer->referralCustomerDetails()->update([
                'is_used' => 1
            ]);
            if ($trip->customer?->referralCustomerDetails?->ref_by_earning_amount && $trip->customer?->referralCustomerDetails?->ref_by_earning_amount > 0) {
                $shareReferralUser = $trip->customer?->referralCustomerDetails?->shareRefferalCustomer;
                $this->customerReferralEarningTransaction($shareReferralUser, $trip->customer?->referralCustomerDetails?->ref_by_earning_amount);

                $push = getNotification('referral_reward_received');
                sendDeviceNotification(fcm_token: $shareReferralUser?->fcm_token,
                    title: translate($push['title']),
                    description: translate(textVariableDataFormat(value: $push['description'], referralRewardAmount: getCurrencyFormat($trip->customer?->referralCustomerDetails?->ref_by_earning_amount))),
                    ride_request_id: $shareReferralUser?->id,
                    action: 'referral_reward_received',
                    user_id: $shareReferralUser?->id
                );
            }

        }
        $trip = $this->trip->getBy(column: 'id', value: $request['trip_request_id']
            , attributes: ['relations' => [
                'vehicleCategory.tripFares', 'customer', 'driver', 'coupon', 'discount', 'time', 'coordinate', 'fee', 'tripStatus']
            ]
        );
        $trip = new TripRequestResource($trip->append('distance_wise_fare'));
        return response()->json(responseFormatter(constant: DEFAULT_200, content: $trip));
    }

    /**
     * Summary of driversNearMe
     * @param Request $request
     * @return JsonResponse
     */
    public function driversNearMe(Request $request): JsonResponse
    {
        if (is_null($request->header('zoneId'))) {

            return response()->json(responseFormatter(ZONE_404));
        }

        // Find drivers list based on customer last locations
        $driver_list = $this->findNearestDriver(
            latitude: $request->latitude,
            longitude: $request->longitude,
            zone_id: $request->header('zoneId'),
            radius: (double)(get_cache('search_radius') ?? 50)
        );
        $lastLocationDriver = LastLocationResource::collection($driver_list);
        return response()->json(responseFormatter(constant: DEFAULT_200, content: $lastLocationDriver));
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function arrivalTime(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required'
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        $time = TripRequestTime::query()->where('trip_request_id', $request->trip_request_id)->first();
        if (!$time) {

            return response()->json(responseFormatter(TRIP_REQUEST_404), 403);
        }
        $time->customer_arrives_at = now();
        $time->save();

        return response()->json(responseFormatter(constant: DEFAULT_UPDATE_200));
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function coordinateArrival(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required',
            'is_reached' => 'required|in:coordinate_1,coordinate_2,destination',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $trip = TripRequestCoordinate::query()->where('trip_request_id', $request->trip_request_id)->first();
        if ($request->is_reached == 'coordinate_1') {
            $trip->is_reached_1 = true;
        }
        if ($request->is_reached == 'coordinate_2') {
            $trip->is_reached_2 = true;
        }
        if ($request->is_reached == 'destination') {
            $trip->is_reached_destination = true;
        }
        $trip->save();

        return response()->json(responseFormatter(DEFAULT_UPDATE_200));

    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required',
            'coupon_code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        $trip = $this->trip->getBy(column: 'id', value: $request->trip_request_id, attributes: ['relations' => 'driver', 'fee']);
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        if ($trip->coupon_id) {

            return response()->json(responseFormatter(constant: COUPON_APPLIED_403), 403);
        }
        $user = auth('api')->user();
        $date = date('Y-m-d');
        $coupon = $this->coupon->getAppliedCoupon([
            'coupon_code' => $request->coupon_code,
            'fare' => $trip->paid_fare,
            'date' => $date
        ]);

        if (!$coupon) {

            return response()->json(responseFormatter(constant: COUPON_404, content: ['discount' => 0]), 403);
        }
        $response = $this->getCouponDiscount($user, $trip, $coupon);

        if ($response['discount'] != 0) {
            $admin_trip_commission = (double)get_cache('trip_commission') ?? 0;
            $vat_percent = (double)get_cache('vat_percent') ?? 1;
            $final_fare_without_tax = ($trip->paid_fare - $trip->fee->vat_tax - $trip->fee->tips) - $response['discount'];
            $vat = ($vat_percent * $final_fare_without_tax) / 100;
            $admin_commission = (($final_fare_without_tax * $admin_trip_commission) / 100) + $vat;
            $updateTrip = TripRequest::find($request->trip_request_id);
            $updateTrip->coupon_id = $coupon->id;
            $updateTrip->coupon_amount = $response['discount'];
            $updateTrip->paid_fare = $final_fare_without_tax + $vat + $trip->fee->tips;
            $updateTrip->fee()->update([
                'vat_tax' => $vat,
                'admin_commission' => $admin_commission
            ]);
            $updateTrip->save();

            $push = getNotification('coupon_applied');
            sendDeviceNotification(
                fcm_token: $trip->driver->fcm_token,
                title: translate($push['title']),
                description: translate(textVariableDataFormat(value: $push['description'])) . ' ' . $response['discount'],
                ride_request_id: $trip->id,
                type: $trip->type,
                action: 'coupon_applied',
                user_id: $trip->driver->id
            );
            try {
                checkPusherConnection(CustomerCouponAppliedEvent::broadcast($trip));
            } catch (Exception $exception) {

            }
            $trip = new TripRequestResource($trip->append('distance_wise_fare'));
            return response()->json(responseFormatter(constant: $response['message'], content: $trip));
        }

        return response()->json(responseFormatter(constant: $response['message'], content: $trip), 403);
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelCoupon(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        $trip = $this->trip->getBy(column: 'id', value: $request->trip_request_id, attributes: ['relations' => 'driver']);
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        if (is_null($trip->coupon_id)) {
            return response()->json(responseFormatter(constant: COUPON_404), 403);
        }

        DB::beginTransaction();
        $this->removeCouponData($trip);
        DB::commit();

        $push = getNotification('coupon_removed');
        sendDeviceNotification(
            fcm_token: $trip->driver->fcm_token,
            title: translate($push['title']),
            description: translate(textVariableDataFormat(value: $push['description'])),
            ride_request_id: $trip->id,
            type: $trip->type,
            action: 'coupon_removed',
            user_id: $trip->driver->id
        );
        try {
            checkPusherConnection(CustomerCouponRemovedEvent::broadcast($trip));
        } catch (Exception $exception) {

        }
        $trip = new TripRequestResource($trip->append('distance_wise_fare'));
        return response()->json(responseFormatter(constant: DEFAULT_UPDATE_200, content: $trip));
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function pendingParcelList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required|numeric',
            'offset' => 'required|numeric',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }
        $attributes = [
            'limit' => $request->limit,
            'offset' => $request->offset,
            'column' => 'customer_id',
            'value' => auth()->id(),
            'whereNotNull' => 'customer_id',
        ];

        $trips = $this->trip->pendingParcelList($attributes, 'customer');
        $trips = TripRequestResource::collection($trips);

        return response()->json(responseFormatter(constant: DEFAULT_200, content: $trips, limit: $request->limit, offset: $request->offset));
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function unpaidParcelRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required|numeric',
            'offset' => 'required|numeric',
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $trips = $this->trip->unpaidParcelRequest([
            'limit' => $request->limit,
            'offset' => $request->offset,
            'column' => 'customer_id',
            'value' => auth()->id(),
            'whereHas' => true,
            'relations' => ['customer', 'driver', 'vehicleCategory', 'vehicleCategory.tripFares', 'vehicle', 'coupon', 'time',
                'coordinate', 'fee', 'tripStatus', 'zone', 'vehicle.model', 'fare_biddings', 'parcel', 'parcelUserInfo']
        ]);
        $trips = TripRequestResource::collection($trips);

        return response()->json(responseFormatter(constant: DEFAULT_200, content: $trips, limit: $request->limit, offset: $request->offset));
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function storeScreenshot(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trip_request_id' => 'required',
            'file' => 'required|mimes:jpg,png'
        ]);

        if ($validator->fails()) {

            return response()->json(responseFormatter(constant: DEFAULT_400, errors: errorProcessor($validator)), 403);
        }

        $this->trip->update([
            'column' => 'id',
            'map_screenshot' => $request->file,
        ], $request->trip_request_id);

        return response()->json(responseFormatter(DEFAULT_200));
    }


    #receivedReturingParcel
    public function receivedReturningParcel($trip_request_id): JsonResponse
    {
        $trip = $this->trip->getBy(column: 'id', value: $trip_request_id, attributes: ['relations' => 'driver.lastLocations', 'time', 'coordinate', 'fee']);
        if (!$trip) {
            return response()->json(responseFormatter(constant: TRIP_REQUEST_404), 403);
        }
        if ($trip->current_status == RETURNED) {
            return response()->json(responseFormatter(TRIP_STATUS_RETURNED_403), 403);
        }
        DB::beginTransaction();
        if ($trip?->fee?->cancelled_by == CUSTOMER && $trip?->parcel?->payer == 'sender' && $trip->due_amount > 0) {
            $this->cashReturnFeeTransaction($trip);
        }
        if ($trip?->fee?->cancelled_by == CUSTOMER && $trip?->parcel?->payer == 'receiver' && $trip->due_amount > 0) {
            $this->cashTransaction($trip, true);
            $this->cashReturnFeeTransaction($trip);
        }
        if ($trip?->fee?->cancelled_by == CUSTOMER) {
            $trip->payment_status = PAID;
        }
        $trip->due_amount = 0;
        $trip->current_status = RETURNED;
        $trip->save();
        $trip->tripStatus()->update([
            RETURNED => now()
        ]);
        DB::commit();
        $this->returnTimeExceedFeeTransaction($trip);
        $push = getNotification('parcel_returned');
        sendDeviceNotification(fcm_token: $trip->driver->fcm_token,
            title: translate($push['title']),
            description: translate(textVariableDataFormat(value: $push['description'])),
            ride_request_id: $trip_request_id,
            type: $trip->type,
            action: 'parcel_returned',
            user_id: $trip->driver->id
        );
        return response()->json(responseFormatter(DEFAULT_UPDATE_200, TripRequestResource::make($trip)));
    }


    /**
     * Summary of findNearestDriver
     * @param mixed $latitude
     * @param mixed $longitude
     * @param mixed $zone_id
     * @param mixed $radius
     * @param null $vehicleCategoryId
     * @return mixed
     */
    private function findNearestDriver($latitude, $longitude, $zone_id, $radius = 50, $vehicleCategoryId = null, $requestType = null, $parcelWeight = null): mixed
    {
        /*
         * replace 6371000 with 6371 for kilometer and 3956 for miles
         */
        $attributes = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius' => $radius,
            'zone_id' => $zone_id,
        ];
        if ($vehicleCategoryId) {
            $attributes['vehicle_category_id'] = $vehicleCategoryId;
        }
        if ($parcelWeight) {
            $attributes['parcel_weight_capacity'] = $parcelWeight;
        }
        if ($requestType) {
            $attributes['service'] = $requestType;
        }
        return $this->lastLocation->getNearestDrivers($attributes);

    }

    /**
     * @return Model|mixed|null
     */
    private function getIncompleteRide(): mixed
    {
        $trip = $this->trip->getBy(column: 'customer_id', value: auth()->id(), attributes: [
            'withAvgRelation' => 'driverReceivedReviews',
            'withAvgColumn' => 'rating',
            'relations' => ['customer', 'driver', 'vehicleCategory', 'vehicleCategory.tripFares', 'vehicle', 'coupon', 'time',
                'coordinate', 'fee', 'tripStatus', 'zone', 'vehicle.model', 'fare_biddings', 'parcel', 'parcelUserInfo', 'customerReceivedReviews', 'driverReceivedReviews']
        ]);

        if (!$trip || $trip->type != 'ride_request' ||
            $trip->fee->cancelled_by == 'driver' ||
            (!$trip->driver_id && $trip->current_status == 'cancelled') ||
            ($trip->driver_id && $trip->payment_status == PAID)) {

            return null;
        }
        return $trip;
    }

    private function getResumeRide(): mixed
    {
        $attributes = [
            'type' => 'ride_request',
            'column' => 'customer_id',
            'value' => auth()->id(),
        ];
        $tripRequest = $this->trip->getIncompleteRide($attributes);
        if (!$tripRequest) {
            return null;
        }
        $trip = $this->trip->getBy(column: 'id', value: $tripRequest->id, attributes: [
            'withAvgRelation' => 'driverReceivedReviews',
            'withAvgColumn' => 'rating',
            'relations' => ['customer', 'driver', 'vehicleCategory', 'vehicleCategory.tripFares', 'vehicle', 'coupon', 'time',
                'coordinate', 'fee', 'tripStatus', 'zone', 'vehicle.model', 'fare_biddings', 'parcel', 'parcelUserInfo', 'customerReceivedReviews', 'driverReceivedReviews']
        ]);
        return $trip;
    }

    private function removeCouponData($trip)
    {
        $this->coupon->removeCouponUsage(attributes: ['id' => $trip->coupon_id, 'amount' => $trip->coupon_amount]);

        $trip = $this->trip->getBy(column: 'id', value: $trip->id);
        $vat_percent = (double)get_cache('vat_percent') ?? 1;
        $final_fare_without_tax = ($trip->paid_fare - $trip->fee->vat_tax - $trip->fee->tips) + $trip->coupon_amount;
        $vat = ($vat_percent * $final_fare_without_tax) / 100;
        $trip->coupon_id = null;
        $trip->coupon_amount = 0;
        $trip->paid_fare = $final_fare_without_tax + $vat + $trip->fee->tips;
        $trip->fee()->update([
            'vat_tax' => $vat
        ]);
        $trip->save();
    }

    private function finalFareDiscountAutoApply($user, $tripId)
    {
        $trip = $this->trip->getBy(column: 'id', value: $tripId);

        $updateTripDiscount = $this->trip->getBy(column: 'id', value: $trip->id);
        $updateTripDiscount->discount_id = null;
        $updateTripDiscount->discount_amount = null;
        $updateTripDiscount->save();

        $finalData = $this->trip->getBy(column: 'id', value: $updateTripDiscount->id);
        $response = $this->getFinalDiscount(user: $user, trip: $finalData);
        if ($response['discount_amount'] != 0) {
            $finalData = $this->trip->getBy(column: 'id', value: $updateTripDiscount->id);
            $admin_trip_commission = (double)get_cache('trip_commission') ?? 0;
            $vat_percent = (double)get_cache('vat_percent') ?? 1;
            $final_fare_without_tax = ($finalData->paid_fare - $finalData->fee->vat_tax - $finalData->fee->tips) - $response['discount_amount'];
            $vat = ($vat_percent * $final_fare_without_tax) / 100;
            $admin_commission = (($final_fare_without_tax * $admin_trip_commission) / 100) + $vat;
            $updateTrip = $this->trip->getBy(column: 'id', value: $finalData->id);
            $updateTrip->discount_id = $response['discount_id'];
            $updateTrip->discount_amount = $response['discount_amount'];
            $updateTrip->paid_fare = $final_fare_without_tax + $vat + $updateTrip->fee->tips;
            $updateTrip->fee()->update([
                'vat_tax' => $vat,
                'admin_commission' => $admin_commission
            ]);
            $updateTrip->save();
            if ($response['discount_id'] != null) {
                $this->updateDiscountCount($response['discount_id'], $response['discount_amount']);
            }
        }
    }

    private function finalFareCouponAutoApply($user, $tripId)
    {
        $trip = $this->trip->getBy(column: 'id', value: $tripId);
        $response = $this->getFinalCouponDiscount(user: $user, trip: $trip);
        if ($response['discount'] != 0) {
            $trip = $this->trip->getBy(column: 'id', value: $tripId);
            $admin_trip_commission = (double)get_cache('trip_commission') ?? 0;
            $vat_percent = (double)get_cache('vat_percent') ?? 1;
            $final_fare_without_tax = ($trip->paid_fare - $trip->fee->vat_tax - $trip->fee->tips) - $response['discount'];
            $vat = ($vat_percent * $final_fare_without_tax) / 100;
            $admin_commission = (($final_fare_without_tax * $admin_trip_commission) / 100) + $vat;
            $updateTrip = $this->trip->getBy(column: 'id', value: $trip->id);
            $updateTrip->coupon_id = $response['coupon_id'];
            $updateTrip->coupon_amount = $response['discount'];
            $updateTrip->paid_fare = $final_fare_without_tax + $vat + $trip->fee->tips;
            $updateTrip->fee()->update([
                'vat_tax' => $vat,
                'admin_commission' => $admin_commission
            ]);
            $updateTrip->save();
            $this->updateCouponCount($response['coupon'], $response['discount']);

        }
    }

}
