<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $this->renderable(function (NotFoundHttpException $e, $request) {
            // S3: never log the request body; a clock posting templates to an
            // unknown path would otherwise write them to the log.
            Log::error('404 Not Found', \App\Support\DeviceRequestMetadata::fromRequest($request) + [
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        });
    }

    public function report(Throwable $exception)
    {
        // Check if the exception is a 404 error (route not found)
        if ($exception instanceof NotFoundHttpException) {
            $request = request();
            Log::channel('404_errors')->warning('404 Not Found', [
                'url' => $request->url(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'time' => now(),
            ]);
        }

        parent::report($exception);
    }
}