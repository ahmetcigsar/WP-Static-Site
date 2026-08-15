<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use WP_REST_Request;
use WP_REST_Response;

final class File_Response extends WP_REST_Response
{
    public string $file;
    public string $filename;

    public function __construct(string $file, string $filename = 'ragnus-static-export.zip')
    {
        parent::__construct(null, 200);
        $this->file = $file;
        $this->filename = $filename;
    }
}

final class REST_Controller
{
    public static function register(): void
    {
        register_rest_route('ragnus-static/v1', '/exports', [
            'methods' => 'POST',
            'callback' => [self::class, 'create'],
            'permission_callback' => [self::class, 'can_export'],
        ]);
        register_rest_route('ragnus-static/v1', '/exports/latest', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => [self::class, 'can_export'],
        ]);
        register_rest_route('ragnus-static/v1', '/exports/latest/artifact', [
            'methods' => 'GET',
            'callback' => [self::class, 'artifact'],
            'permission_callback' => [self::class, 'can_export'],
        ]);
        register_rest_route('ragnus-static/v1', '/exports/(?P<job_id>[A-Za-z0-9-]+)/artifact', [
            'methods' => 'GET',
            'callback' => [self::class, 'job_artifact'],
            'permission_callback' => [self::class, 'can_export'],
        ]);
        register_rest_route('ragnus-static/v1', '/deployments/callback', [
            'methods' => 'POST',
            'callback' => [self::class, 'deployment_callback'],
            'permission_callback' => [self::class, 'can_export'],
        ]);
    }

    public static function can_export(): bool
    {
        return current_user_can(Plugin::EXPORT_CAPABILITY);
    }

    public static function create(WP_REST_Request $request): WP_REST_Response
    {
        if (get_transient(Plugin::LOCK_KEY)) {
            return new WP_REST_Response(['message' => __('The export process is already running.', 'ragnus-static-publisher'), 'status' => Plugin::status()], 409);
        }

        $job_id = Plugin::schedule_export('ci-manual');
        return new WP_REST_Response(['job_id' => $job_id, 'status' => 'queued'], 202);
    }

    public static function status(): WP_REST_Response
    {
        return new WP_REST_Response(Plugin::public_status());
    }

    public static function artifact(): WP_REST_Response
    {
        $archive = Archive_Manager::latest();
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            return new WP_REST_Response(['message' => __('No downloadable exports found.', 'ragnus-static-publisher')], 404);
        }

        return new File_Response((string) $archive['path']);
    }

    public static function job_artifact(WP_REST_Request $request): WP_REST_Response
    {
        $job_id = sanitize_file_name((string) $request['job_id']);
        $archive = $job_id === '' ? null : Archive_Manager::find($job_id);
        if ($archive === null || ! is_readable((string) $archive['path'])) {
            return new WP_REST_Response(['message' => __('The requested export archive was not found.', 'ragnus-static-publisher')], 404);
        }

        return new File_Response((string) $archive['path'], $job_id . '.zip');
    }

    public static function deployment_callback(WP_REST_Request $request): WP_REST_Response
    {
        $job_id = sanitize_file_name((string) $request->get_param('job_id'));
        $state = sanitize_key((string) $request->get_param('state'));
        $build_sha256 = strtolower(sanitize_text_field((string) $request->get_param('build_sha256')));
        $archive = $job_id === '' ? null : Archive_Manager::find($job_id);

        if ($archive === null) {
            return new WP_REST_Response(['message' => __('The requested export archive was not found.', 'ragnus-static-publisher')], 404);
        }
        if (! in_array($state, ['deploying', 'completed', 'failed'], true)) {
            return new WP_REST_Response(['message' => __('Invalid deployment status.', 'ragnus-static-publisher')], 400);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $build_sha256) !== 1
            || ! hash_equals((string) $archive['build_sha256'], $build_sha256)) {
            return new WP_REST_Response(['message' => __('The deployment build checksum does not match the export.', 'ragnus-static-publisher')], 409);
        }

        $deployment_url = esc_url_raw((string) $request->get_param('deployment_url'));
        $error = sanitize_text_field((string) $request->get_param('error'));
        Plugin::record_deployment_status($job_id, $state, [
            'build_sha256' => $build_sha256,
            'deployment_url' => $deployment_url,
            'error' => $state === 'failed' ? $error : '',
        ]);

        return new WP_REST_Response(['job_id' => $job_id, 'state' => $state], 200);
    }

    public static function serve_file(bool $served, $result): bool
    {
        if (! $result instanceof File_Response) {
            return $served;
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($result->filename) . '"');
        header('Content-Length: ' . (string) filesize($result->file));
        readfile($result->file);
        return true;
    }
}
