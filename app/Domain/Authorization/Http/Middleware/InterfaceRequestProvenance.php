<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Http\Middleware;

use Illuminate\Http\Request;
use stdClass;
use WeakMap;

final class InterfaceRequestProvenance
{
    private const TOKEN_ATTRIBUTE = '__most_interface_provenance';

    /**
     * @var WeakMap<object, array{depth: positive-int, client_supplied: bool}>|null
     */
    private static ?WeakMap $states = null;

    public static function enter(Request $request, bool $clientSupplied): object
    {
        $states = self::states();
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);

        if (is_object($token) && isset($states[$token])) {
            $state = $states[$token];
            $state['depth']++;
            $states[$token] = $state;

            return $token;
        }

        $token = new stdClass;
        $states[$token] = [
            'depth' => 1,
            'client_supplied' => $clientSupplied,
        ];
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        return $token;
    }

    public static function isTrustedServerDerived(Request $request): bool
    {
        $states = self::states();
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);

        return is_object($token)
            && isset($states[$token])
            && $states[$token]['client_supplied'] === false;
    }

    public static function leave(Request $request, object $token): void
    {
        $states = self::states();

        if (! isset($states[$token])) {
            return;
        }

        $state = $states[$token];
        if ($state['depth'] === 1) {
            unset($states[$token]);
            if ($request->attributes->get(self::TOKEN_ATTRIBUTE) === $token) {
                $request->attributes->remove(self::TOKEN_ATTRIBUTE);
            }

            return;
        }

        $state['depth']--;
        $states[$token] = $state;
    }

    /**
     * @return WeakMap<object, array{depth: positive-int, client_supplied: bool}>
     */
    private static function states(): WeakMap
    {
        return self::$states ??= new WeakMap;
    }
}
