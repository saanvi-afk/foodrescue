<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';
ini_set('display_errors','0');
use FoodRescue\Core\{Database,HttpException,Session,View};
use FoodRescue\Controllers\{AuthController,DashboardController};
use FoodRescue\Middleware\Authorize;
use FoodRescue\Models\UserRepository;
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.tile.openstreetmap.org; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
Session::start();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isApi = str_starts_with($path, '/api/');
try {
    $user = isset($_SESSION['user_id']) ? (new UserRepository(Database::connection()))->find((int) $_SESSION['user_id']) : null;
    if (isset($_SESSION['user_id']) && !$user) { Session::logout(); }
    if ($user && (int) $user['auth_version'] !== (int) ($_SESSION['auth_version'] ?? 0)) { Session::logout(); $user = null; }
    if ($isApi && !str_starts_with($path, '/api/auth/')) {
        header('Content-Type: application/json; charset=utf-8');
        $user=Authorize::check($user);
        $method=$_SERVER['REQUEST_METHOD'];
        if ($method!=='GET') {
            Session::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>6*1024*1024) { throw new HttpException(413,'Request too large.'); }
            if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) { $data=$_POST; }
            else {
                try { $data=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR); }
                catch (JsonException) { throw new HttpException(400,'Invalid JSON request.'); }
                if (!is_array($data)) { throw new HttpException(400,'Expected a JSON object.'); }
            }
        } else { $data=[]; }
        $result=(new \FoodRescue\Controllers\OperationsController())->dispatch($path,$method,$user,$data);
        echo json_encode(['ok'=>true,'data'=>$result,'csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR);
    } elseif ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        $action = substr($path, strlen('/api/auth/'));
        $routes = require BASE_PATH . '/routes/api.php';
        if (!str_starts_with($path, '/api/auth/') || !array_key_exists($action,$routes)) { throw new HttpException(404,'Endpoint not found.'); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); throw new HttpException(405,'Use POST for this endpoint.'); }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { throw new HttpException(413,'Request too large.'); }
        Session::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if ($routes[$action]) { Authorize::check($user); }
        try { $data = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new HttpException(400, 'Invalid JSON request.'); }
        if (!is_array($data)) { throw new HttpException(400,'Expected a JSON object.'); }
        foreach ($data as $value) { if (!is_string($value)) { throw new HttpException(422,'Fields must contain text values.'); } }
        $result = (new AuthController())->handle($action,$data);
        http_response_code($action === 'register' ? 201 : 200);
        echo json_encode(['ok'=>true,'data'=>$result,'csrf'=>$_SESSION['csrf']],JSON_THROW_ON_ERROR);
    } else {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); throw new HttpException(405,'Use GET for this page.'); }
        $routes = require BASE_PATH . '/routes/web.php';
        if (preg_match('#^/donations/(\d+)(/edit)?$#',$path,$m)) { $routes[$path]=['workspace',isset($m[2]) ? 'donation-form' : 'donation'];$resourceId=(int)$m[1]; }
        if (preg_match('#^/experiments/(\d+)$#',$path,$m)) { $routes[$path]=['workspace','experiment'];$resourceId=(int)$m[1]; }
        if (preg_match('#^/exports/(impact|experiment)/(\d+)\.(csv|pdf|json)$#',$path,$m)) {
            (new \FoodRescue\Controllers\PageController())->export(Authorize::check($user),$m[1],$m[3],(int)$m[2]);exit;
        }
        if (preg_match('#^/files/(donation|document|profile)/(\d+)$#',$path,$m)) {
            $file=(new \FoodRescue\Services\UploadService(Database::connection()))->file(Authorize::check($user),$m[1],(int)$m[2]);
            header('Content-Type: ' . $file['mime_type']);header('Content-Disposition: ' . ($m[1]==='document' ? 'attachment' : 'inline') . '; filename="attachment"');
            readfile(BASE_PATH . '/storage/private/' . $file['storage_path']);exit;
        }
        if (!isset($routes[$path])) { throw new HttpException(404,'Page not found.'); }
        [$kind,$argument] = array_pad($routes[$path],2,null);
        if ($kind === 'landing') { View::render('landing',['title'=>'Good food. A better destination.','user'=>$user]); }
        elseif ($kind === 'auth') { (new AuthController())->page($argument); }
        elseif ($kind === 'workspace') { (new \FoodRescue\Controllers\PageController())->show($argument,Authorize::check($user),$resourceId ?? 0); }
        else { (new DashboardController())->show(Authorize::check($user,$argument)); }
    }
} catch (Throwable $error) {
    $conflict=$error instanceof PDOException && $error->getCode()==='23000';
    $status = $error instanceof HttpException ? $error->status : ($conflict ? 409 : 503);
    $message = $error instanceof HttpException ? $error->getMessage() : ($conflict ? 'This change conflicts with an existing record or database constraint. Refresh and review the values.' : 'The service is temporarily unavailable. Please try again shortly.');
    if (!$error instanceof HttpException) { error_log((string) $error); }
    http_response_code($status);
    if ($isApi) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>['status'=>$status,'message'=>$message]]); }
    elseif ($status === 401) { header('Location: /login',true,302); }
    else { View::render('error',['title'=>'Unable to continue','status'=>$status,'message'=>$message]); }
}
