<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Models\User;

/**
 * Where a user goes after signing in.
 *
 * Not everyone can open the dashboard. An operator holds exactly four permissions and belongs
 * on the shop-floor terminal; a driver belongs on their trip list. Sending every role to
 * `/dashboard` authenticates them successfully and then drops them on a 403 with no way back
 * — a lockout dressed up as a permission check.
 *
 * The first entry whose permission the user holds wins, so the order is the priority order.
 */
final class LandingPage
{
    /** @var list<array{permission: string, path: string}> */
    private const CANDIDATES = [
        ['permission' => 'report.dashboard', 'path' => '/dashboard'],
        ['permission' => 'operation.start', 'path' => '/floor'],
        ['permission' => 'trip.view_own', 'path' => '/trips'],
        ['permission' => 'artwork.view_any', 'path' => '/artworks'],
        ['permission' => 'job_card.view_any', 'path' => '/job-cards'],
        ['permission' => 'sales_order.view_any', 'path' => '/sales-orders'],
        ['permission' => 'stock_lot.view_any', 'path' => '/stock'],
        ['permission' => 'qc_inspection.view_any', 'path' => '/qc-inspections'],
        ['permission' => 'product.view_any', 'path' => '/products'],
        ['permission' => 'customer.view_any', 'path' => '/customers'],
        ['permission' => 'item.view_any', 'path' => '/items'],
        ['permission' => 'user.view_any', 'path' => '/admin/users'],
    ];

    public static function for(?User $user): string
    {
        if ($user === null) {
            return '/login';
        }

        foreach (self::CANDIDATES as $candidate) {
            if ($user->hasPermission($candidate['permission'])) {
                return $candidate['path'];
            }
        }

        // A user with no permission at all is a configuration mistake, not a route to guess
        // at. The floor terminal has its own device login and will say so plainly.
        return '/floor';
    }
}
