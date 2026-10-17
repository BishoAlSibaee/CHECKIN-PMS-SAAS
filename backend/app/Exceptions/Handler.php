<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            $apiMessage = $this->resolveApiErrorMessage($e);
            if ($apiMessage !== null) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 503;

                return response()->json([
                    'success' => false,
                    'message' => $apiMessage,
                    'code' => $status,
                    'data' => null,
                ], $status);
            }
        }

        return parent::render($request, $e);
    }

    private function resolveApiErrorMessage(Throwable $e): ?string
    {
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            return 'PHP extensions pdo and pdo_mysql are disabled on the server. Enable them in cPanel → Select PHP Version for the API subdomain, then retry login.';
        }

        $message = $e->getMessage();

        if ($this->isDatabaseConnectivityError($e, $message)) {
            Log::error('API database connectivity failure', ['exception' => $e]);

            return config('app.debug')
                ? $message
                : 'Database connection failed. Ensure MySQL is running and run: php artisan migrate --force && php artisan passport:keys && php artisan hotel:ensure-admin';
        }

        return null;
    }

    private function isDatabaseConnectivityError(Throwable $e, string $message): bool
    {
        if ($e instanceof \PDOException) {
            return true;
        }

        $needles = [
            'could not find driver',
            'PDOException',
            'Connection refused',
            'No connection could be made',
            'Access denied for user',
            'Unknown database',
            'SQLSTATE[HY000]',
            'SQLSTATE[2002]',
            'SQLSTATE[1045]',
            'SQLSTATE[1049]',
        ];

        foreach ($needles as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable) {
            return $this->isDatabaseConnectivityError($previous, $previous->getMessage());
        }

        return false;
    }
}
