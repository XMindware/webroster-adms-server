<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Priority order for locale detection:
        // 1. URL query parameter (?lang=xx)
        // 2. Session stored preference
        // 3. Browser Accept-Language header
        // 4. Default from config
        
        $locale = null;
        
        // Check if locale is provided in URL
        if ($request->has('lang')) {
            $locale = $request->input('lang');
            if (in_array($locale, config('app.available_locales'))) {
                Session::put('locale', $locale);
            }
        }
        
        // Check session
        if (!$locale && Session::has('locale')) {
            $locale = Session::get('locale');
        }
        
        // Check browser Accept-Language header
        if (!$locale) {
            $browserLang = $request->getPreferredLanguage(config('app.available_locales'));
            if ($browserLang) {
                $locale = $browserLang;
            }
        }
        
        // Fallback to default locale
        if (!$locale || !in_array($locale, config('app.available_locales'))) {
            $locale = config('app.locale');
        }
        
        // Set the application locale
        App::setLocale($locale);
        
        return $next($request);
    }
}
