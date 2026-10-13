<?php

namespace App\Http\Controllers;

use App\Services\SupportAssistant;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SupportAssistantController extends Controller
{
    public function ask(Request $request, SupportAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1200'],
            'history' => ['sometimes', 'array', 'max:6'],
            'history.*' => ['required', 'array:role,content'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:1200'],
            'filters' => ['sometimes', 'array:q,category_id,location_id,work_mode'],
            'filters.q' => ['nullable', 'string', 'max:120'],
            'filters.category_id' => ['nullable', 'integer', 'min:1'],
            'filters.location_id' => ['nullable', 'integer', 'min:1'],
            'filters.work_mode' => ['nullable', 'in:ONSITE,REMOTE,HYBRID'],
        ]);

        if (! $assistant->configured()) {
            return $this->unavailable();
        }

        $slot = null;
        try {
            $limits = Cache::lock('assistant:limits', 5);
            if (! $limits->get()) {
                return $this->busy();
            }
            try {
                $identities = ['ip:'.(string) $request->server('REMOTE_ADDR', 'unknown')];
                if ($request->user()) {
                    $identities[] = 'user:'.$request->user()->getAuthIdentifier();
                }
                $keys = array_map(fn (string $identity): string => 'assistant:minute:'.hash_hmac('sha256', $identity, (string) config('app.key')), $identities);
                $minuteLimit = max(1, min(30, (int) config('assistant.requests_per_minute')));
                foreach ($keys as $key) {
                    if (RateLimiter::tooManyAttempts($key, $minuteLimit)) {
                        return $this->limited(max(1, RateLimiter::availableIn($key)));
                    }
                }

                $day = now('UTC');
                $dailyKey = 'assistant:daily:'.$day->format('Y-m-d');
                if ((int) Cache::get($dailyKey, 0) >= max(0, (int) config('assistant.daily_request_limit'))) {
                    return $this->limited(max(1, (int) $day->diffInSeconds($day->copy()->addDay()->startOfDay())));
                }
                $slot = $this->reserveSlot($assistant->timeout() + 10);
                if (! $slot) {
                    return $this->busy();
                }
                foreach ($keys as $key) {
                    RateLimiter::hit($key, 60);
                }
                // Bound total search work as well as per-visitor traffic.
                Cache::put($dailyKey, (int) Cache::get($dailyKey, 0) + 1, $day->copy()->addDay()->startOfDay());
            } finally {
                $limits->release();
            }

            return response()->json($assistant->answer($data['message'], $data['history'] ?? [], $data['filters'] ?? []));
        } catch (Throwable) {
            // Never expose credentials, chat text, or database connection details.
            return $this->unavailable();
        } finally {
            try {
                $slot?->release();
            } catch (Throwable) {
                // The short lock TTL recovers the slot if the cache becomes unavailable.
            }
        }
    }

    private function reserveSlot(int $seconds): ?Lock
    {
        for ($index = 0; $index < max(1, min(10, (int) config('assistant.max_concurrent_requests'))); $index++) {
            $lock = Cache::lock('assistant:slot:'.$index, $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private function limited(int $seconds): JsonResponse
    {
        return response()->json(['message' => __('assistant.rate_limited'), 'code' => 'assistant_rate_limited', 'retry_after' => $seconds], 429, ['Retry-After' => (string) $seconds]);
    }

    private function busy(): JsonResponse
    {
        return response()->json(['message' => __('assistant.busy'), 'code' => 'assistant_busy'], 503, ['Retry-After' => '5']);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('assistant.unavailable'), 'code' => 'assistant_unavailable'], 503);
    }
}
