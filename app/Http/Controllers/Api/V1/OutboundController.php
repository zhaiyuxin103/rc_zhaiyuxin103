<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CreateOutbound;
use App\Actions\Outbound\ReplayOutbound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOutboundRequest;
use App\Http\Resources\Api\V1\OutboundResource;
use App\Models\Outbound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Jiannei\Response\Laravel\Support\Facades\Response;

class OutboundController extends Controller
{
    /**
     * Accept a new outbound request.
     */
    public function store(StoreOutboundRequest $request, CreateOutbound $createOutbound): JsonResponse
    {
        $outbound = $createOutbound->handle($request->user(), $request->outboundAttributes());

        return Response::accepted(
            new OutboundResource($outbound),
            'The outbound has been accepted.',
            route('api.v1.outbounds.show', $outbound),
        );
    }

    /**
     * Show the current outbound status.
     */
    public function show(Request $request, Outbound $outbound): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $outbound);

        return Response::success(new OutboundResource($outbound));
    }

    /**
     * Replay a failed outbound.
     */
    public function replay(Request $request, Outbound $outbound, ReplayOutbound $replayOutbound): JsonResponse
    {
        Gate::forUser($request->user())->authorize('replay', $outbound);

        $outbound = $replayOutbound->handle($outbound);

        return Response::accepted(
            new OutboundResource($outbound),
            'The outbound has been queued for replay.',
            route('api.v1.outbounds.show', $outbound),
        );
    }
}
