<?php

namespace App\Support;

use App\Exceptions\IncompatibleSchemaException;
use App\Exceptions\ProblemAuthorizationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 9457 application/problem+json response builder (constitution Principle V).
 */
class Problem
{
    /**
     * Build a problem+json response.
     *
     * @param  array<string, mixed>  $extensions  extra members (e.g. errors map for 422)
     */
    public static function response(int $status, string $title, ?string $detail = null, array $extensions = []): JsonResponse
    {
        $body = array_merge([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
        ], $detail !== null ? ['detail' => $detail] : [], $extensions);

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Map a framework exception to its problem+json representation.
     */
    public static function fromThrowable(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            $errors = $e->errors();
            $extensions = ['type' => ProblemType::uri(ProblemType::VALIDATION_FAILED), 'errors' => $errors];

            // Fields refused for a typed reason (spec 023 FR-006).
            $types = app(ProblemTypeCollector::class)->uris(array_keys($errors));

            if ($types !== []) {
                $extensions['error_types'] = $types;
            }

            return self::response(422, 'Validation failed', 'One or more fields are invalid.', $extensions);
        }

        // Route-model-binding misses arrive wrapped in a NotFoundHttpException
        // whose message would leak the model class — unwrap them.
        if ($e instanceof ModelNotFoundException
            || ($e instanceof HttpExceptionInterface && $e->getPrevious() instanceof ModelNotFoundException)) {
            return self::response(404, 'Not found', 'The requested resource does not exist.');
        }

        if ($e instanceof AuthenticationException) {
            return self::response(401, 'Unauthorized', 'A valid API key is required.');
        }

        // Typed refusals (spec 023): type URI and extension members, title
        // and detail unchanged. The exception handler passes authorization
        // failures wrapped in an AccessDeniedHttpException.
        $typed = $e instanceof ProblemAuthorizationException ? $e : $e->getPrevious();

        if ($typed instanceof ProblemAuthorizationException) {
            return self::response(403, 'Forbidden', $typed->getMessage(), [
                'type' => ProblemType::uri($typed->problemType),
            ] + $typed->extensions);
        }

        // Row-permission denials (spec 011 FR-011/FR-023): thrown by
        // BaseModel's write gates before any DB write happens.
        if ($e instanceof AuthorizationException) {
            $detail = $e->getMessage() !== '' ? $e->getMessage() : 'You do not have permission to perform this action.';

            return self::response(403, 'Forbidden', $detail);
        }

        if ($e instanceof QueryException && ($missing = self::missingSchemaObject($e)) !== null) {
            $e = $missing;
        }

        if ($e instanceof IncompatibleSchemaException) {
            return self::response(500, 'Incompatible ISPConfig schema', $e->getMessage(), [
                'type' => ProblemType::uri(ProblemType::INCOMPATIBLE_SCHEMA),
            ] + array_filter([
                'table' => $e->table,
                'column' => $e->column,
                'found_dbversion' => $e->foundDbversion,
                'required_dbversion' => $e->requiredDbversion,
            ], fn ($member) => $member !== null));
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';

            return self::response($status, $title, $e->getMessage() !== '' ? $e->getMessage() : null);
        }

        $detail = config('app.debug') ? $e->getMessage() : null;

        return self::response(500, 'Internal server error', $detail);
    }

    /**
     * Unknown column (42S22) or table (42S02): the ISPConfig schema is older
     * than the API. Only identifiers are lifted from the driver message and the
     * statement; the SQL itself is never copied (spec 010 FR-001).
     */
    private static function missingSchemaObject(QueryException $e): ?IncompatibleSchemaException
    {
        $state = $e->errorInfo[0] ?? null;
        $driverMessage = (string) ($e->errorInfo[2] ?? '');
        $prefix = 'The ISPConfig database schema is older than this API expects: ';
        $hint = " Run ISPConfig's updater.";

        if ($state === '42S22') {
            $column = preg_match("/Unknown column '([\w.]+)'/", $driverMessage, $m) ? $m[1] : null;
            $table = null;

            if ($column !== null && str_contains($column, '.')) {
                [$table, $column] = array_slice(explode('.', $column), -2);
            }

            $table ??= self::tableOf($e->getSql());
            $subject = match (true) {
                $table !== null && $column !== null => "table {$table} is missing column {$column}",
                $column !== null => "column {$column} is missing",
                default => 'a required column is missing',
            };

            return new IncompatibleSchemaException($prefix.$subject.'.'.$hint, $table, $column);
        }

        if ($state === '42S02') {
            $table = preg_match("/Table '(?:\w+\.)?(\w+)' doesn't exist/", $driverMessage, $m) ? $m[1] : self::tableOf($e->getSql());
            $subject = $table !== null ? "table {$table} is missing" : 'a required table is missing';

            return new IncompatibleSchemaException($prefix.$subject.'.'.$hint, $table);
        }

        return null;
    }

    private static function tableOf(string $sql): ?string
    {
        return preg_match('/\b(?:insert\s+into|update|from)\s+[`"]?(\w+)[`"]?/i', $sql, $m) ? $m[1] : null;
    }
}
