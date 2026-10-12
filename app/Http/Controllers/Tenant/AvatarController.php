<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AvatarController extends Controller
{
    public function edit(Request $request): View
    {
        return view('tenant.profile.edit', ['user' => $request->user()]);
    }

    public function update(UpdateAvatarRequest $request): RedirectResponse
    {
        $media = $request->user()->addMediaFromRequest('avatar')->toMediaCollection('avatar');

        Log::channel('tenancy')->info('Avatar uploaded', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('profile')->with('status', 'Avatar updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->clearMediaCollection('avatar');

        Log::channel('tenancy')->info('Avatar removed', [...tenant()->logContext(), 'user_id' => $request->user()->id]);

        return redirect()->route('profile')->with('status', 'Avatar removed.');
    }
}
