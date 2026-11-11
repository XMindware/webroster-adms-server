<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Oficina;
use Illuminate\Http\Request;

/**
 * Resolve the office an operator picked in a dropdown.
 *
 * `oficinas.id` is the only key that identifies one office: `idoficina` is an
 * office number that repeats across empresas, so the old pair
 * (selectedOficina + idempresa) had to be carried around by every form as a
 * hidden field kept in sync by JavaScript — and a single stale or missing
 * hidden field silently selected the wrong customer's office.
 *
 * Forms now post `oficina_id` alone. The legacy pair is still honoured so old
 * links, bookmarks and any form that has not been converted keep working.
 *
 * Uses input() rather than query(): it reads the query string on a GET and the
 * body on a POST, so the same helper serves both the filter dropdowns and the
 * create/edit forms.
 */
trait ResolvesSelectedOficina
{
    /**
     * @param string $legacyKey the field older forms used for the office number
     *   (idoficina). Different screens called it selectedOficina, oficina or
     *   idoficina, so the caller names its own.
     */
    protected function resolveSelectedOficina(Request $request, string $legacyKey = 'selectedOficina'): ?Oficina
    {
        if ($request->filled('oficina_id')) {
            return Oficina::find((int) $request->input('oficina_id'));
        }

        if ($request->filled($legacyKey)) {
            return Oficina::where('idoficina', $request->input($legacyKey))
                ->when(
                    $request->filled('idempresa'),
                    fn ($q) => $q->where('idempresa', $request->input('idempresa'))
                )
                ->first();
        }

        return null;
    }
}
