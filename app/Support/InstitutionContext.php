<?php
namespace App\Support;

use App\Models\Institucion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InstitutionContext
{
    private const ALIASES = [
        'ccitp' => ['CCITP'],
        'divertimundo' => ['Divertimundo', 'Diveretimundo'],
        'pitagoras' => ['Pitágoras', 'Pitagoras'],
    ];

    public static function slugs(): array
    {
        return array_keys(self::ALIASES);
    }

    public static function fromRequest(Request $request): ?string
    {
        $raw = $request->input('institucion');
        if ($raw === null || $raw === '') {
            $raw = $request->header('X-Institucion');
        }
        if ($raw === null || $raw === '') {
            return null;
        }

        $slug = self::normalizeSlug((string) $raw);
        if (!$slug) {
            throw ValidationException::withMessages([
                'institucion' => ['Institución no válida. Valores permitidos: ccitp, divertimundo, pitagoras.'],
            ]);
        }
        return $slug;
    }

    public static function normalizeSlug(?string $slug): ?string
    {
        if (!$slug) return null;
        $value = strtolower(trim(Str::ascii($slug)));
        return in_array($value, self::slugs(), true) ? $value : null;
    }

    public static function aliases(string $slug): array
    {
        return self::ALIASES[$slug] ?? [];
    }

    public static function matchesName(?string $institutionName, ?string $slug): bool
    {
        if (!$slug) return true;
        if (!$institutionName) return false;

        $name = strtolower(Str::ascii($institutionName));
        foreach (self::aliases($slug) as $alias) {
            if (str_contains($name, strtolower(Str::ascii($alias)))) {
                return true;
            }
        }
        return false;
    }

    public static function slugForName(?string $institutionName): ?string
    {
        if (!$institutionName) return null;

        foreach (self::slugs() as $slug) {
            if (self::matchesName($institutionName, $slug)) {
                return $slug;
            }
        }

        return null;
    }

    public static function applyRelation($query, string $relation, ?string $slug): void
    {
        if (!$slug) return;
        $aliases = self::aliases($slug);

        $query->whereHas($relation, function ($institutionQuery) use ($aliases) {
            $institutionQuery->where(function ($nameQuery) use ($aliases) {
                foreach ($aliases as $index => $alias) {
                    if ($index === 0) {
                        $nameQuery->where('nombre', 'like', '%'.$alias.'%');
                    } else {
                        $nameQuery->orWhere('nombre', 'like', '%'.$alias.'%');
                    }
                }
            });
        });
    }

    public static function institutionIds(?string $slug): array
    {
        if (!$slug) return [];

        $aliases = self::aliases($slug);
        return Institucion::query()
            ->where(function ($query) use ($aliases) {
                foreach ($aliases as $index => $alias) {
                    if ($index === 0) {
                        $query->where('nombre', 'like', '%'.$alias.'%');
                    } else {
                        $query->orWhere('nombre', 'like', '%'.$alias.'%');
                    }
                }
            })
            ->pluck('institucion_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function assertInstitutionId(?int $institutionId, ?string $slug): void
    {
        if (!$slug || !$institutionId) return;
        if (!in_array($institutionId, self::institutionIds($slug), true)) {
            abort(403, 'El registro no pertenece a la institución seleccionada.');
        }
    }
}
