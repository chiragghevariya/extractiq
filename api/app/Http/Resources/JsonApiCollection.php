<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Collection with JSON:API-style pagination: meta.page{current,size,total,last} + links{first,last,prev,next}. */
class JsonApiCollection extends AnonymousResourceCollection
{
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'meta' => ['page' => [
                'current' => $paginated['current_page'],
                'size' => (int) $paginated['per_page'],
                'total' => $paginated['total'],
                'last' => $paginated['last_page'],
            ]],
            'links' => [
                'first' => $paginated['first_page_url'],
                'last' => $paginated['last_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
            ],
        ];
    }
}
