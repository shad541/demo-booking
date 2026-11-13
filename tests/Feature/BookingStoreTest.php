<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creates_booking_for_authenticated_user_and_stores_utc(): void
    {
        $other = User::factory()->create();

        $response = $this->postJson('/api/bookings', $this->payload([
            'user_id' => $other->id,
            'id' => 999999,
            'created_at' => '2000-01-01 00:00:00',
        ]))->assertCreated()
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'user_id' => $this->user->id,
            'room_id' => $this->room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
            'title' => 'Планёрка',
        ]);
        $this->assertNotSame(999999, $response->json('id'));
        $this->assertDatabaseCount('bookings', 1);
    }

    #[DataProvider('validIntervals')]
    public function test_accepts_valid_intervals(string $timezone, string $start, string $end): void
    {
        $this->room->office->update(['timezone' => $timezone]);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => $start,
            'ends_at' => $end,
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function validIntervals(): array
    {
        return [
            'minimum duration and opening' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T09:15:00+03:00'],
            'maximum duration and closing' => ['Europe/Moscow', '2026-11-02T16:00:00+03:00', '2026-11-02T20:00:00+03:00'],
            'Novosibirsk opening from UTC' => ['Asia/Novosibirsk', '2026-11-02T02:00:00Z', '2026-11-02T02:15:00Z'],
            'Novosibirsk closing from Moscow' => ['Asia/Novosibirsk', '2026-11-02T15:45:00+03:00', '2026-11-02T16:00:00+03:00'],
            'client date differs from office date' => ['Asia/Novosibirsk', '2026-11-01T21:00:00-05:00', '2026-11-01T21:15:00-05:00'],
            'different offsets on endpoints' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T09:00:00+01:00'],
            'quarter-hour offset converted before grid check' => ['Asia/Novosibirsk', '2026-11-02T07:45:00+05:45', '2026-11-02T08:00:00+05:45'],
            'offset need not be a multiple of fifteen minutes' => ['Europe/Moscow', '2026-11-02T06:07:00+00:07', '2026-11-02T06:22:00+00:07'],
            'zero fractional seconds' => ['Europe/Moscow', '2026-11-02T10:00:00.000000+03:00', '2026-11-02T10:15:00.000+03:00'],
            'ISO without seconds' => ['Europe/Moscow', '2026-11-02T10:00+03:00', '2026-11-02T10:15+03:00'],
            'ISO basic offset' => ['Europe/Moscow', '2026-11-02T10:00:00+0300', '2026-11-02T10:15:00+0300'],
            'past dates allowed' => ['Europe/Moscow', '2000-01-01T10:00:00+03:00', '2000-01-01T10:15:00+03:00'],
            'weekends allowed' => ['Europe/Moscow', '2026-11-07T10:00:00+03:00', '2026-11-07T10:15:00+03:00'],
            'far future allowed' => ['Europe/Moscow', '2099-11-02T10:00:00+03:00', '2099-11-02T10:15:00+03:00'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_rejects_invalid_payload_without_writing(array $changes, string $field): void
    {
        $this->postJson('/api/bookings', $this->payload($changes))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing room' => [['room_id' => null], 'room_id'],
            'unknown room' => [['room_id' => 999999], 'room_id'],
            'room array' => [['room_id' => [1]], 'room_id'],
            'SQL in room' => [['room_id' => '1 OR 1=1'], 'room_id'],
            'missing title' => [['title' => null], 'title'],
            'blank title' => [['title' => '   '], 'title'],
            'title array' => [['title' => ['meeting']], 'title'],
            'title too long' => [['title' => str_repeat('я', 256)], 'title'],
            'missing start' => [['starts_at' => null], 'starts_at'],
            'missing end' => [['ends_at' => null], 'ends_at'],
            'date without offset' => [['starts_at' => '2026-11-02T10:00:00'], 'starts_at'],
            'date only' => [['starts_at' => '2026-11-02'], 'starts_at'],
            'relative date' => [['starts_at' => 'tomorrow'], 'starts_at'],
            'invalid calendar date' => [['starts_at' => '2026-02-30T10:00:00+03:00'], 'starts_at'],
            'invalid hour' => [['starts_at' => '2026-11-02T25:00:00+03:00'], 'starts_at'],
            'invalid offset' => [['starts_at' => '2026-11-02T10:00:00+25:00'], 'starts_at'],
            'date array' => [['starts_at' => ['2026-11-02T10:00:00+03:00']], 'starts_at'],
            'SQL in date' => [['ends_at' => "2026-11-02T11:00:00+03:00' OR 1=1 --"], 'ends_at'],
            'zero duration' => [['ends_at' => '2026-11-02T10:00:00+03:00'], 'ends_at'],
            'negative duration' => [['ends_at' => '2026-11-02T09:45:00+03:00'], 'ends_at'],
            'under minimum' => [['ends_at' => '2026-11-02T10:10:00+03:00'], 'ends_at'],
            'over maximum' => [['ends_at' => '2026-11-02T14:15:00+03:00'], 'ends_at'],
            'start off grid' => [['starts_at' => '2026-11-02T10:01:00+03:00'], 'starts_at'],
            'end off grid' => [['ends_at' => '2026-11-02T11:01:00+03:00'], 'ends_at'],
            'start seconds' => [['starts_at' => '2026-11-02T10:00:01+03:00'], 'starts_at'],
            'end seconds' => [['ends_at' => '2026-11-02T11:00:01+03:00'], 'ends_at'],
            'start microseconds' => [['starts_at' => '2026-11-02T10:00:00.000001+03:00'], 'starts_at'],
            'end microseconds' => [['ends_at' => '2026-11-02T11:00:00.1+03:00'], 'ends_at'],
            'before opening' => [['starts_at' => '2026-11-02T08:45:00+03:00', 'ends_at' => '2026-11-02T09:00:00+03:00'], 'starts_at'],
            'after closing' => [['starts_at' => '2026-11-02T19:45:00+03:00', 'ends_at' => '2026-11-02T20:15:00+03:00'], 'ends_at'],
            'next day' => [['starts_at' => '2026-11-02T19:00:00+03:00', 'ends_at' => '2026-11-03T10:00:00+03:00'], 'ends_at'],
            'offset makes office time too late' => [['starts_at' => '2026-11-02T19:00:00Z', 'ends_at' => '2026-11-02T20:00:00Z'], 'starts_at'],
        ];
    }

    public function test_uses_novosibirsk_office_hours_instead_of_moscow_hours(): void
    {
        $this->room->office->update(['timezone' => 'Asia/Novosibirsk']);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-11-02T16:00:00+03:00',
            'ends_at' => '2026-11-02T16:15:00+03:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    #[DataProvider('overlappingIntervals')]
    public function test_rejects_every_kind_of_overlap(string $start, string $end): void
    {
        Booking::factory()->for($this->room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => $start,
            'ends_at' => $end,
        ]))->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'same interval' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00'],
            'overlaps start' => ['2026-11-02T09:45:00+03:00', '2026-11-02T10:15:00+03:00'],
            'overlaps end' => ['2026-11-02T10:45:00+03:00', '2026-11-02T11:15:00+03:00'],
            'contained' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'enclosing' => ['2026-11-02T09:45:00+03:00', '2026-11-02T11:15:00+03:00'],
            'same instant with different offset' => ['2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z'],
        ];
    }

    public function test_allows_adjacent_bookings_on_both_sides(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        foreach ([['09:00', '10:00'], ['11:00', '12:00']] as [$start, $end]) {
            $this->postJson('/api/bookings', $this->payload([
                'starts_at' => "2026-11-02T{$start}:00+03:00",
                'ends_at' => "2026-11-02T{$end}:00+03:00",
            ]))->assertCreated();
        }

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_rejects_a_second_request_for_the_same_slot(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();
        $this->postJson('/api/bookings', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_same_slot_in_another_room(): void
    {
        Booking::factory()->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();
    }

    public function test_allows_same_time_on_another_date(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-11-03T10:00:00+03:00',
            'ends_at' => '2026-11-03T11:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_accepts_maximum_title_length(): void
    {
        $this->postJson('/api/bookings', $this->payload(['title' => str_repeat('я', 255)]))
            ->assertCreated();
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $changes);
    }
}
