<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_requests_for_empty_room_create_only_one_booking(): void
    {
        $room = Room::factory()->create();
        $token = User::factory()->create()->createToken('concurrency-test')->plainTextToken;
        $applicationName = 'booking-test-'.Str::uuid();
        $payload = json_encode([
            'room_id' => $room->id,
            'title' => 'Concurrent meeting',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], JSON_THROW_ON_ERROR);
        $processes = [
            $this->requestProcess($payload, $token, $applicationName),
            $this->requestProcess($payload, $token, $applicationName),
        ];

        DB::beginTransaction();

        try {
            Room::query()->lockForUpdate()->findOrFail($room->id);

            foreach ($processes as $process) {
                $process->start();
            }

            $deadline = microtime(true) + 10;

            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::scalar(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                    [$applicationName],
                );

                if ($waiting === 2) {
                    break;
                }

                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Request exited before acquiring the lock: '.$process->getErrorOutput().$process->getOutput());
                    }
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            $this->assertSame(2, $waiting, 'Both requests must reach the room lock before it is released.');
            DB::commit();

            $responses = [];

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($responses, 'status');
            sort($statuses);

            $this->assertSame([201, 422], $statuses);
            $this->assertDatabaseCount('bookings', 1);

            $rejected = array_values(array_filter($responses, fn (array $response): bool => $response['status'] === 422))[0];
            $this->assertArrayHasKey('starts_at', $rejected['body']['errors']);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function requestProcess(string $payload, string $token, string $applicationName): Process
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $kernel->bootstrap();
            Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[3]]);
            $request = Illuminate\Http\Request::create('/api/bookings', 'POST', server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$argv[2],
            ], content: $argv[1]);
            $response = $kernel->handle($request);
            echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
            $kernel->terminate($request, $response);
            PHP;

        return new Process([PHP_BINARY, '-r', $code, $payload, $token, $applicationName], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
        ], timeout: 15);
    }
}
