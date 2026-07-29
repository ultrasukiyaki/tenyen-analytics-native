<?php

declare(strict_types=1);

use Tenyen\Analytics\AdminMetadata;

$root = dirname(__DIR__, 3);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit;
}
$config = require $configFile;
require_once $root . '/app/admin-auth.php';
tyaa_require_auth(is_array($config) ? $config : [], true);

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
if (!tyaa_verify_csrf($csrf)) tyaa_json(['ok'=>false,'error'=>'csrf_failed','message'=>'The form has expired.'],403);

$services = require $root . '/app/bootstrap.php';
$username = (string)($config['admin']['username'] ?? 'administrator');
$metadata = new AdminMetadata($services['pdo'], 'admin:' . hash('sha256', $username));
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input = $_POST;
if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($decoded)) tyaa_json(['ok'=>false,'error'=>'invalid_json','message'=>'Invalid JSON request.'],400);
    $input = $decoded;
}
$action = (string)($_GET['action'] ?? $input['action'] ?? '');

try {
    if ($method === 'GET' && $action === 'annotations') {
        tyaa_json(['ok'=>true]+$metadata->listAnnotations($_GET));
    }
    if ($method === 'GET' && $action === 'annotation') {
        tyaa_json(['ok'=>true,'annotation'=>$metadata->annotation((string)($_GET['entity_type']??''),(string)($_GET['entity_key']??''))]);
    }
    if ($method === 'GET' && $action === 'tags') {
        tyaa_json(['ok'=>true,'items'=>$metadata->listTags(trim((string)($_GET['q']??'')))]);
    }
    if ($method === 'GET' && $action === 'views') {
        $report=trim((string)($_GET['report']??''));
        tyaa_json(['ok'=>true,'items'=>$metadata->listViews($report!==''?$report:null)]);
    }
    if ($method !== 'POST') tyaa_json(['ok'=>false,'error'=>'method_not_allowed','message'=>'Method not allowed.'],405);

    if ($action === 'save_annotation') {
        $tags=$input['tag_ids']??[];
        if(!is_array($tags))$tags=[];
        tyaa_json(['ok'=>true,'annotation'=>$metadata->saveAnnotation(
            (string)($input['entity_type']??''),(string)($input['entity_key']??''),
            (string)($input['alias']??''),(string)($input['note']??''),
            filter_var($input['watched']??false,FILTER_VALIDATE_BOOLEAN),$tags
        )]);
    }
    if ($action === 'toggle_watch') {
        tyaa_json(['ok'=>true,'annotation'=>$metadata->toggleWatch((string)($input['entity_key']??''),filter_var($input['watched']??false,FILTER_VALIDATE_BOOLEAN))]);
    }
    if ($action === 'save_tag') {
        $id=isset($input['tag_id'])?(int)$input['tag_id']:null;
        tyaa_json(['ok'=>true,'tag'=>$metadata->saveTag($id?:null,(string)($input['name']??''),(string)($input['color']??'slate'))]);
    }
    if ($action === 'delete_tag') {
        $metadata->deleteTag((int)($input['tag_id']??0));tyaa_json(['ok'=>true]);
    }
    if ($action === 'save_view') {
        $state=$input['state']??[];
        if(!is_array($state))throw new InvalidArgumentException('Invalid saved-view state.');
        $id=isset($input['saved_view_id'])?(int)$input['saved_view_id']:null;
        tyaa_json(['ok'=>true,'view'=>$metadata->saveView(
            $id?:null,(string)($input['report']??''),(string)($input['name']??''),(string)($input['description']??''),
            $state,filter_var($input['pinned']??false,FILTER_VALIDATE_BOOLEAN),filter_var($input['is_default']??false,FILTER_VALIDATE_BOOLEAN)
        )]);
    }
    if ($action === 'delete_view') {
        $metadata->deleteView((int)($input['saved_view_id']??0));tyaa_json(['ok'=>true]);
    }
    tyaa_json(['ok'=>false,'error'=>'unknown_action','message'=>'Unknown metadata action.'],404);
} catch (InvalidArgumentException $exception) {
    tyaa_json(['ok'=>false,'error'=>'validation_failed','message'=>$exception->getMessage()],422);
} catch (Throwable $exception) {
    error_log('[Tenyen Analytics metadata] '.$exception->getMessage());
    tyaa_json(['ok'=>false,'error'=>'server_error','message'=>'The metadata operation failed.'],500);
}
