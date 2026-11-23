---
view: components.packages.quick-look
title: A quick look
subtitle: One route per URI, a controller per version, and a v2-only endpoint.
file: routes/api.php
language: php
code: |
  use App\Http\Controllers\ReportController;
  use App\Http\Controllers\UserController;
  use App\Http\Controllers\UserV1Controller;
  use App\Http\Controllers\UserV2Controller;

  Route::get('users/{user}', [UserController::class, 'show'])->controllers([
      'v1' => [UserV1Controller::class, 'show'],
      'v2' => [UserV2Controller::class, 'show'],
  ]);

  Route::sinceVersion('v2')->middleware('auth:api')->group(function () {
      Route::apiResource('reports', ReportController::class);
  });

  // GET /api/users/1   X-API-Version: v2   → UserV2Controller@show
---
