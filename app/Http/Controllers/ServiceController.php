<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $services = Service::with('serviceOptions')->get();

        $mappedServices = $services->map(function ($service) {
            $data = $service->toArray();
            $data['options'] = $service->serviceOptions->map(function ($option) {
                return [
                    'id' => $option->id,
                    'option_name' => $option->option_name,
                    'option_type' => $option->option_type,
                    'option_price' => $option->option_price,
                    'choices' => $option->choices,
                    'is_required' => $option->is_required,
                ];
            })->toArray();
            unset($data['service_options']);
            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => $mappedServices,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ServiceRequest $request)
    {
        $service = Service::create($request->validated());
        return response()->json($service, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $service = Service::with('serviceOptions')->findOrFail($id);

        $data = $service->toArray();
        $data['options'] = $service->serviceOptions->map(function ($option) {
            return [
                'id' => $option->id,
                'option_name' => $option->option_name,
                'option_type' => $option->option_type,
                'option_price' => $option->option_price,
                'choices' => $option->choices,
                'is_required' => $option->is_required,
            ];
        })->toArray();
        unset($data['service_options']);

        return response()->json($data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $service = Service::findOrFail($id);
        return view('services.edit', compact('service'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceRequest $request, Service $service)
    {
        $validatedData = $request->validated();

        $service->update([
            'name' => $request['name'],
            'description' => $request['description'] ?? "",
            'price' => $request['price'],
        ]);
        return response()->json($service);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service)
    {
        $service->delete();
        return response()->json(['message' => 'Service deleted successfully']);
    }
}
