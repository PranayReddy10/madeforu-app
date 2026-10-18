<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RedirectController extends Controller
{
    public function index(Request $request)
    {
        $q = Redirect::orderByDesc('hits')->orderBy('from_path');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('from_path', 'like', "%{$s}%")->orWhere('to_path', 'like', "%{$s}%"));
        }

        return view('admin.redirects.index', ['redirects' => $q->paginate(50)->withQueryString()]);
    }

    public function create()
    {
        return view('admin.redirects.form', ['redirect' => new Redirect(['status' => 301])]);
    }

    public function store(Request $request)
    {
        Redirect::create($this->data($request, new Redirect));

        return redirect()->route('admin.redirects.index')->with('status', 'Redirect created.');
    }

    public function edit(Redirect $redirect)
    {
        return view('admin.redirects.form', compact('redirect'));
    }

    public function update(Request $request, Redirect $redirect)
    {
        $redirect->update($this->data($request, $redirect));

        return redirect()->route('admin.redirects.index')->with('status', 'Redirect saved.');
    }

    public function destroy(Redirect $redirect)
    {
        $redirect->delete();

        return redirect()->route('admin.redirects.index')->with('status', 'Redirect deleted.');
    }

    private function data(Request $request, Redirect $redirect): array
    {
        $from = (string) $request->input('from_path');
        if (preg_match('#^https?://#', $from)) {
            $from = (string) parse_url($from, PHP_URL_PATH);
        }
        $request->merge(['from_path' => Redirect::normalise($from)]);
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:255', 'not_in:/', Rule::unique('redirects', 'from_path')->ignore($redirect->id)],
            'to_path' => 'required|string|max:255|different:from_path',
            'status' => 'required|in:301,302',
        ]);

        return $data;
    }
}
