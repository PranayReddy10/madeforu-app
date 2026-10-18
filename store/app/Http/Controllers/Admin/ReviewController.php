<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index()
    {
        return view('admin.reviews.index', ['reviews' => Review::with('product', 'order')->orderBy('is_approved')->latest()->paginate(30)]);
    }

    public function update(Request $request, Review $review)
    {
        $review->update(['is_approved' => $request->boolean('is_approved')]);

        return back()->with('status', $review->is_approved ? 'Review approved.' : 'Review hidden.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return back()->with('status', 'Review deleted.');
    }
}
