<?php

namespace OzanKurt\Shield\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Waf\WafRuleResolver;

class WafRulesController extends Controller
{
    public function index(Request $request, LookupResolver $lookups)
    {
        if ($request->get('mode') === 'dataTable') {
            return $this->ajax($request, $lookups);
        }

        return view('shield::dashboard.waf-rules.index', [
            'categories' => WafRuleCategory::orderBy('sort_order')->get(),
            'kinds' => WafRuleKind::orderBy('sort_order')->get(),
            'targets' => WafRuleTarget::orderBy('sort_order')->get(),
            'actions' => WafRuleAction::orderBy('sort_order')->get(),
            'severities' => LogLevel::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, LookupResolver $lookups, AuditLogger $audit, WafRuleResolver $resolver)
    {
        $data = $this->validateRule($request, $lookups);

        $rule = WafRule::create($data + ['source' => 'user', 'version' => 1]);

        $resolver->clearCache();
        $audit->log('model.wafrule.created', "WAF rule created: {$rule->name}", [
            'subject_type' => WafRule::class,
            'subject_id' => $rule->id,
            'changes' => $rule->only(['name', 'pattern', 'category_id', 'action_id', 'severity_id']),
        ]);

        return response()->json(['ok' => true, 'id' => $rule->id]);
    }

    public function update(Request $request, int $id, LookupResolver $lookups, AuditLogger $audit, WafRuleResolver $resolver)
    {
        $rule = WafRule::findOrFail($id);

        if ($rule->source !== 'user') {
            return response()->json(['error' => 'Built-in rules cannot be edited (only toggled or disabled).'], 422);
        }

        $before = $rule->only(['name', 'pattern', 'category_id', 'action_id', 'severity_id', 'is_enabled']);
        $data = $this->validateRule($request, $lookups);
        $rule->update($data + ['version' => $rule->version + 1]);

        $resolver->clearCache();
        $audit->log('model.wafrule.updated', "WAF rule updated: {$rule->name}", [
            'subject_type' => WafRule::class,
            'subject_id' => $rule->id,
            'changes' => ['before' => $before, 'after' => $rule->only(array_keys($before))],
        ]);

        return response()->json(['ok' => true]);
    }

    public function toggle(int $id, AuditLogger $audit, WafRuleResolver $resolver)
    {
        $rule = WafRule::findOrFail($id);
        $rule->is_enabled = ! $rule->is_enabled;
        $rule->save();

        $resolver->clearCache();
        $audit->log('model.wafrule.updated', "WAF rule {$rule->name} " . ($rule->is_enabled ? 'enabled' : 'disabled'), [
            'subject_type' => WafRule::class,
            'subject_id' => $rule->id,
            'changes' => ['is_enabled' => $rule->is_enabled],
        ]);

        return response()->json(['ok' => true, 'is_enabled' => $rule->is_enabled]);
    }

    public function destroy(int $id, AuditLogger $audit, WafRuleResolver $resolver)
    {
        $rule = WafRule::findOrFail($id);

        if ($rule->source !== 'user') {
            return response()->json(['error' => 'Built-in rules cannot be deleted.'], 422);
        }

        $rule->delete();
        $resolver->clearCache();

        $audit->log('model.wafrule.deleted', "WAF rule deleted: {$rule->name}", [
            'subject_type' => WafRule::class,
            'subject_id' => $rule->id,
        ]);

        return response()->json(['ok' => true]);
    }

    private function ajax(Request $request, LookupResolver $lookups)
    {
        $query = WafRule::query();
        $total = $query->count();

        if ($search = $request->input('search.value')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('pattern', 'like', "%{$search}%");
            });
        }

        $filtered = $query->count();
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);

        $rows = $query->orderBy('id')->offset($start)->limit($length)->get()->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'source' => $r->source,
            'category' => $lookups->name(WafRuleCategory::class, $r->category_id),
            'kind' => $lookups->name(WafRuleKind::class, $r->kind_id),
            'target' => $lookups->name(WafRuleTarget::class, $r->target_id),
            'action' => $lookups->name(WafRuleAction::class, $r->action_id),
            'severity' => $lookups->name(LogLevel::class, $r->severity_id),
            'enabled' => $r->is_enabled,
            'pattern' => mb_substr((string) $r->pattern, 0, 200),
            'is_user' => $r->source === 'user',
        ]);

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
        ]);
    }

    private function validateRule(Request $request, LookupResolver $lookups): array
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string',
            'kind' => 'required|string',
            'target' => 'required|string',
            'pattern' => 'required|string',
            'action' => 'required|string',
            'severity' => 'required|string',
            'score' => 'integer|min:0',
            'is_enabled' => 'boolean',
        ])->validate();

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category_id' => $lookups->id(WafRuleCategory::class, $validated['category']),
            'kind_id' => $lookups->id(WafRuleKind::class, $validated['kind']),
            'target_id' => $lookups->id(WafRuleTarget::class, $validated['target']),
            'pattern' => $validated['pattern'],
            'action_id' => $lookups->id(WafRuleAction::class, $validated['action']),
            'severity_id' => $lookups->id(LogLevel::class, $validated['severity']),
            'score' => $validated['score'] ?? 0,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ];
    }
}
