<?php

/*
 * Render back-office screens as standalone HTML so they can be screenshotted
 * at the reference designs' exact viewport (1536x1024) and diffed against
 * `frontend images/*.png`.
 *
 * Same reason as the guardian-portal capture.php next door: headless Chrome
 * driven from the CLI cannot sign in, so the page is rendered HERE through
 * the real kernel with a real authenticated principal and written into
 * public/ where the browser can load it same-origin (the markup references
 * /build/app.css by absolute path; a file:// page would resolve nothing).
 *
 * It boots against .env.demo - the Heritage College demo database - because
 * that is the data the reference mockups were drawn from. Loading the env
 * BEFORE the kernel is the same trick demo-server-router.php uses.
 *
 * public/__compare/ is throwaway and holds fully-rendered AUTHENTICATED
 * pages. Delete it when the comparison is done; never deploy it.
 */

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root, '.env.demo')->load();

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $root.'/public/__compare';

if (! is_dir($out)) {
    mkdir($out, 0755, true);
}

/* The reference is the SUPER ADMIN dashboard, so the principal has to be one:
   rendering it as anyone else silently drops nav sections and KPI cards and
   the diff would then be measuring permissions, not design. */
$user = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
    ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();

if ($user === null) {
    fwrite(STDERR, "No Super Admin or Admin user in the demo database.\n");
    exit(1);
}

auth()->login($user);

/* The base URL is spelled out because @vite() builds ABSOLUTE asset URLs from
   the request host. Request::create('/dashboard') defaults to "localhost" with
   no port, so every stylesheet 404s and the screenshot comes back as an
   unstyled column of markup that looks like a layout bug rather than a missing
   asset. */
$base = rtrim((string) env('APP_URL', 'http://localhost:8940'), '/');

/*
 * Every screen in the parity programme. A page that 500s or 403s still gets
 * written out - the file then CONTAINS the error, which is the point: a
 * silently-missing capture reads as "not started yet" and this is meant to
 * be the thing that tells you otherwise.
 */
$map = [
    'dashboard' => '/dashboard',
    'students' => '/students',
    'classes' => '/classes',
    'subjects' => '/subjects',
    'academics-settings' => '/academics/settings',
    'timetable' => '/timetable',
    'attendance' => '/attendance',
    'examinations' => '/examinations',
    'results' => '/results',
    'finance-dashboard' => '/finance/dashboard',
    'library' => '/library',
    'inventory' => '/inventory',
    'transport' => '/transport',
    'hostel' => '/hostel',
    'guardians' => '/guardians',
    'staff' => '/staff',
    'reports' => '/reports',
    'settings' => '/settings',
    'settings-advanced' => '/settings/advanced',
    'users' => '/users',

    // NOT converted this session - captured to prove the platform styling
    // reaches screens nobody edited.
    'payroll' => '/payroll',
    'messages' => '/messages',
    'alumni' => '/alumni',
    'admissions' => '/admissions',
    'audit-log' => '/audit-log',
    'medical' => '/medical',
    'procurement' => '/procurement/suppliers',
    'ledger' => '/ledger/chart-of-accounts',
    'operations-backups' => '/operations/backups',
];

foreach ($map as $name => $uri) {
    $request = Request::create($base.$uri, 'GET');
    $request->setLaravelSession($app['session']->driver());

    try {
        $response = $kernel->handle($request);
        $html = $response->getContent();
        $status = $response->getStatusCode();
    } catch (Throwable $e) {
        $html = '<pre>'.htmlspecialchars($e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()).'</pre>';
        $status = 500;
    }

    file_put_contents($out."/{$name}.html", $html);
    printf("%-14s %s  %d  %d bytes\n", $name, $uri, $status, strlen((string) $html));
}

printf("\nSigned in as %s (%s)\n", $user->name ?? $user->email, $user->getRoleNames()->implode(', '));
printf("Wrote %s\n", $out);
