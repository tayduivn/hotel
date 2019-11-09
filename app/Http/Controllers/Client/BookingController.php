<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Booking\StoreRequest;
use App\Models\Room;
use App\Repositories\Invoice\InvoiceRepository;
use App\Repositories\Room\RoomRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    private $roomRepository;
    private $invoiceRepository;

    public function __construct(RoomRepository $roomRepository, InvoiceRepository $invoiceRepository)
    {
        Redis::connection();
        $this->roomRepository = $roomRepository;
        $this->invoiceRepository = $invoiceRepository;
    }

    public function redirectBooking(Request $request)
    {

        $data = $request->except('_token');

        if (Redis::exists('bookingData')) {
            Redis::del('bookingData');
        }
        if (!$data['isAjax']) {
            if (!$data['checkIn'] || !$data['checkOut']) {
                $request->session()->flash('error', __('messages.Booking_missing_requirement'));

                return redirect()->back();
            }
        }


        $rules = [
            'checkIn' => 'required|after:yesterday',
            'checkOut' => 'required|after:checkIn',
        ];

        $messages = [
            'checkIn.required' => __('messages.validation.checkInRequired'),
            'checkOut.required' => __('messages.validation.checkOutARequired'),
            'checkIn.after' => __('messages.validation.checkInAfter'),
            'checkOut.after' => __('messages.validation.checkOutAfter'),
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($data['isAjax']) {
            if ($validator->fails()) {
                $dataResponse = [
                    'messages' => 'validation_fail',
                    'data' => $validator->messages(),
                ];
            } else {
                $room = $this->roomRepository->find($data['roomId']);
                $request->check_in_date = $data['checkIn'];
                $request->check_out_date = $data['checkOut'];
                $check = $this->roomRepository->availableTimeByRoom($request, $room);
                if ($check) {
                    $data['checkIn'] = Carbon::parse($data['checkIn'])->format('d-m-Y');
                    $data['checkOut'] = Carbon::parse($data['checkOut'])->format('d-m-Y');
                    Redis::set('bookingData', json_encode($data));
                    $dataResponse = [
                        'messages' => 'success',
                    ];
                } else {
                    $dataResponse = [
                        'messages' => 'no_room',
                    ];
                }


            }

            return response()->json($dataResponse, 200);
        }

        $data['checkIn'] = Carbon::parse($data['checkIn'])->format('d-m-Y');
        $data['checkOut'] = Carbon::parse($data['checkOut'])->format('d-m-Y');
        Redis::set('bookingData', json_encode($data));

        return redirect(route('booking.index'));
    }

    public function index(Request $request)
    {
        if (!Redis::exists('bookingData')) {
            $request->session()->flash('error', __('messages.Booking_missing_requirement'));

            return redirect()->back();
        }

        $bookingData = json_decode(Redis::get('bookingData'), true);
        $data = $this->roomRepository->getRoomForBooking($bookingData['roomId']);
        $data = array_merge($data, $bookingData);
        $user = Auth::user();
        $user = ['user' => $user];
        $data = array_merge($data, $user);

        return view('client.booking.index', $data);
    }

    public function submit(StoreRequest $request)
    {
        $data = $request->except('_token');
        $room = $this->roomRepository->find($data['room_id']);
        $roomNumber = $this->roomRepository->availableTimeByRoom($request, $room);

        if (!$roomNumber) {
            $request->session()->flash('error', __('messages.No_room_available'));

            return redirect()->back();
        };
        DB::beginTransaction();
        try {
            $this->invoiceRepository->submitBookingClient($data, $roomNumber[0]);
            DB::commit();
            $request->session()->flash('success', 'Đặt phòng thành công');

            return redirect(route('home'));
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception($e->getMessage());
        }
    }
}
