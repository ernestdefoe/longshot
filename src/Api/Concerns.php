<?php

namespace ErnestDefoe\Longshot\Api;

use ErnestDefoe\Longshot\Rejected;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

trait Concerns
{
    /** The id in the route, or 0 (not found) for one too big for an id column. */
    protected function routeId(ServerRequestInterface $request, string $name = 'id'): int
    {
        $id = (string) Arr::get($request->getAttributes(), 'routeParameters.'.$name);

        return strlen($id) <= 10 && (int) $id <= 2147483647 ? (int) $id : 0;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** A whole number from the body, or null for anything else ("12", 12.5, "1e3", true). */
    protected function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d{1,12}$/', $value) ? (int) $value : null;
    }

    /** A refusal the member can act on: 409 when prices moved, 422 otherwise. */
    protected function rejected(Rejected $e, \Flarum\Locale\TranslatorInterface $translator): JsonResponse
    {
        $params = [];
        foreach ($e->params as $key => $value) {
            $params['{'.$key.'}'] = $value;
        }

        return new JsonResponse([
            'errors' => [[
                'status' => $e->reason === 'prices' ? '409' : '422',
                'code' => 'longshot_'.$e->reason,
                'detail' => $translator->trans('ernestdefoe-longshot.api.rejected.'.$e->reason, $params),
            ]],
            'meta' => ['prices' => $e->prices],
        ], $e->reason === 'prices' ? 409 : 422);
    }

    /**
     * Which of these members the actor may see, for lists that show other
     * members: a hidden member's offers and rankings are not shown.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    protected function visibleIds(array $ids, User $actor): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if (! $ids) {
            return [];
        }

        return array_fill_keys(User::query()->whereVisibleTo($actor)->whereIn('id', $ids)->pluck('id')->all(), true);
    }
}
