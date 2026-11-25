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
        if ($request->is('api/v1/onboarding')) {
            return $next($request);
        }

        $connection = DB::connection();
        $initialTransactionLevel = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $tenantId = $this->resolveTenantId($request);

            if ($tenantId === null) {
                $connection->rollBack();

                return response()->json(['message' => 'The requested organization was not found.'], 404);
            }

            $connection->select(
                "select set_config('app.tenant_id', ?, true)",
                [(string) $tenantId],
            );
            $request->attributes->set('tenant_id', $tenantId);

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

    private function resolveTenantId(Request $request): ?int
    {
        if (preg_match('#^api/v1/telegram/webhook/([1-9][0-9]*)$#D', $request->path(), $matches) === 1) {
            return (int) $matches[1];
        }

        $tenantId = $request->header('X-Tenant');

        if (is_string($tenantId) && ctype_digit($tenantId) && (int) $tenantId > 0) {
            return (int) $tenantId;
        }

        if ($tenantId !== null) {
            abort(400, 'A valid tenant identifier is required.');
        }

        $slug = $request->header('X-Tenant-Slug');

        if (! is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            abort(400, 'A valid X-Tenant-Slug header is required.');
        }

        $tenantId = DB::table('tenant_slug_directory')
            ->where('slug', $slug)
            ->value('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
