<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Http;

use Illuminate\Http\Request;
use WeiJuKeJi\LaravelEkpOrgSync\Models\Source;
use WeiJuKeJi\LaravelEkpOrgSync\Models\SyncRun;

class SyncController
{
    public function store(Request $request, int $source)
    {
        $input = $request->validate(['mode' => 'required|in:full,incremental']);
        $changed = Source::query()->whereKey($source)->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->update(['requested_mode' => $input['mode'], 'next_sync_at' => now()]);
        abort_if($changed !== 1, 409, '来源正在同步或未启用，请稍后重试');

        return response()->json(['code' => 202, 'data' => ['source_id' => $source, 'mode' => $input['mode'], 'status' => 'scheduled'], 'message' => '同步请求已登记'], 202);
    }

    public function index()
    {
        return response()->json(['code' => 200, 'data' => Source::query()->with('directory')->get(), 'message' => 'success']);
    }

    public function runs(Request $request)
    {
        $input = $request->validate(['source_id' => 'nullable|integer', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = SyncRun::query();
        if (isset($input['source_id'])) {
            $query->where('source_id', $input['source_id']);
        }

        return response()->json(['code' => 200, 'data' => $query->orderByDesc('started_at')->paginate($input['per_page'] ?? 20), 'message' => 'success']);
    }
}
