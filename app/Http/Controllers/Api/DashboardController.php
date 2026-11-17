<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function landlord(Request $request)
    {
        $user = $request->user();
        $period = BusinessTime::period();
        $isAdmin = $user->isAdmin();

        $rooms = Room::query()->when(! $isAdmin, fn ($query) => $query->where('landlord_id', $user->id));
        $leases = Lease::query()->when(! $isAdmin, fn ($query) => $query->where('landlord_id', $user->id));
        $inquiries = Inquiry::query()->whereIn('status', [Inquiry::PENDING, Inquiry::APPROVED])
            ->when(! $isAdmin, fn ($query) => $query->whereHas('room', fn ($roomsQuery) => $roomsQuery->where('landlord_id', $user->id)));
        $invoices = Invoice::query()->where('billing_period', $period)
            ->when(! $isAdmin, fn ($query) => $query->whereHas('lease', fn ($leasesQuery) => $leasesQuery->where('landlord_id', $user->id)));

        $totalRooms = (clone $rooms)->count();
        $occupied = (clone $rooms)->where('status', 'occupied')->count();
        $available = (clone $rooms)->where('status', 'available')->count();
        $reserved = (clone $rooms)->where('status', 'reserved')->count();
        $maintenance = (clone $rooms)->where('status', 'maintenance')->count();
        $occupancy = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100) : 0;
        $pendingInquiries = (clone $inquiries)->where('status', Inquiry::PENDING)->count();
        $openOffers = (clone $inquiries)->where('status', Inquiry::APPROVED)->where('offer_expires_at', '>', now())->count();
        $monthlyRevenue = (clone $invoices)->whereIn('status', [Invoice::PAID, Invoice::PARTIAL])->sum('amount_paid');
        $tenantCount = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::TENANT))
            ->when(! $isAdmin, fn ($query) => $query->whereHas('tenantLeases', fn ($leaseQuery) => $leaseQuery->where('landlord_id', $user->id)))
            ->count();

        // Overdue balance for this landlord's leases
        $overdueQuery = Invoice::query()
            ->when(! $isAdmin, fn ($query) => $query->whereHas('lease', fn ($leasesQuery) => $leasesQuery->where('landlord_id', $user->id)))
            ->where(function ($q) {
                $q->where('status', Invoice::OVERDUE)
                  ->orWhere(fn ($sub) => $sub->where('status', Invoice::UNPAID)->where('due_date', '<', BusinessTime::date()));
            });
        $overdueCount = (clone $overdueQuery)->count();
        $overdueAmount = (clone $overdueQuery)->get()->sum(fn (Invoice $invoice) => (float) $invoice->remainingBalance());

        return response()->json([
            'occupancy' => [
                'percent' => $occupancy,
                'occupied' => $occupied,
                'total' => $totalRooms,
            ],
            'available_rooms' => $available,
            'reserved_rooms' => $reserved,
            'maintenance_rooms' => $maintenance,
            'tenant_count' => $tenantCount,
            'pending_inquiries' => $pendingInquiries,
            'open_offers' => $openOffers,
            'monthly_revenue' => number_format((float) $monthlyRevenue, 2, '.', ''),
            'overdue_invoices_count' => $overdueCount,
            'overdue_amount' => number_format((float) $overdueAmount, 2, '.', ''),
            'active_leases' => $leases->where('status', 'active')->count(),
        ]);
    }

    public function admin(Request $request)
    {
        $period = BusinessTime::period();

        // Total users by role
        $totalUsers = User::count();
        $rolesCount = User::join('roles', 'users.role_id', '=', 'roles.id')
            ->selectRaw('roles.name, count(*) as count')
            ->groupBy('roles.name')
            ->pluck('count', 'name')
            ->toArray();

        // Total rooms and status breakdown
        $totalRooms = Room::count();
        $roomsByStatus = Room::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $roomsByType = Room::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $avgPrice = Room::avg('price') ?: 0;
        $minPrice = Room::min('price') ?: 0;
        $maxPrice = Room::max('price') ?: 0;

        $occupied = $roomsByStatus['occupied'] ?? 0;
        $available = $roomsByStatus['available'] ?? 0;
        $reserved = $roomsByStatus['reserved'] ?? 0;
        $maintenance = $roomsByStatus['maintenance'] ?? 0;
        $occupancyPercent = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100) : 0;

        // Financial metrics
        $invoicesThisMonth = Invoice::where('billing_period', $period)->get();
        $totalBilledThisMonth = $invoicesThisMonth->sum('total_amount_due');
        $totalCollectedThisMonth = $invoicesThisMonth->sum('amount_paid');
        $overdueInvoices = Invoice::where(function ($q) {
            $q->where('status', Invoice::OVERDUE)->orWhere(function ($sub) {
                $sub->where('status', Invoice::UNPAID)->where('due_date', '<', BusinessTime::date());
            });
        })->get();
        $totalOverdueAmount = $overdueInvoices->sum(fn (Invoice $invoice) => (float) $invoice->remainingBalance());

        // Active Leases
        $activeLeasesCount = Lease::where('status', 'active')->count();

        // Inquiries breakdown
        $pendingInquiriesCount = Inquiry::where('status', Inquiry::PENDING)->count();
        $openOffersCount = Inquiry::where('status', Inquiry::APPROVED)->where('offer_expires_at', '>', now())->count();

        // Past 7 days activity (for engagement bar chart)
        $past7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $businessDay = BusinessTime::today()->subDays($i);
            $date = $businessDay->toDateString();
            $dayName = $businessDay->format('D');
            $utcStart = $businessDay->utc();
            $utcEnd = $businessDay->addDay()->utc();
            $inquiriesCount = Inquiry::where('created_at', '>=', $utcStart)->where('created_at', '<', $utcEnd)->count();
            $paymentsCount = \App\Models\Payment::where('paid_at', '>=', $utcStart)->where('paid_at', '<', $utcEnd)->count();
            $newUsersCount = User::where('created_at', '>=', $utcStart)->where('created_at', '<', $utcEnd)->count();
            $past7Days[] = [
                'date' => $date,
                'day' => $dayName,
                'inquiries' => $inquiriesCount,
                'payments' => $paymentsCount,
                'users' => $newUsersCount,
            ];
        }

        // Recent users (latest 6)
        $recentUsers = User::with('role')->latest()->take(6)->get()->map(fn ($u) => $u->toAuthArray());

        // Recent activity logs (latest 6)
        $recentActivities = \App\Models\ActivityLog::with('user.role')->latest()->take(6)->get();

        return response()->json([
            'users' => [
                'total' => $totalUsers,
                'tenants' => $rolesCount['tenant'] ?? 0,
                'landlords' => $rolesCount['landlord'] ?? 0,
                'staff' => $rolesCount['staff'] ?? 0,
                'admins' => $rolesCount['admin'] ?? 0,
            ],
            'rooms' => [
                'total' => $totalRooms,
                'occupied' => $occupied,
                'available' => $available,
                'reserved' => $reserved,
                'maintenance' => $maintenance,
                'occupancy_percent' => $occupancyPercent,
                'avg_price' => number_format((float) $avgPrice, 2, '.', ''),
                'min_price' => number_format((float) $minPrice, 2, '.', ''),
                'max_price' => number_format((float) $maxPrice, 2, '.', ''),
                'by_type' => $roomsByType,
            ],
            'finance' => [
                'monthly_billed' => number_format((float) $totalBilledThisMonth, 2, '.', ''),
                'monthly_collected' => number_format((float) $totalCollectedThisMonth, 2, '.', ''),
                'overdue_count' => $overdueInvoices->count(),
                'overdue_amount' => number_format((float) $totalOverdueAmount, 2, '.', ''),
            ],
            'operations' => [
                'active_leases' => $activeLeasesCount,
                'pending_inquiries' => $pendingInquiriesCount,
                'open_offers' => $openOffersCount,
            ],
            'weekly_activity' => $past7Days,
            'recent_users' => $recentUsers,
            'recent_activities' => $recentActivities,
        ]);
    }
}
