<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SetPostgresTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant');

        if (! is_string($tenantId) || ! ctype_digit($tenantId) || (int) $tenantId < 1) {
            return response()->json(['message' => 'A valid X-Tenant header is required.'], 400);
        }

        $connection = DB::connection();
        $initialTransactionLevel = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $connection->select(
                "select set_config('app.tenant_id', ?, true)",
                [(string) (int) $tenantId],
            );

            $response = $next($request);

            if ($response->getStatusCode() < 400) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }

            return $response;
        } catch (Throwable $exception) {
            while ($connection->transactionLevel() > $initialTransactionLevel) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }
}
