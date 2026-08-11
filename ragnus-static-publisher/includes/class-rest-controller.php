<?php

declare(strict_types=1);

namespace Ragnus\StaticPublisher;

use WP_REST_Request;
use WP_REST_Response;

final class File_Response extends WP_REST_Response
{
    public string $file;

    public function __construct(string $file)
    {
        parent::__construct(null, 200);
        $this->file = $file;
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
    }

    public static function can_export(): bool
    {
        return current_user_can('manage_options');
    }

    public static function create(WP_REST_Request $request): WP_REST_Response
    {
        if (get_transient(Plugin::LOCK_KEY)) {
            return new WP_REST_Response(['message' => 'Export işlemi zaten çalışıyor.', 'status' => Plugin::status()], 409);
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
        $status = Plugin::status();
        $archive = $status['archive'] ?? '';
        if (($status['state'] ?? '') !== 'completed' || ! is_string($archive) || ! is_readable($archive)) {
            return new WP_REST_Response(['message' => 'İndirilebilir export bulunamadı.'], 404);
        }

        return new File_Response($archive);
    }

    public static function serve_file(bool $served, $result): bool
    {
        if (! $result instanceof File_Response) {
            return $served;
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="ragnus-static-export.zip"');
        header('Content-Length: ' . (string) filesize($result->file));
        readfile($result->file);
        return true;
    }
}
