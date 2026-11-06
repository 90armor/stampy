<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Policies\PoliciesPolicy;
use App\Policies\ReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6i guard: no page shows a link its viewer can't open (CLAUDE.md,
 * "A link, card or row shown to someone who cannot open its target is a
 * bug"). For each role, on the demo seed, it walks every page reachable
 * from the dashboard, collects every internal link, and requests each one
 * as that user: every link must answer 200 or redirect somewhere other than
 * an error, never 403 or 404.
 *
 * Each kind of page (route and tab) is read for links once — the twentieth
 * employee's profile has the same links as the first, to other records — but
 * every link found is requested, since whether it opens depends on the record.
 */
class InternalLinkAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Links that aren't pages to open with GET. */
    private const SKIP = ['/logout'];

    public function test_every_internal_link_each_role_sees_opens_for_them(): void
    {
        $this->seed();

        $unlinkedAdmin = User::factory()->create(['username' => 'unlinked-admin'])->assignRole('admin');

        $users = [
            'admin' => User::where('email', 'admin@example.com')->firstOrFail(),
            'manager' => User::where('email', 'aye.aye.mon@example.com')->firstOrFail(),
            'employee' => User::where('email', 'kyaw.kyaw.naing@example.com')->firstOrFail(),
            'unlinked admin' => $unlinkedAdmin,
        ];

        foreach ($users as $role => $user) {
            $broken = $this->crawl($user);

            $this->assertSame([], $broken, "Links {$role} ({$user->username}) is shown but can't open:\n".implode("\n", $broken));
        }
    }

    /**
     * @return list<string> each broken link, with the page it was on
     */
    private function crawl(User $user): array
    {
        $this->actingAs($user);

        // The dashboard, and every section tab the user may see: tabs are
        // buttons, not links, so a crawl from links alone never opens one, and a
        // report renders only on its open tab.
        $queue = [route('dashboard')];
        foreach (app(ReportPolicy::class)->tabs($user) as $tab => $label) {
            $queue[] = route('reports.index', ['tab' => $tab]);
        }
        foreach (app(PoliciesPolicy::class)->tabs($user) as $tab => $label) {
            $queue[] = route('policies.index', ['tab' => $tab]);
        }
        if ($user->can('viewAny', Department::class)) {
            $queue[] = route('organization.index', ['tab' => 'departments']);
            $queue[] = route('organization.index', ['tab' => 'positions']);
        }
        $checked = array_fill_keys($queue, 'start');
        $read = [];
        $broken = [];

        while ($queue !== []) {
            $url = array_shift($queue);
            $response = $this->get($url);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $target = $response->headers->get('Location');

                if ($this->isErrorRedirect($target)) {
                    $broken[] = "{$url} → redirects to {$target} (on {$checked[$url]})";
                }

                continue;
            }

            if ($status !== 200) {
                $broken[] = "{$url} → {$status} (on {$checked[$url]})";

                continue;
            }

            $kind = $this->kind($url);
            if (isset($read[$kind]) || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }
            $read[$kind] = true;

            foreach ($this->links($response->getContent()) as $link) {
                if (! isset($checked[$link])) {
                    $checked[$link] = $url;
                    $queue[] = $link;
                }
            }
        }

        // Every role reaches at least the dashboard, profile, Time off and Overtime.
        $this->assertGreaterThanOrEqual(4, count($read), 'The crawl read too few pages to mean anything.');
        if (getenv('LINK_CRAWL_DEBUG')) {
            fwrite(STDERR, $user->username.': read '.implode(', ', array_keys($read)).'; requested '.count($checked)."\n");
        }

        return $broken;
    }

    /** @return list<string> */
    private function links(string $html): array
    {
        preg_match_all('/\shref="([^"]*)"/', $html, $matches);

        return collect($matches[1])
            ->map(fn (string $href) => html_entity_decode($href, ENT_QUOTES | ENT_HTML5))
            ->map(fn (string $href) => Str::before($href, '#'))
            ->filter(fn (string $href) => $href !== '' && (str_starts_with($href, config('app.url')) || str_starts_with($href, '/')) && ! str_starts_with($href, '//'))
            ->map(fn (string $href) => str_starts_with($href, '/') ? rtrim(config('app.url'), '/').$href : $href)
            ->reject(fn (string $href) => $this->isAsset($href) || in_array(parse_url($href, PHP_URL_PATH), self::SKIP, true))
            ->unique()
            ->values()
            ->all();
    }

    /** A route name plus its tab: pages of one kind carry the same links. */
    private function kind(string $url): string
    {
        $route = Route::getRoutes()->match(request()->create($url));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $route->getName().'|'.(is_string($query['tab'] ?? null) ? $query['tab'] : '');
    }

    private function isAsset(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with($path, '/build/') || str_starts_with($path, '/livewire/') || preg_match('/\.(css|js|png|svg|ico|woff2?)$/', $path) === 1;
    }

    private function isErrorRedirect(?string $target): bool
    {
        $path = (string) parse_url((string) $target, PHP_URL_PATH);

        return $target === null || $path === '/login' || str_starts_with($path, '/password/change');
    }
}
