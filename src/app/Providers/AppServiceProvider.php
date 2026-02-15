<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\DocumentConvert\DocumentConvertService;
use App\Services\DocumentConvert\DocxToHtmlConverter;
use App\Services\DocumentConvert\PdfToHtmlConverter;
use App\Services\DocumentConvert\HtmlSanitizer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HtmlSanitizer::class);
        
        $this->app->singleton(DocxToHtmlConverter::class, function ($app) {
            return new DocxToHtmlConverter($app->make(HtmlSanitizer::class));
        });
        
        $this->app->singleton(PdfToHtmlConverter::class, function ($app) {
            return new PdfToHtmlConverter($app->make(HtmlSanitizer::class));
        });
        
        $this->app->singleton(DocumentConvertService::class, function ($app) {
            return new DocumentConvertService(
                $app->make(DocxToHtmlConverter::class),
                $app->make(PdfToHtmlConverter::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
