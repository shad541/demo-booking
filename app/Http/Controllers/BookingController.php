<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $start = $request->startsAt();
        $end = $request->endsAt();

        $booking = DB::transaction(function () use ($request, $start, $end): Booking {
            // Lock the room even when it has no bookings yet.
            $room = Room::query()->lockForUpdate()->findOrFail($request->validated('room_id'));

            $conflict = $room->bookings()
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Комната уже занята в выбранное время.',
                ]);
            }

            return $request->user()->bookings()->create([
                'room_id' => $room->id,
                'title' => $request->validated('title'),
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }, attempts: 3);

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Booking $booking): Response
    {
        Gate::authorize('delete', $booking);
        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
