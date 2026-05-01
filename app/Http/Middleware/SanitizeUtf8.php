<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Sanitize all incoming string data to valid UTF-8.
 *
 * Strips invalid byte sequences, null bytes, and control characters
 * (except newline, carriage return, tab) from request input.
 * This prevents MySQL "Incorrect string value" errors and ensures
 * consistent text handling across the application.
 */
class SanitizeUtf8
{
    public function handle(Request $request, Closure $next)
    {
        $request->merge($this->sanitize($request->all()));

        return $next($request);
    }

    private function sanitize(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitize($value);
            }

            if (! is_string($value)) {
                return $value;
            }

            return $this->cleanString($value);
        }, $data);
    }

    private function cleanString(string $value): string
    {
        // 1. Convert to valid UTF-8, replacing invalid sequences
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // 2. Remove null bytes
        $value = str_replace("\0", '', $value);

        // 3. Remove control characters except \n \r \t
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return $value;
    }
}
