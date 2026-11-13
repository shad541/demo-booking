<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_delete_booking_and_rebook_slot(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();
        $this->assertModelMissing($booking);

        $this->postJson('/api/bookings', [
            'room_id' => $booking->room_id,
            'title' => 'Повторная бронь',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();
    }

    public function test_another_user_cannot_delete_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        $this->assertModelExists($booking);
    }

    public function test_missing_booking_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
        $this->deleteJson('/api/bookings/not-an-id')->assertNotFound();
    }

    public function test_write_endpoints_require_authentication_even_without_accept_header(): void
    {
        $booking = Booking::factory()->create();

        $this->post('/api/bookings', [])->assertUnauthorized();
        $this->delete("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertModelExists($booking);
        $this->assertDatabaseCount('bookings', 1);
    }
}
