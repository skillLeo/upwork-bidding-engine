<?php

namespace App\Http\Controllers;

use App\Models\SavedFilter;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SavedFilterController extends Controller
{
    public function index(): JsonResponse
    {
        $filters = SavedFilter::query()
            ->orderByDesc('is_pinned')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $filters]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $filter = DB::transaction(function () use ($validated) {
            if ($validated['is_default'] ?? false) {
                SavedFilter::query()->update(['is_default' => false]);
            }

            return SavedFilter::create($validated);
        });

        return response()->json(['data' => $filter], 201);
    }

    public function update(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        $validated = $this->validated($request, $savedFilter->id);

        DB::transaction(function () use ($validated, $savedFilter) {
            if ($validated['is_default'] ?? false) {
                SavedFilter::query()->where('id', '!=', $savedFilter->id)->update(['is_default' => false]);
            }

            $savedFilter->update($validated);
        });

        return response()->json(['data' => $savedFilter->fresh()]);
    }

    public function destroy(SavedFilter $savedFilter): JsonResponse
    {
        $savedFilter->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                // Scoped to the workspace. Rule::unique builds its own query
                // and so never sees the model's global tenant scope, which
                // made saved-filter names unique across the WHOLE platform:
                // one workspace naming a filter "Laravel" made that name
                // permanently unavailable to every other workspace, with a
                // 422 reading "The name has already been taken" about a
                // filter its owner could not see and had never created.
                //
                // Filters are one of the four things each workspace owns
                // outright, so two workspaces are perfectly entitled to the
                // same name.
                Rule::unique('saved_filters', 'name')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($ignoreId),
            ],
            'is_default' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            // `present` (not `required`) - an empty criteria array is a
            // legitimate "show everything" filter, not a missing field.
            //
            // Deliberately not validating criteria's internal shape field
            // by field: it's an internal, frontend-controlled JSON blob
            // consumed defensively by LeadController's filtering (missing
            // keys are just treated as "no filter on this dimension"), not
            // raw user input. Adding criteria.* nested rules here causes a
            // real Laravel quirk: ->validated() reconstructs the parent
            // key from its children and drops it entirely when the client
            // sends an empty criteria object with no matching nested keys.
            'criteria' => ['present', 'array'],
        ]);
    }
}
