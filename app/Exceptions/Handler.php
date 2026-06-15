<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        if (
            $e instanceof \Symfony\Component\ErrorHandler\Error\FatalError
            || str_contains($e->getMessage(), 'Maximum execution time')
        ) {
            return response()->view('errors.timeout', [
                'message' => 'The document is taking longer than expected to process. Please try again in a moment.',
            ], 504);
        }

        return parent::render($request, $e);
    }

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
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
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
