<?php

namespace App\Http\Requests;

use App\Models\Room;
use App\Rules\Iso8601Timestamp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['bail', 'required', 'integer', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'starts_at' => ['bail', 'required', new Iso8601Timestamp],
            'ends_at' => ['bail', 'required', new Iso8601Timestamp],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $room = Room::query()->with('office')->findOrFail($this->input('room_id'));
            $start = CarbonImmutable::parse($this->input('starts_at'))->setTimezone($room->office->timezone);
            $end = CarbonImmutable::parse($this->input('ends_at'))->setTimezone($room->office->timezone);
            $duration = $start->diffInSeconds($end);

            if ($duration < 15 * 60 || $duration > 4 * 60 * 60) {
                $validator->errors()->add('ends_at', 'Длительность брони должна быть от 15 минут до 4 часов; конец должен быть позже начала.');
            }

            foreach (['starts_at' => $start, 'ends_at' => $end] as $field => $time) {
                if ($time->minute % 15 !== 0 || $time->second !== 0 || $time->microsecond !== 0) {
                    $validator->errors()->add($field, 'Время должно попадать на шаг 15 минут по времени офиса, без секунд и долей секунды.');
                }
            }

            $opening = $start->startOfDay()->setTime(9, 0);
            $closing = $start->startOfDay()->setTime(20, 0);

            if ($start->lt($opening) || $start->gte($closing)) {
                $validator->errors()->add('starts_at', 'Бронь должна начинаться с 09:00 до 20:00 по местному времени офиса.');
            }

            if (! $start->isSameDay($end) || $end->gt($closing)) {
                $validator->errors()->add('ends_at', 'Бронь должна заканчиваться не позже 20:00 того же дня по местному времени офиса.');
            }
        }];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('starts_at'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('ends_at'))->utc();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Выбранная комната не существует.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала брони.',
            'ends_at.required' => 'Укажите время окончания брони.',
        ];
    }
}
