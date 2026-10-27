<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Event;
use App\Models\Zone;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CategoriesController
{
    public function index(): Response
    {
        Gate::authorize('categories.gerer');
        $categories = Category::with(['event:id,name', 'zones:id,name'])->orderBy('name')->get()->map(fn (Category $c) => ['id' => $c->id, 'event_id' => $c->event_id, 'event_name' => $c->event->name, 'name' => $c->name, 'color' => $c->color, 'priority_circuit' => $c->priority_circuit, 'zone_ids' => $c->zones->pluck('id'), 'zone_names' => $c->zones->pluck('name')]);

        return Inertia::render('Admin/Categories', ['events' => Event::orderBy('name')->get(['id', 'name']), 'zones' => Zone::orderBy('name')->get(['id', 'name', 'event_id']), 'categories' => $categories]);
    }

    /** @return array{event_id:string,name:string,color:string,priority_circuit:bool,zone_ids:list<string>} */
    private function validated(Request $r, ?Category $category = null): array
    {
        $event = $category ? $category->event_id : $r->input('event_id');

        $r->validate(['event_id' => ['bail', 'required', 'uuid', $category ? Rule::in([$event]) : Rule::exists('events', 'id')]]);
        $data = $r->validate(['event_id' => ['required', 'uuid', $category ? Rule::in([$event]) : Rule::exists('events', 'id')], 'name' => ['required', 'string', 'max:120', Rule::unique('categories')->where('event_id', $event)->ignore($category?->id)], 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'priority_circuit' => 'required|boolean', 'zone_ids' => 'present|array', 'zone_ids.*' => ['bail', 'required', 'uuid', 'distinct', Rule::exists('zones', 'id')->where('event_id', $event)]]);

        return [...$data, 'priority_circuit' => $r->boolean('priority_circuit'), 'zone_ids' => array_values($data['zone_ids'])];
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $this->validated($r);
        DB::transaction(function () use ($data): void {
            $category = Category::create(collect($data)->except('zone_ids')->all());
            $this->syncZones($category, $data['zone_ids']);
            app(Audit::class)->append('categorie.creee', auth()->id(), ['id' => $category->id, 'event_id' => $category->event_id, 'fields' => ['name', 'color', 'priority_circuit', 'zone_ids']]);
        });

        return back();
    }

    public function update(Request $r, Category $category): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $this->validated($r, $category);
        DB::transaction(function () use ($category, $data): void {
            $before = $category->zones()->pluck('zones.id')->sort()->values()->all();
            $category->fill(collect($data)->except('zone_ids')->all());
            $fields = array_keys($category->getDirty());
            if ($before !== collect($data['zone_ids'])->sort()->values()->all()) {
                $fields[] = 'zone_ids';
            } if (! $fields) {
                return;
            } $category->save();
            $this->syncZones($category, $data['zone_ids']);
            app(Audit::class)->append('categorie.modifiee', auth()->id(), ['id' => $category->id, 'event_id' => $category->event_id, 'fields' => $fields]);
        });

        return back();
    }

    /** @param list<string> $ids */
    private function syncZones(Category $category, array $ids): void
    {
        $existing = DB::table('category_zone')->where('category_id', $category->id)->pluck('id', 'zone_id');
        $pivot = [];
        foreach ($ids as $id) {
            $pivot[$id] = ['id' => $existing[$id] ?? (string) Str::uuid(), 'event_id' => $category->event_id];
        }
        $category->zones()->sync($pivot);
    }
}
