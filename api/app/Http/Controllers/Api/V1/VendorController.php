<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorHintResource;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use App\Models\VendorHint;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $q = Vendor::query()->withCount('documents', 'hints')->orderBy('name');
        if ($s = $request->input('filter.q')) {
            $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', ['%'.mb_strtolower($s).'%'])->orWhere('tax_key', Vendor::taxKey($s) ?? '-'));
        }

        return VendorResource::collection($q->paginate((int) $request->input('page.size', 50), ['*'], 'page[number]', (int) $request->input('page.number', 1)));
    }

    public function store(Request $request)
    {
        $vendor = Vendor::create($this->validated($request));

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor->update($this->validated($request, $vendor));

        return new VendorResource($vendor);
    }

    /** Vendor memory: hints learned from reviewer corrections. */
    public function hints(Vendor $vendor)
    {
        return VendorHintResource::collection($vendor->hints()->with('author:id,name')->get());
    }

    public function destroyHint(Vendor $vendor, VendorHint $hint)
    {
        abort_unless($hint->vendor_id === $vendor->id, 404);
        $hint->delete();

        return response()->noContent();
    }

    public function destroy(Vendor $vendor)
    {
        $vendor->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        $company = $request->user()->company_id;
        $data = $request->validate([
            'name' => [$vendor ? 'sometimes' : 'required', 'string', 'max:255'],
            'tax_id' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:255',
            'default_currency' => 'nullable|string|size:3|alpha',
            'erp_ref' => 'nullable|string|max:120',
            'aliases' => 'sometimes|array|max:50',
            'aliases.*' => 'string|max:255',
        ]);
        if (isset($data['name'])) {
            $exists = Vendor::where('normalized_name', Vendor::normalize($data['name']))
                ->when($vendor, fn ($q) => $q->where('id', '!=', $vendor->id))->exists();
            if ($exists) {
                throw ValidationException::withMessages(['name' => 'A vendor with this name already exists.']);
            }
        }
        if (isset($data['default_currency'])) {
            $data['default_currency'] = strtoupper($data['default_currency']);
        }

        return $data;
    }
}
