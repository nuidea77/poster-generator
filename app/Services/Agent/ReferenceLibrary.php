<?php

namespace App\Services\Agent;

use App\Models\StyleReference;

/**
 * Агентын хэв маягийн жишээ сан: админы сонгосон идэвхтэй жишээнүүдийг хайж,
 * зургийн моделд style reference болгон өгөх (гэрэл, өнгө, найруулга — текст/лого биш).
 */
class ReferenceLibrary
{
    /**
     * Идэвхтэй жишээнүүдийг ангиллаар шүүж, хэв маяг таарсныг нь эхэнд, дараа нь шинэ нь эхэндээ байхаар буцаана.
     *
     * @return list<array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}>
     */
    public function search(?string $category = null, ?string $style = null, int $limit = 6): array
    {
        $style = mb_strtolower(trim((string) $style));
        $query = StyleReference::query()->where('enabled', true);

        if ($category) {
            $query->where('category', $category);
        }
        if ($style !== '') {
            // Яг таарсан → хэсэгчлэн таарсан → бусад (том/жижиг үсэг ялгахгүй).
            $query->orderByRaw(
                'CASE WHEN LOWER(style) = ? THEN 0 WHEN LOWER(style) LIKE ? THEN 1 ELSE 2 END',
                [$style, '%'.$style.'%'],
            );
        }

        return $query->latest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (StyleReference $r) => $this->present($r))
            ->all();
    }

    /**
     * 'ref_<id>' танигчаар нэг идэвхтэй жишээг буцаана.
     *
     * @return array{id: string, title: string, category: string, style: ?string, notes: ?string, path: string}|null
     */
    public function get(string $id): ?array
    {
        if (! preg_match('/^ref_(\d+)$/', $id, $m)) {
            return null;
        }
        $ref = StyleReference::where('enabled', true)->find((int) $m[1]);

        return $ref ? $this->present($ref) : null;
    }

    // Агентад өгөх хэлбэр (path нь public диск дээрх зам).
    private function present(StyleReference $r): array
    {
        return [
            'id' => 'ref_'.$r->id,
            'title' => $r->title,
            'category' => $r->category,
            'style' => $r->style,
            'notes' => $r->notes,
            'path' => $r->path,
        ];
    }
}
