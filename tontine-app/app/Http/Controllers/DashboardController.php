<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Refund;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $memberships = $user->tontineMemberships()->with('tontine.product')->latest()->get();

        $pendingContributionsCount = Contribution::whereHas(
            'tontineMember',
            fn ($q) => $q->where('user_id', $user->id)
        )->where('status', 'pending')->count();

        $unreadNotificationsCount = $user->unreadNotifications()->count();

        $pendingRefunds = Refund::where('user_id', $user->id)->where('status', 'pending')->get();

        return view('dashboard.index', compact(
            'memberships', 'pendingContributionsCount', 'unreadNotificationsCount', 'pendingRefunds'
        ));
    }
}
