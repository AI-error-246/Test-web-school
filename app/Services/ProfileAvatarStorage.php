<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProfileAvatarStorage
{
    public function store(UploadedFile $file, string $userId): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['avatar' => __('profile.avatar_invalid')]),
        };
        $key = 'profile-avatars/'.$userId.'/'.Str::uuid().'.'.$extension;

        try {
            $stored = Storage::disk('local')->putFileAs(dirname($key), $file, basename($key));
        } catch (Throwable $exception) {
            $this->delete($key, $userId);
            report($exception);
            throw ValidationException::withMessages(['avatar' => __('profile.avatar_save_failed')]);
        }

        if ($stored === false) {
            $this->delete($key, $userId);
            throw ValidationException::withMessages(['avatar' => __('profile.avatar_save_failed')]);
        }

        return $key;
    }

    public function show(?string $key, string $userId): BinaryFileResponse
    {
        abort_unless($this->belongsTo($key, $userId) && Storage::disk('local')->exists($key), 404);

        $contentType = match (pathinfo($key, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };

        return response()->file(Storage::disk('local')->path($key), [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function delete(?string $key, string $userId): void
    {
        if (! $this->belongsTo($key, $userId)) {
            return;
        }

        try {
            if (! Storage::disk('local')->delete($key)) {
                report(new \RuntimeException('Unable to remove a replaced private profile image.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function belongsTo(?string $key, string $userId): bool
    {
        return is_string($key)
            && preg_match('/\Aprofile-avatars\/'.preg_quote($userId, '/').'\/[a-f0-9-]{36}\.(?:jpg|png|webp)\z/', $key) === 1;
    }
}
