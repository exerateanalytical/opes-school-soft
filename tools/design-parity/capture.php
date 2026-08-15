<?php

/*
 * Capture every guardian-portal screen as a standalone HTML file so it can be
 * screenshotted at the reference designs' exact device size and diffed
 * against them.
 *
 * WHY NOT JUST POINT A HEADLESS BROWSER AT THE ROUTES.
 * The portal is behind auth, and headless Chrome driven from the CLI cannot
 * log in - it has no way to click the demo button or carry a session cookie.
 * So the pages are rendered HERE, through the real kernel with a real
 * authenticated principal, and written into public/ where the browser can
 * load them same-origin. Same origin matters: the markup references
 * /build/app.css and the Livewire bundle by absolute path, and a file:// page
 * would resolve neither.
 *
 * Everything lands in public/__compare/, which is throwaway - delete the
 * directory when the comparison is done. It is NOT something to leave in a
 * deployment: it is a directory of fully-rendered authenticated pages.
 */

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Support\Portal\ChildFeeStatement;
use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = __DIR__.'/../../public/__compare';

if (! is_dir($out)) {
    mkdir($out, 0755, true);
}

$guardian = Guardian::query()->whereNotNull('portal_user_id')->orderBy('id')->first();
$user = User::query()->find($guardian->portal_user_id);
$studentId = (int) DB::table('student_guardians')->where('guardian_id', $guardian->getKey())->value('student_id');
$enrollmentId = app(ChildFeeStatement::class)->latestEnrollmentId($studentId);
$threadId = (int) (DB::table('message_thread_participants')->where('user_id', $user->getKey())->value('message_thread_id') ?? 0);

/*
 * reference png => portal uri
 *
 * Only screens with a real one-to-one counterpart. A reference with no route
 * is listed in `$unmapped` below rather than quietly dropped, because "we
 * never built this screen" and "this screen does not match" are different
 * findings and must not be blurred together.
 */
$map = [
    'parent-dashboard.png' => '/portal',
    'my-children.png' => '/portal/children',
    'child-profile.png' => "/portal/children/{$studentId}/profile",
    'child-overview.png' => "/portal/children/{$studentId}/profile",
    'academic-overview.png' => "/portal/children/{$studentId}/results",
    'assignments.png' => "/portal/children/{$studentId}/assignments",
    'attendance.png' => "/portal/children/{$studentId}/attendance",
    'behaviour-discipline.png' => "/portal/children/{$studentId}/discipline",
    'fees-dashboard.png' => "/portal/children/{$studentId}/fees",
    'fee-structure-breakdown.png' => "/portal/children/{$studentId}/fee-detail/structure",
    'outstanding-balance.png' => "/portal/children/{$studentId}/fee-detail/outstanding",
    'make-payment.png' => "/portal/children/{$studentId}/fee-detail/pay",
    'payment-history-receipts.png' => '/portal/payments',
    'health-overview.png' => "/portal/children/{$studentId}/health",
    'medical-history.png' => "/portal/children/{$studentId}/health-detail/history",
    'immunization-vaccination-records.png' => "/portal/children/{$studentId}/health-detail/immunisations",
    'medical-documents.png' => "/portal/children/{$studentId}/health-detail/documents",
    'health-id.png' => "/portal/children/{$studentId}/health-detail/card",
    'digital-school-id-child-id.png' => "/portal/children/{$studentId}/id-card",
    'child-documents.png' => "/portal/children/{$studentId}/documents",
    'messages-inbox.png' => '/portal/messages',
    'notifications.png' => '/portal/notifications',
    'global-search.png' => '/portal/search',
    'parent-profile.png' => '/portal/account',
    'account-settings.png' => '/portal/account/settings',
    'notification-preferences.png' => '/portal/account/notifications',
    'help-support.png' => '/portal/help',
    'excursions-trips.png' => '/portal/school-life/excursions',
    'emergency-important-contacts.png' => "/portal/children/{$studentId}/contacts",
];

if ($threadId > 0) {
    $map['message-chat-class-teacher.png'] = "/portal/messages/{$threadId}";
}

$index = [];
$failed = [];

foreach ($map as $png => $uri) {
    $app->forgetInstance(PortalContext::class);
    $app['auth']->forgetGuards();
    auth()->login($user);

    try {
        $response = $kernel->handle(Request::create($uri, 'GET'));
        $status = $response->getStatusCode();

        if ($status !== 200) {
            $failed[$png] = $status.'  '.$uri;

            continue;
        }

        /*
         * Rewrite absolute asset URLs to same-origin.
         *
         * `asset()` builds them from APP_URL (http://localhost), but this
         * capture is served from the dev server on another port - so the
         * stylesheet, the Livewire bundle and every image 404 and the page
         * renders as raw unstyled HTML. Which is exactly what the first run
         * produced, and it looks like a catastrophic layout failure rather
         * than a missing file.
         */
        $html = str_replace(
            ['http://localhost/', 'https://localhost/'],
            '/',
            (string) $response->getContent()
        );
    } catch (Throwable $e) {
        $failed[$png] = 'EX '.substr($e->getMessage(), 0, 80);

        continue;
    }

    $slug = pathinfo($png, PATHINFO_FILENAME);
    file_put_contents($out.'/'.$slug.'.html', $html);

    $index[] = ['png' => $png, 'slug' => $slug, 'uri' => $uri];
}

file_put_contents($out.'/index.json', json_encode($index, JSON_PRETTY_PRINT));

printf("captured %d screens into public/__compare\n", count($index));

foreach ($failed as $png => $why) {
    echo "  FAILED {$png} => {$why}\n";
}

/*
 * References with no counterpart route. Reported, never silently skipped.
 */
$unmapped = [];

foreach (glob(__DIR__.'/mobile/*.png') ?: [] as $file) {
    $name = basename($file);

    if (! isset($map[$name]) && ! preg_match('/-\d\.png$/', $name)) {
        $unmapped[] = $name;
    }
}

echo "\n".count($unmapped)." reference screens have no mapped route:\n";

foreach (array_slice($unmapped, 0, 40) as $name) {
    echo "  {$name}\n";
}
