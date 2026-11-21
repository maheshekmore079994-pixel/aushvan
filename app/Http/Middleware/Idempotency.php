<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write request safe to repeat (double click, retry after a network
 * or power failure). The screen sends a unique Idempotency-Key with the
 * request; if the same key arrives again, the first result is returned and
 * nothing is created twice.
 *
 * Only successful results are remembered, so a request that failed
 * validation can be corrected and sent again with the same key.
 */
class Idempotency
{
    public const ABANDONED_AFTER_SECONDS = 60;

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                return new JsonResponse([
                    'code' => 'idempotency_key_missing',
                    'message' => 'Idempotency-Key header is required for this request.',
                ], 400);
            }

            return $next($request);
        }

        if (strlen($key) > 64) {
            return new JsonResponse(['code' => 'idempotency_key_invalid', 'message' => 'Idempotency-Key is too long.'], 400);
        }

        $userId = $request->user()->id;
        $where = ['user_id' => $userId, 'key' => $key];

        $fresh = $where + [
            'method' => $request->method(),
            'path' => substr($request->path(), 0, 255),
            'created_at' => now(),
        ];

        try {
            DB::table('idempotency_keys')->insert($fresh);
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('idempotency_keys')->where($where)->first();

            if ($existing !== null && ($existing->method !== $fresh['method'] || $existing->path !== $fresh['path'])) {
                return new JsonResponse(['code' => 'idempotency_key_reused', 'message' => 'This Idempotency-Key was used for a different request.'], 422);
            }

            // The first attempt died (power cut, crash) before it could finish or clean up. Its database
            // work was rolled back with it, so after a minute the retry may take the key over.
            $abandoned = $existing !== null && $existing->response_status === null
                && now()->diffInSeconds($existing->created_at, true) > self::ABANDONED_AFTER_SECONDS
                && DB::table('idempotency_keys')->where($where)->whereNull('response_status')
                    ->where('created_at', $existing->created_at)->update(['created_at' => $fresh['created_at']]) === 1;

            if ($abandoned) {
                $existing = null;
            } elseif ($existing === null || $existing->response_status === null) {
                return new JsonResponse([
                    'code' => 'request_in_progress',
                    'message' => 'This request is already being processed.',
                ], 409);
            }

            if ($existing !== null) {
                return (new JsonResponse(json_decode($existing->response_body, true), $existing->response_status))
                    ->header('Idempotency-Replayed', 'true');
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where($where)->delete();
            throw $e;
        }

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where($where)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent() ?: 'null',
            ]);
        } else {
            DB::table('idempotency_keys')->where($where)->delete();
        }

        return $response;
    }
}
