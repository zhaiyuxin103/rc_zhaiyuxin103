<?php

namespace App\Actions\Outbound;

use App\Models\Outbound;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SendOutbound
{
    /**
     * Send the outbound to its external HTTP endpoint.
     */
    public function handle(Outbound $outbound): Response
    {
        $headers = $outbound->headers ?? [];

        $headers = array_filter(
            $headers,
            fn (mixed $value, string|int $name): bool => strcasecmp((string) $name, 'X-Outbound-ID') !== 0,
            ARRAY_FILTER_USE_BOTH,
        );
        $headers['X-Outbound-ID'] = $outbound->id;

        return Http::connectTimeout((int) config('services.outbound.connect_timeout', 5))
            ->timeout((int) config('services.outbound.timeout', 15))
            ->withHeaders($headers)
            ->send($outbound->http_method, $outbound->target_url, [
                'json' => $outbound->payload,
            ]);
    }
}
