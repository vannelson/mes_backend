<?php

namespace App\Http\Middleware;

use App\Services\Translation\LocaleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiLocale
{
    public function __construct(
        protected LocaleManager $localeManager
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->localeManager->resolveRequestLocale($request);

        $request->attributes->set('locale', $locale);
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $this->localeManager->htmlLang($locale));
        $response->headers->set('Vary', 'Accept-Language, X-MES-Locale, Cookie');

        return $response;
    }
}
